<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Exceptions\ProductionRequestException;
use App\Modules\Production\Models\Enums\ProductionRequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE FLOOR'S WORKLIST — what the store could not cover out of finished
 * goods, in the order the factory should make it.
 *
 * PAPERWORK ONLY (invariant 2). Nothing here creates, starts or cancels a
 * batch, nothing writes a shift entry, nothing moves stock and nothing
 * posts a voucher. `start()` records that a person picked the job up.
 * People start batches; this class only says what is owed.
 *
 * THE LOCK ORDER production_requests sits at the BOTTOM of (see
 * StockReservationService's docblock for the whole chain):
 *
 *     sales_orders / sales_order_lines → material_bags → stock_balances
 *       → stock_reservations → production_requests
 *
 * createFromShortfall locks the LINE first — it is the only write here that
 * needs a sales figure to be stable while it decides, and the line lock is
 * what serialises two people sending the same line to production at once
 * (the one-open-request rule cannot be a MySQL partial unique index).
 * markProducedIfCovered deliberately locks NOTHING above itself: it is
 * called at the end of a delivery that already holds the balance and the
 * reservation rows, so reaching back up for a sales lock there is exactly
 * how a deadlock would be built.
 *
 * FC-06: no rate, no cost, no vendor on this surface.
 */
class ProductionRequestService
{
    public function __construct(
        // COVERAGE IS A RESERVATION FIGURE, and it is read through
        // Inventory's own service — never its tables.
        private readonly StockReservationService $reservations,
    ) {}

    /** Relations both `queue()` and `withStatuses()` load — one list, one place. */
    private const WITH = ['item:id,sku,name,display_name,uom', 'salesOrderLine.salesOrder.customer:id,name', 'requestedBy:id,name'];

    // ---- reads ------------------------------------------------------------

    /**
     * The queue: everything still owed, in priority order.
     *
     * @return Collection<int, ProductionRequest>
     */
    public function queue(): Collection
    {
        $requests = ProductionRequest::query()
            ->open()
            ->with(self::WITH)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($requests as $request) {
            $this->decorate($request);
        }

        return $requests;
    }

    /**
     * Requests in the named statuses, newest first — the LOOK-BACK reader
     * the owner asked for on 03-Sep-2026 (DEC-20260902-032 leaves a produced
     * request out of the queue with no other way to see it again).
     *
     * `queue()` stays the floor's open worklist, ordered by priority and
     * never touched here; this one never reorders, and is never the source
     * for Start or Cancel — decorate() still stamps `can` on every row so a
     * finished row reads as read-only (start/cancel/reorder all false for a
     * final status), but nothing on this path writes.
     *
     * @param  array<int, ProductionRequestStatus>  $statuses
     * @return Collection<int, ProductionRequest>
     */
    public function withStatuses(array $statuses): Collection
    {
        $requests = ProductionRequest::query()
            ->with(self::WITH)
            ->whereIn('status', array_map(fn (ProductionRequestStatus $s) => $s->value, $statuses))
            ->orderByDesc('id')
            ->get();

        foreach ($requests as $request) {
            $this->decorate($request);
        }

        return $requests;
    }

    /** The open request on a line, if there is one — the queue row's `request`. */
    public function openForLine(int $salesOrderLineId): ?ProductionRequest
    {
        return ProductionRequest::query()
            ->open()
            ->where('sales_order_line_id', $salesOrderLineId)
            ->orderBy('priority')
            ->first();
    }

    /**
     * The same answer for a WHOLE PAGE of lines, in one query — keyed by
     * sales_order_line_id.
     *
     * The store's fulfilment queue prints an open request on every row, and
     * Inventory may not read Production's tables (the module rule), so
     * asking openForLine() per row would be a query per row through a
     * service call. One open request per line is the invariant
     * createFromShortfall enforces, so a flat map is the whole answer.
     *
     * @param  list<int>  $salesOrderLineIds
     * @return array<int, ProductionRequest>
     */
    public function openForLines(array $salesOrderLineIds): array
    {
        if ($salesOrderLineIds === []) {
            return [];
        }

        return ProductionRequest::query()
            ->open()
            ->whereIn('sales_order_line_id', $salesOrderLineIds)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (ProductionRequest $request) => (int) $request->sales_order_line_id)
            ->all();
    }

    /**
     * THE STATE MACHINE, IN ONE PLACE. The resource prints it as `can` and
     * every action re-asks it before writing, so no screen re-derives what
     * is allowed (the MaterialRequestService pattern).
     *
     * `cancel` is TWO-SIDED (P3): the store that raised it and the floor
     * that would run it may both withdraw it. Which permission each caller
     * holds is the route's business, not this method's — this only says
     * whether the DOCUMENT is in a state that can be cancelled at all.
     *
     * @return array{start: bool, cancel: bool, reorder: bool}
     */
    public function abilities(ProductionRequest $request): array
    {
        return [
            'start' => $request->status === ProductionRequestStatus::Queued,
            'cancel' => ! $request->status->isFinal(),
            'reorder' => $request->status->isOpen(),
        ];
    }

    public function decorate(ProductionRequest $request): ProductionRequest
    {
        $request->can = $this->abilities($request);

        return $request;
    }

    // ---- writes -----------------------------------------------------------

    /**
     * SEND A LINE'S SHORTFALL TO THE FLOOR.
     *
     * S14, THE SHORTFALL CAP: what the floor is asked for is CAPPED at what
     * the line is genuinely short of — ordered less delivered less still
     * held — rather than refused. The store types a round number; the
     * factory should not be asked to make pieces no customer is waiting for
     * ahead of pieces somebody is, and a refusal over 20 pieces would just
     * be retyped.
     *
     * ONE OPEN REQUEST PER LINE, enforced here under the line's lock
     * because MySQL has no partial unique index. A produced or cancelled
     * request does not block a new one — a line whose first run was
     * scrapped must be able to ask again.
     */
    public function createFromShortfall(SalesOrderLine $line, string $quantity, ?int $userId): ProductionRequest
    {
        $this->guardPositive($quantity);

        return DB::transaction(function () use ($line, $quantity, $userId) {
            // HEAD OF THE GLOBAL LOCK ORDER: the parent ORDER row first —
            // judged for openness under its own lock, so a cancel committing
            // in the same instant cannot leave a request the withdraw scan
            // already missed (C1) — then the line row, where two people
            // sending the same line meet and the second sees the first's
            // request. The same two locks reserve() takes, in the same
            // order, which is what makes the shortfall and demand caps read
            // each other's writes instead of each other's screenshots (C2).
            $order = $this->lockOpenOrder((int) $line->sales_order_id);

            $fresh = SalesOrderLine::query()
                ->whereKey($line->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $fresh->setRelation('salesOrder', $order);

            // GLOBAL LOCK ORDER: the line's holds are read UNDER their own
            // locks, and BEFORE the request check takes production_requests
            // — reservations sit above requests in the order, and an
            // unlocked held figure could be read mid-release and undersize
            // what the floor is asked for (Codex P1, PR #33).
            $held = $this->reservations->heldOnLineLocked((int) $fresh->id);

            $existing = ProductionRequest::query()
                ->open()
                ->where('sales_order_line_id', $fresh->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw ProductionRequestException::alreadyOpenForLine($existing);
            }

            $shortfall = bcsub(
                bcsub((string) $fresh->quantity, (string) $fresh->quantity_delivered, 4),
                $held,
                4,
            );

            if (bccomp($shortfall, '0', 4) !== 1) {
                throw ProductionRequestException::nothingShort($this->lineLabel($fresh));
            }

            $capped = bccomp($quantity, $shortfall, 4) === 1 ? $shortfall : $quantity;

            return $this->decorate(ProductionRequest::create([
                'sales_order_line_id' => $fresh->id,
                'item_id' => $fresh->item_id,
                'quantity' => $capped,
                'priority' => $this->nextPriority(),
                'status' => ProductionRequestStatus::Queued,
                'requested_by' => $userId,
            ]));
        });
    }

    /**
     * REWRITE THE WHOLE QUEUE'S ORDER, in one transaction holding every open
     * row locked.
     *
     * It has to be the whole queue: renumbering a subset would leave the
     * requests it omitted carrying stale priorities against the ones it
     * moved, and two rows would claim the same place. Priorities are dense
     * and 1-based afterwards; there is no unique index precisely so the
     * intermediate states of this rewrite are legal.
     *
     * @param  list<int>  $orderedIds
     * @return Collection<int, ProductionRequest>
     */
    public function reorder(array $orderedIds): Collection
    {
        return DB::transaction(function () use ($orderedIds) {
            $open = ProductionRequest::query()
                ->open()
                ->orderBy('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $openIds = $open->pluck('id')->map(fn ($id) => (int) $id)->all();
            $given = array_values(array_unique(array_map('intval', $orderedIds)));

            $missing = array_values(array_diff($openIds, $given));
            $unknown = array_values(array_diff($given, $openIds));

            if ($missing !== [] || $unknown !== []) {
                throw ProductionRequestException::reorderMustCoverQueue(
                    $missing,
                    count($given),
                    count($openIds),
                );
            }

            $byId = $open->keyBy('id');

            foreach ($given as $index => $id) {
                $byId[$id]->update(['priority' => $index + 1]);
            }

            return $this->queue();
        });
    }

    /** Somebody on the floor picked the job up. No batch is created (invariant 2). */
    public function start(ProductionRequest $request): ProductionRequest
    {
        return DB::transaction(function () use ($request) {
            $locked = $this->lock($request->getKey());

            if (! $this->abilities($locked)['start']) {
                throw ProductionRequestException::cannotStart($locked);
            }

            $locked->update(['status' => ProductionRequestStatus::InProgress]);

            return $this->decorate($locked);
        });
    }

    /**
     * WITHDRAW A REQUEST, with a reason. TWO-SIDED (P3) — the store and the
     * floor may both do it, exactly like a MaterialRequest.
     *
     * Cancelling reverses nothing on the floor: pieces already made stay
     * made and reach the order through a hold, never by un-cancelling this
     * row.
     */
    public function cancel(ProductionRequest $request, string $reason): ProductionRequest
    {
        return DB::transaction(function () use ($request, $reason) {
            $locked = $this->lock($request->getKey());

            if (! $this->abilities($locked)['cancel']) {
                throw ProductionRequestException::cannotCancel($locked);
            }

            $locked->update([
                'status' => ProductionRequestStatus::Cancelled,
                'cancelled_reason' => $reason,
            ]);

            return $this->decorate($locked);
        });
    }

    /**
     * EVERY OPEN REQUEST ON A CANCELLED ORDER, WITHDRAWN — S6. Called from
     * inside SalesOrderService::cancel's existing transaction, which already
     * holds the order row (head of the global lock order).
     *
     * @return int how many requests were cancelled
     */
    public function cancelForOrder(SalesOrder $order, string $reason): int
    {
        $requests = ProductionRequest::query()
            ->open()
            ->whereIn(
                'sales_order_line_id',
                SalesOrderLine::query()->where('sales_order_id', $order->getKey())->select('id')
            )
            ->lockForUpdate()
            ->get();

        foreach ($requests as $request) {
            $request->update([
                'status' => ProductionRequestStatus::Cancelled,
                'cancelled_reason' => $reason,
            ]);
        }

        return $requests->count();
    }

    /**
     * S1 — IS THE LINE COVERED? Judged on the ORDER LINE, never on the
     * request.
     *
     * COVERAGE = what has already been delivered against the line + what the
     * line still holds. Both halves counted ONCE:
     *
     *   - delivered counts whether or not a hold was behind it (a delivery
     *     does not require one);
     *   - only the OUTSTANDING part of each active hold is added, because
     *     the consumed part of a hold IS the delivered pieces and is
     *     already in the first half.
     *
     * The brief's wording was "sum(active + consumed reservation quantity) +
     * delivered", which double-counts exactly that overlap: a 100-piece line
     * with a 100-piece hold, 60 delivered out of it, would read 100 + 60 =
     * 160 and mark the line produced with 40 pieces still to make. Recorded
     * as a deviation; the RULE it implements — coverage on the line, never
     * on the request — is the brief's, unchanged.
     *
     * THE COUNTER-EXAMPLE THAT MOTIVATED S1: a 100-piece line with 90 free
     * pieces held and a 10-piece request outstanding is covered 90, not 100.
     * The request stays exactly where it was.
     *
     * @return int how many requests were marked produced
     */
    public function markProducedIfCovered(SalesOrderLine $line): int
    {
        // NO LOCK ABOVE production_requests. This runs at the tail of a
        // delivery that already holds the balance and the reservation rows
        // (S9); a sales-side lock taken here would be out of order.
        $fresh = SalesOrderLine::query()->findOrFail($line->getKey());

        // The held figure is read UNDER the reservation locks (Codex P1,
        // PR #33): an unlocked read here could count a hold mid-release and
        // retire a request the line still needs. Reservations before
        // production_requests — the global order, same as every other path.
        $coverage = bcadd(
            (string) $fresh->quantity_delivered,
            $this->reservations->heldOnLineLocked((int) $fresh->id),
            4,
        );

        if (bccomp($coverage, (string) $fresh->quantity, 4) === -1) {
            return 0;
        }

        // QUEUED ONLY (Codex P1, PR #33). A request the floor has already
        // STARTED is not silently taken off its worklist by paperwork —
        // pieces may be mid-machine, and whether a running job should stop
        // is the floor's call (and part of open question Q62). Coverage
        // retires only what nobody has begun.
        $open = ProductionRequest::query()
            ->where('status', ProductionRequestStatus::Queued)
            ->where('sales_order_line_id', $fresh->id)
            ->lockForUpdate()
            ->get();

        foreach ($open as $request) {
            $request->update(['status' => ProductionRequestStatus::Produced]);
        }

        return $open->count();
    }

    // ---- internals --------------------------------------------------------

    /** End of the queue. Dense numbering is reorder()'s job, not this one's. */
    /**
     * The current tail of the queue, LOCKED — two lines sent to production
     * at the same moment lock different sales rows, so without this they
     * both read the same maximum and land on one priority (Codex P2,
     * PR #33). Locking the single top row serialises them; an empty queue
     * has no row to lock and a same-instant tie there is settled by the
     * (priority, id) display order until the next reorder renumbers.
     *
     * @return Builder<ProductionRequest>
     */
    public static function maxPriorityLockQuery()
    {
        return ProductionRequest::query()
            ->open()
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->lockForUpdate();
    }

    private function nextPriority(): int
    {
        $highest = self::maxPriorityLockQuery()->value('priority');

        return (int) $highest + 1;
    }

    private function lock(int $id): ProductionRequest
    {
        return ProductionRequest::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The order row, LOCKED, its status judged UNDER the lock — the floor is
     * only asked to make things for an order that is actually live, and the
     * answer holds until this transaction commits. Own method so the lock is
     * pinnable (SQLite drops FOR UPDATE; the conflictingPackagingQuery
     * precedent).
     *
     * @return Builder<SalesOrder>
     */
    public static function openOrderQuery(int $salesOrderId)
    {
        return SalesOrder::query()->whereKey($salesOrderId)->lockForUpdate();
    }

    private function lockOpenOrder(int $salesOrderId): SalesOrder
    {
        $order = self::openOrderQuery($salesOrderId)->first();

        if ($order === null || ! in_array($order->status, [
            SalesOrderStatus::Confirmed,
            SalesOrderStatus::PartiallyDelivered,
        ], true)) {
            throw ProductionRequestException::orderNotOpen(
                $order?->documentNumber() ?? "order #{$salesOrderId}",
                $order?->status->value ?? 'missing',
            );
        }

        return $order;
    }

    private function guardPositive(string $quantity): void
    {
        if (bccomp($quantity, '0', 4) !== 1) {
            throw ProductionRequestException::quantityNotPositive($quantity);
        }
    }

    private function lineLabel(SalesOrderLine $line): string
    {
        return $line->salesOrder?->documentNumber() ?? "line #{$line->id}";
    }
}
