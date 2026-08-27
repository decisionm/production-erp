<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionRequest;
use Illuminate\Support\Facades\DB;

/**
 * WHAT THE FLOOR IS OWED, AND WHO IS WAITING FOR IT — the read behind the
 * Production Queue screen.
 *
 * IT COMPUTES NOTHING OF ITS OWN. Every figure on this payload is lifted
 * whole from the service that already owns it:
 *
 *   priority · status · `can`   ProductionRequestService::queue() — the same
 *                               state machine the actions refuse on, so the
 *                               button and its 422 cannot disagree.
 *   ETA · free · queued_ahead   FulfilmentPlanningService::plan() — the walk
 *                               through the real shift boundaries, and its
 *                               cannot_estimate CASCADE (S12).
 *   ordered · expected date     the sales order line and its order, read
 *                               through the request's OWN relation — the same
 *                               `salesOrderLine.salesOrder` path plan() already
 *                               eager-loads for the customer name.
 *
 * No date is stored (S11) and none is invented: a request the planning walk
 * could not date arrives here dateless with its reason, and leaves dateless
 * with its reason.
 *
 * IT SHAPES NOTHING AND GATES NOTHING EITHER. This class returns the request
 * MODEL paired with its join figures; ProductionQueueResource turns that into
 * wire keys and decides which of them the caller may read. That split is the
 * point: the request's key names stay ProductionRequestResource's, and the
 * permission rule lives at the HTTP layer where the caller is.
 *
 * NO MACHINE AND NO SHIFT ARE ON THIS PAYLOAD, deliberately. `production_
 * requests` carries no machine and no shift column: a machine is chosen when
 * somebody starts a BATCH, and this document creates none (invariant 2).
 * There is no evidenced source for "which machine will run this", so the
 * screen prints an em-dash rather than a plausible guess.
 *
 * READ-ONLY end to end. Nothing here writes, locks, moves stock, touches a
 * batch or queues a voucher.
 *
 * FC-06: no rate, no cost, no vendor. The sales order line carries a
 * unit_price and it is deliberately not read — this is the floor's screen.
 */
class ProductionQueueService
{
    public function __construct(
        private readonly ProductionRequestService $requests,
        private readonly FulfilmentPlanningService $planning,
    ) {}

    /**
     * The whole screen in one read: every open request with its demand and
     * its date, plus the basis those dates stand on.
     *
     * @return array{data: list<array<string, mixed>>, basis: array<string, mixed>}
     */
    public function queue(): array
    {
        /*
         * ONE SNAPSHOT, NOT TWO READS. The dates and the rows come from two
         * separate reads of the same open-request set — plan() walks the queue
         * to work out what is ahead of what, then queue() reads the rows. A
         * reorder, start or cancellation committing BETWEEN them pairs the old
         * queue's estimates with the new queue's rows, and the screen quotes a
         * queued_ahead and a readiness date for an order it is no longer
         * showing (Codex, eb1cfb8). Wrapping both in one transaction is what
         * makes the database hand them the same view: REPEATABLE READ opens
         * the consistent snapshot at the first read on the live MySQL, and a
         * transaction is a consistent read on SQLite too.
         *
         * Still a READ. Nothing inside writes, locks or updates — the
         * transaction exists only to fix what both reads see.
         */
        return DB::transaction(function (): array {
            $plan = $this->planning->plan();

            // Keyed by sales order line — safe as a 1:1 lookup because
            // createFromShortfall enforces ONE OPEN REQUEST PER LINE under the
            // line's own lock, and both this queue and plan() read `open()`.
            $estimates = [];
            foreach ($plan['data'] as $row) {
                $estimates[(int) $row['line_id']] = $row;
            }

            $rows = [];

            foreach ($this->requests->queue() as $request) {
                $rows[] = $this->row($request, $estimates[(int) $request->sales_order_line_id] ?? null);
            }

            return ['data' => $rows, 'basis' => $plan['basis']];
        });
    }

    // ---- internals --------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $estimate  this request's planning row
     * @return array{request: ProductionRequest, ordered: ?string, delivered: ?string, expected_date: ?string, planning: array<string, mixed>}
     */
    private function row(ProductionRequest $request, ?array $estimate): array
    {
        $line = $request->salesOrderLine;
        $order = $line?->salesOrder;

        return [
            // The document itself, unserialized — the resource names its keys.
            'request' => $request,

            // WHAT THE CUSTOMER ORDERED, and what has already gone out — the
            // denominator the request quantity is a part of.
            'ordered' => $line === null ? null : (string) $line->quantity,
            'delivered' => $line === null ? null : (string) $line->quantity_delivered,

            /*
             * The order's EXPECTED DATE, or null. Called what Sales calls it
             * — the form label, SalesOrderResource, the export and the sort
             * all say "expected date", and StoreSalesOrderRequest validates
             * it only as after_or_equal:order_date. Whether it is a PROMISE
             * to the customer is not something this field carries or this
             * class may assert (PENDING-OWNER-QUESTIONS).
             */
            'expected_date' => $order?->expected_date?->toDateString(),

            'planning' => [
                // FREE FINISHED GOODS for this product, from the planning
                // read's own AvailabilityService call. ITEM-level, not
                // line-level: it is the same figure for every request against
                // the same product, and a screen that grouped them must take
                // it ONCE rather than sum it.
                'free' => $estimate['free'] ?? null,

                'queued_ahead' => $estimate['queued_ahead'] ?? null,
                'capacity_per_shift' => $estimate['capacity_per_shift'] ?? null,
                'shifts_needed' => $estimate['shifts_needed'] ?? null,
                'estimated_ready_date' => $estimate['estimated_ready_date'] ?? null,

                /*
                 * NO PLANNING ROW IS NOT "NO PROBLEM". A request the walk did
                 * not reach cannot be dated, and the honest answer is the
                 * refusal with no reason attached — never a blank cell that
                 * reads like a row simply awaiting its turn.
                 */
                'cannot_estimate' => $estimate === null ? true : (bool) $estimate['cannot_estimate'],
                'reason' => $estimate['reason'] ?? null,
            ],
        ];
    }
}
