<?php

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\AvailabilityService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Collection;

/**
 * THE SALES FULFILMENT CONTROL VIEW — one row per sales order line, and the
 * one place every team reads the SAME fulfilment state.
 *
 * It answers, for a factory user, the five questions every screen in this
 * workflow has to answer: what is the status, what is blocking this line, who
 * must act next, how much stock is available or held, and when it can be
 * dispatched.
 *
 * IT IS A READ. Nothing here writes, holds, releases, requests or dispatches:
 * every act stays on the screen that already owns it (the store's fulfilment
 * queue, the floor's production queue, sales' delivery and invoice). This
 * service composes the other modules' SERVICES, never their models directly —
 * AvailabilityService and StockReservationService for stock, and
 * ProductionRequestService for what the floor owes.
 *
 * HONESTY ABOUT WHAT IS NOT RECORDED (the load-bearing rule of this file).
 * Four of the columns the control view is asked for have NO SOURCE IN THIS
 * BUILD, and they are reported as the string 'not_recorded' with a reason a
 * person can read — never as 0, never as blank, never as a guess:
 *
 *   store_rejected      the reservation vocabulary is active/released/consumed;
 *                       a release is not distinguishable from a refusal, so
 *                       "the store rejected this quantity" is not a fact this
 *                       database holds.
 *   production.planned  planning (FulfilmentPlanningService) is READ-ONLY —
 *                       it persists no approved schedule to read back.
 *   production.completed  no FK joins a production request to a shift
 *                       production entry; "produced" is inferred from line
 *                       coverage, so completed-against-this-line is unknown.
 *   qa / customer_approval  neither gate exists anywhere in this build.
 *
 * A blank column would read as "nothing to worry about" on a factory floor.
 * That is the one thing this view must never say by accident.
 */
class FulfilmentControlService
{
    /** No source in this build — said in words, never shown as a zero. */
    public const NOT_RECORDED = 'not_recorded';

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly StockReservationService $reservations,
        private readonly ProductionRequestService $productionRequests,
    ) {}

    /**
     * Every line of every LIVE order — the same population the store's
     * fulfilment queue works, so the two screens can never disagree about
     * which lines exist.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $lines = SalesOrderLine::query()
            ->with([
                'item:id,sku,name,display_name,uom',
                'salesOrder:id,status,customer_id,order_date,expected_date',
                'salesOrder.customer:id,name',
            ])
            ->whereHas('salesOrder', fn ($order) => $order->whereIn('status', [
                SalesOrderStatus::Confirmed,
                SalesOrderStatus::PartiallyDelivered,
            ]))
            ->orderBy('sales_order_id')
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $lineIds = $lines->map(fn (SalesOrderLine $line) => (int) $line->id)->all();
        $itemIds = $lines->map(fn (SalesOrderLine $line) => (int) $line->item_id)->unique()->values()->all();

        // Batch reads for the whole page — never one per row.
        $availability = [];
        foreach ($this->availability->forItems($itemIds) as $figures) {
            $availability[$figures['item_id']] = $figures;
        }
        $holds = $this->reservations->activeForLines($lineIds);
        $requests = $this->productionRequests->openForLines($lineIds);
        $invoiced = $this->invoicedByLine($lineIds);

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = $this->row(
                $line,
                $availability[(int) $line->item_id] ?? null,
                $holds[(int) $line->id] ?? null,
                $requests[(int) $line->id] ?? null,
                $invoiced[(int) $line->id] ?? '0.0000',
            );
        }

        // THE ROWS THAT NEED A HUMAN COME FIRST. Everything else keeps the
        // order book's own order (usort is stable from PHP 8.0).
        usort($rows, fn (array $a, array $b) => $b['blocker']['severity'] <=> $a['blocker']['severity']);

        return $rows;
    }

    /**
     * @param  array{item_id: int, on_hand: string, reserved: string, free: string, over_reserved: string}|null  $availability
     * @param  Collection<int, StockReservation>|null  $holds
     * @return array<string, mixed>
     */
    private function row(
        SalesOrderLine $line,
        ?array $availability,
        ?Collection $holds,
        ?ProductionRequest $request,
        string $invoiced,
    ): array {
        $ordered = (string) $line->quantity;
        $delivered = (string) $line->quantity_delivered;
        $reserved = $this->foldOutstanding($holds);

        $outstanding = bcsub($ordered, $delivered, 4);
        $outstanding = bccomp($outstanding, '0', 4) === 1 ? $outstanding : '0.0000';

        $shortfall = bcsub($outstanding, $reserved, 4);
        $shortfall = bccomp($shortfall, '0', 4) === 1 ? $shortfall : '0.0000';

        $free = $availability['free'] ?? '0.0000';
        $overReserved = $availability['over_reserved'] ?? '0.0000';

        // WHAT COULD PHYSICALLY GO TODAY — held stock, capped at what the
        // line still owes. It is NOT a statement that it MAY go: the two
        // approval gates below are not recorded anywhere in this build, and
        // the blocker says so in words.
        $dispatchReady = bccomp($reserved, $outstanding, 4) === 1 ? $outstanding : $reserved;

        $blocker = $this->blocker($outstanding, $reserved, $shortfall, $free, $overReserved, $request, $invoiced, $delivered);

        return [
            'line_id' => (int) $line->id,
            'sales_order_id' => (int) $line->sales_order_id,
            'order_status' => $line->salesOrder?->status->value,
            'customer' => $line->salesOrder?->customer === null ? null : [
                'id' => (int) $line->salesOrder->customer->id,
                'name' => $line->salesOrder->customer->name,
            ],
            'item' => $line->item === null ? null : [
                'id' => (int) $line->item->id,
                'sku' => $line->item->sku,
                'name' => $line->item->name,
                'display_name' => $line->item->display_name,
                'uom' => $line->item->uom,
            ],

            // ---- the quantities, all 4dp decimal strings like the rest of this API
            'ordered' => $ordered,
            'delivered' => $delivered,
            'invoiced' => $invoiced,
            'available_stock' => $free,
            'held' => $reserved,
            'over_reserved' => $overReserved,
            'shortfall' => $shortfall,
            'dispatch_ready' => $dispatchReady,

            // ---- the store's decision. "Approved" is what it actually holds.
            // "Rejected" has no field in this build and is NOT inferred from a
            // release: a release may be a re-point, a cancellation or a
            // correction, and calling any of those a refusal would be a lie
            // told in a number.
            'store' => [
                'approved' => $reserved,
                'rejected' => self::NOT_RECORDED,
                'rejected_detail' => 'The ERP records holds as active/released/consumed. '
                    .'A release is not a refusal, so a store-rejected quantity is not stored.',
                'oldest_hold_at' => $this->oldestHoldAt($holds),
                'waiting_days' => $this->waitingDays($holds),
            ],

            // ---- production
            'production' => [
                'requested' => $request === null ? '0.0000' : (string) $request->quantity,
                'status' => $request?->status->value,
                'priority' => $request === null ? null : (int) $request->priority,
                'planned' => self::NOT_RECORDED,
                'planned_detail' => 'Planning is read-only in this build — no approved schedule is persisted.',
                'completed' => self::NOT_RECORDED,
                'completed_detail' => 'No link joins a production request to a shift production entry, '
                    .'so completed-against-this-line is not stored.',
            ],

            // ---- the two gates the owner asked to be separate and visible.
            // They are separate here, and both say plainly that they do not exist.
            'quality' => [
                'state' => self::NOT_RECORDED,
                'detail' => 'Internal QA approval is not recorded against a sales order line. '
                    .'Today only an already quality-rejected carton is refused at dispatch.',
            ],
            'customer_approval' => [
                'state' => self::NOT_RECORDED,
                'detail' => 'Customer approval for a customer+product combination is not built — '
                    .'no table, no status, no gate.',
            ],

            'expected_date' => $line->salesOrder?->expected_date?->toDateString(),
            'blocker' => $blocker,
        ];
    }

    /**
     * WHO MUST ACT NEXT, and why — the headline of the whole view, and the
     * only column derived rather than read. Every branch is judged on data
     * this build genuinely holds; the two unbuilt gates are named as unknown
     * rather than silently treated as passed.
     *
     * @return array{code: string, summary: string, team: string, severity: int}
     */
    private function blocker(
        string $outstanding,
        string $reserved,
        string $shortfall,
        string $free,
        string $overReserved,
        ?ProductionRequest $request,
        string $invoiced,
        string $delivered,
    ): array {
        // Promised twice — the one state that needs a decision even though
        // nothing is short. Asked first, exactly as the store's queue does.
        if (bccomp($overReserved, '0', 4) === 1 && bccomp($reserved, '0', 4) === 1) {
            return [
                'code' => 'over_reserved',
                'summary' => 'This product is promised to more orders than there is stock for.',
                'team' => 'Store',
                // THE HIGHEST SEVERITY THERE IS, beating even a line with
                // nothing held and nothing requested. S8, the same rule the
                // store's own queue sorts by: a short line is ordinary
                // business, but stock promised twice is a decision somebody
                // has to make, and burying it below the merely-short rows is
                // how it gets made by accident.
                'severity' => 8,
            ];
        }

        if (bccomp($outstanding, '0', 4) !== 1) {
            // Nothing left to ship. The only thing that can still be owed is the bill.
            if (bccomp($invoiced, $delivered, 4) === -1) {
                return [
                    'code' => 'awaiting_invoice',
                    'summary' => 'Everything ordered has been delivered; it is not fully invoiced.',
                    'team' => 'Accounts',
                    'severity' => 2,
                ];
            }

            return [
                'code' => 'complete',
                'summary' => 'Delivered and invoiced in full.',
                'team' => '—',
                'severity' => 0,
            ];
        }

        if (bccomp($shortfall, '0', 4) === 1) {
            if ($request !== null) {
                return $request->status->value === 'in_progress'
                    ? [
                        'code' => 'in_production',
                        'summary' => 'The floor has started making the shortfall.',
                        'team' => 'Production',
                        'severity' => 3,
                    ]
                    : [
                        'code' => 'queued_for_production',
                        'summary' => 'The shortfall is on the floor\'s worklist and has not been started.',
                        'team' => 'Production',
                        'severity' => 4,
                    ];
            }

            return bccomp($free, '0', 4) === 1
                ? [
                    'code' => 'store_has_not_held_stock',
                    'summary' => 'Stock is free in the finished-goods store and has not been held for this order.',
                    'team' => 'Store',
                    'severity' => 6,
                ]
                : [
                    'code' => 'short_and_not_requested',
                    'summary' => 'There is no free stock and no production has been asked for.',
                    'team' => 'Store',
                    'severity' => 7,
                ];
        }

        // Covered by holds. The stock exists and is spoken for — but whether it
        // MAY leave depends on two gates this build does not record, and saying
        // "ready to dispatch" without them is the false green this view exists
        // to avoid.
        return [
            'code' => 'held_awaiting_dispatch',
            'summary' => 'Held in full and waiting to be dispatched. '
                .'Internal QA approval and customer approval are not recorded in the ERP — confirm both off-system before releasing.',
            'team' => 'Store',
            'severity' => 1,
        ];
    }

    /** How long the OLDEST live hold on this line has been waiting — the ageing signal. */
    private function waitingDays(?Collection $holds): ?int
    {
        $oldest = $holds?->min('created_at');

        return $oldest === null ? null : (int) $oldest->diffInDays(now());
    }

    private function oldestHoldAt(?Collection $holds): ?string
    {
        return $holds?->min('created_at')?->toDateString();
    }

    /**
     * Invoiced quantity per line. Counts EVERY invoice raised in the ERP,
     * drafts included — the same rule the sales order's own figure uses, and
     * the one Q44 records as an engineering default rather than a decision.
     *
     * @param  list<int>  $lineIds
     * @return array<int, string>
     */
    private function invoicedByLine(array $lineIds): array
    {
        return InvoiceLine::query()
            ->whereIn('sales_order_line_id', $lineIds)
            ->get(['sales_order_line_id', 'quantity'])
            ->groupBy('sales_order_line_id')
            ->map(fn (Collection $lines) => $lines->reduce(
                fn (string $carry, InvoiceLine $line) => bcadd($carry, (string) $line->quantity, 4),
                '0.0000',
            ))
            ->all();
    }

    /**
     * What a line's live holds still hold — quantity minus what has already
     * been consumed or given up, the same fold the store's queue uses.
     *
     * @param  Collection<int, StockReservation>|null  $holds
     */
    private function foldOutstanding(?Collection $holds): string
    {
        if ($holds === null) {
            return '0.0000';
        }

        // The MODEL's own definition of what a hold still holds — never a
        // second arithmetic here, so this view and the store's queue cannot
        // drift apart on what "held" means.
        return $holds->reduce(
            fn (string $carry, StockReservation $hold) => bcadd($carry, $hold->outstandingQuantity(), 4),
            '0.0000',
        );
    }
}
