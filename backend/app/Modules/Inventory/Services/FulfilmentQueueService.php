<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * THE STORE'S FULFILMENT QUEUE — every line of every live order, with what
 * is held against it, what is free, what is short, and what the store is
 * allowed to do about it.
 *
 * ONE PLACE COMPUTES `fulfilment_state` AND `can`, and it is this one. Both
 * ride on the wire (the PurchaseOrderResource pattern) precisely so no
 * screen re-derives the state machine: the browser renders the answer the
 * server gave, and the write refuses on the same predicate it printed.
 *
 * NOTHING HERE WRITES and nothing here locks. It is the read behind a
 * screen; every figure a WRITE depends on is recomputed inside
 * StockReservationService's transaction under the balance lock, because the
 * screen the storekeeper is looking at was true a moment ago.
 *
 * SORTED WITH THE TROUBLE FIRST (S8): an over-reserved line goes to the top
 * of the queue whatever page it would otherwise fall on. More pieces are
 * promised than the factory holds and somebody has to decide whose order
 * gives way — that is not a row to discover on page four.
 *
 * AND THE FINISHED LINES ARE HIDDEN BY DEFAULT (S16): a fully allocated line
 * needs no action from the store, and a queue whose majority needs nothing
 * stops being read. They are still reachable — `?state=fully_allocated` asks
 * for them by name.
 *
 * PAGINATED IN PHP, not in SQL, and deliberately: `fulfilment_state` is
 * computed from three sources (the line, its holds, the item's balance) and
 * the sort puts over-reserved rows first, neither of which is a column. The
 * candidate set is the lines of OPEN orders only — bounded by the order book,
 * not by history. Same shape as ProductStandardsWorkspaceService, which
 * pages an assessed collection for the same reason.
 *
 * FC-06: no rate, no cost, no supplier. `unit_price` sits on the sales order
 * line and is deliberately NOT read here — this is the store's screen, and
 * the store is not shown money.
 */
class FulfilmentQueueService
{
    /** Nothing held, nothing delivered — the line is untouched. */
    public const STATE_UNTOUCHED = 'untouched';

    /** Some of it is held or delivered, some of it is still short. */
    public const STATE_PARTIALLY_ALLOCATED = 'partially_allocated';

    /** Short, and the shortfall is already on the floor's worklist. */
    public const STATE_AWAITING_PRODUCTION = 'awaiting_production';

    /** The item is promised more times than the factory holds it (S8). */
    public const STATE_OVER_RESERVED = 'over_reserved';

    /** Delivered plus held covers what the customer ordered. */
    public const STATE_FULLY_ALLOCATED = 'fully_allocated';

    /** @var list<string> */
    public const STATES = [
        self::STATE_UNTOUCHED,
        self::STATE_PARTIALLY_ALLOCATED,
        self::STATE_AWAITING_PRODUCTION,
        self::STATE_OVER_RESERVED,
        self::STATE_FULLY_ALLOCATED,
    ];

    public const PER_PAGE_DEFAULT = 25;

    public const PER_PAGE_MAX = 200;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly StockReservationService $reservations,
        // WHAT THE FLOOR HAS BEEN ASKED FOR — read through Production's own
        // service, never its tables (the module rule). Cross-module
        // injection exactly as SalesCostInsightService does it.
        private readonly ProductionRequestService $productionRequests,
    ) {}

    /**
     * The queue, one page of it.
     *
     * @param  array<string, mixed>  $filters  validated ListFulfilmentQueueRequest input
     */
    public function queue(array $filters = [], int $perPage = self::PER_PAGE_DEFAULT, int $page = 1): LengthAwarePaginator
    {
        $rows = $this->rows();

        $state = $filters['state'] ?? null;

        // NO STATE ASKED FOR IS NOT "EVERYTHING" (S16) — it is everything
        // that still needs somebody. A named state is taken literally,
        // including `fully_allocated`, which is the only way to see them.
        $rows = $state === null
            ? array_values(array_filter($rows, fn (array $row) => $row['fulfilment_state'] !== self::STATE_FULLY_ALLOCATED))
            : array_values(array_filter($rows, fn (array $row) => $row['fulfilment_state'] === $state));

        return (new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        ))->withQueryString();
    }

    /**
     * IS THIS ORDER READY TO GO OUT? Every line covered by what has already
     * been delivered plus what is still held for it.
     *
     * SO-LEVEL, and only for an order that is actually live: a draft has
     * nothing to dispatch, a completed one already went, and a cancelled one
     * never will. An order with NO LINES answers false rather than true —
     * "every line of none is covered" is technically so and operationally a
     * badge on an empty piece of paper.
     *
     * IT GATES NOTHING (Q27 untouched). This is a badge on a list row; the
     * Delivery flow refuses and permits exactly what it did before.
     */
    public function readyForDispatch(SalesOrder $order): bool
    {
        if (! in_array($order->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered], true)) {
            return false;
        }

        $lines = $order->relationLoaded('lines') ? $order->lines : $order->lines()->get();

        if ($lines->isEmpty()) {
            return false;
        }

        $held = $this->reservations->activeForLines(
            $lines->map(fn (SalesOrderLine $line) => (int) $line->id)->all()
        );

        foreach ($lines as $line) {
            $covered = bcadd(
                (string) $line->quantity_delivered,
                $this->foldOutstanding($held[(int) $line->id] ?? null),
                4,
            );

            if (bccomp($covered, (string) $line->quantity, 4) === -1) {
                return false;
            }
        }

        return true;
    }

    // ---- internals --------------------------------------------------------

    /**
     * Every candidate row, computed and sorted — over-reserved first, then
     * oldest order first so the queue reads like the order book does.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $lines = SalesOrderLine::query()
            ->with(['item:id,sku,name,uom', 'salesOrder:id,status,customer_id', 'salesOrder.customer:id,name'])
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

        // Three batch reads for the whole page — never one per row.
        $availability = [];
        foreach ($this->availability->forItems($itemIds) as $figures) {
            $availability[$figures['item_id']] = $figures;
        }
        $holds = $this->reservations->activeForLines($lineIds);
        $requests = $this->productionRequests->openForLines($lineIds);

        $rows = [];

        foreach ($lines as $line) {
            $rows[] = $this->row(
                $line,
                $availability[(int) $line->item_id] ?? null,
                $holds[(int) $line->id] ?? null,
                $requests[(int) $line->id] ?? null,
            );
        }

        // OVER-RESERVED FIRST, and the rest left in the order the query
        // produced them (usort is not stable in PHP < 8.0; it is from 8.0,
        // which this project requires — so the secondary order survives).
        usort(
            $rows,
            fn (array $a, array $b) => (int) ($b['fulfilment_state'] === self::STATE_OVER_RESERVED)
                <=> (int) ($a['fulfilment_state'] === self::STATE_OVER_RESERVED)
        );

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
    ): array {
        $ordered = (string) $line->quantity;
        $delivered = (string) $line->quantity_delivered;
        $reserved = $this->foldOutstanding($holds);

        // WHAT THE LINE STILL OWES THE CUSTOMER after everything already
        // shipped and everything still held — the same figure the demand cap
        // (S5) and the shortfall cap (S14) are judged against, so the number
        // on the screen is the number the write will allow.
        $shortfall = bcsub(bcsub($ordered, $delivered, 4), $reserved, 4);
        $shortfall = bccomp($shortfall, '0', 4) === 1 ? $shortfall : '0.0000';

        $free = $availability['free'] ?? '0.0000';
        $overReserved = $availability['over_reserved'] ?? '0.0000';

        $state = $this->state($ordered, $delivered, $reserved, $shortfall, $overReserved, $request);

        return [
            'line_id' => (int) $line->id,
            'sales_order_id' => (int) $line->sales_order_id,
            'customer' => $line->salesOrder?->customer === null ? null : [
                'id' => (int) $line->salesOrder->customer->id,
                'name' => $line->salesOrder->customer->name,
            ],
            'item' => $line->item === null ? null : [
                'id' => (int) $line->item->id,
                'sku' => $line->item->sku,
                'name' => $line->item->name,
            ],
            'ordered' => $ordered,
            'delivered' => $delivered,
            'reserved' => $reserved,
            'shortfall' => $shortfall,
            // ITEM-LEVEL, not line-level: what is free in the finished-goods
            // store for this product, and by how much the product as a whole
            // is over-promised. Both are why this line is where it is.
            'free' => $free,
            'over_reserved' => $overReserved,
            'fulfilment_state' => $state,
            'holds' => $this->holdPayload($line, $holds),
            'request' => $request === null ? null : [
                'id' => (int) $request->id,
                'status' => $request->status->value,
                'priority' => (int) $request->priority,
                'quantity' => (string) $request->quantity,
            ],
            'can' => $this->abilities($shortfall, $free, $reserved, $request),
        ];
    }

    /**
     * THE FIVE STATES, in the order they are asked.
     *
     * OVER-RESERVED IS ASKED FIRST and beats even a fully covered line (S8 +
     * brief line 29: over-reserved rows sort first). A covered line normally
     * needs nothing from the store — but a covered line whose stock is
     * promised twice is precisely the line somebody has to make a decision
     * about, so it is neither hidden by the default filter nor buried below
     * the rows that are merely short.
     *
     * AND IT IS ONLY SAID OF A LINE THAT ACTUALLY HOLDS SOME. The
     * over-reservation is a figure about the ITEM; painting every line of a
     * busy product red would make the word mean "popular" instead of
     * "promised twice", and the store would stop reading it.
     */
    private function state(
        string $ordered,
        string $delivered,
        string $reserved,
        string $shortfall,
        string $overReserved,
        ?ProductionRequest $request,
    ): string {
        if (bccomp($overReserved, '0', 4) === 1 && bccomp($reserved, '0', 4) === 1) {
            return self::STATE_OVER_RESERVED;
        }

        if (bccomp($shortfall, '0', 4) !== 1) {
            return self::STATE_FULLY_ALLOCATED;
        }

        if ($request !== null) {
            return self::STATE_AWAITING_PRODUCTION;
        }

        $touched = bccomp($reserved, '0', 4) === 1 || bccomp($delivered, '0', 4) === 1;

        return $touched ? self::STATE_PARTIALLY_ALLOCATED : self::STATE_UNTOUCHED;
    }

    /**
     * WHAT THE STORE MAY DO WITH THIS ROW — each one derived from the
     * refusal it mirrors, so the button and the 422 cannot disagree:
     *
     *   reserve             StockReservationException::nothingFree (free > 0)
     *                       and ::exceedsRemainingDemand (shortfall > 0)
     *   release / repoint   ::cannotRelease — there has to be a live hold
     *   send_to_production  ProductionRequestException::nothingShort
     *                       (shortfall > 0) and ::alreadyOpenForLine
     *
     * The order being live is not re-asked: only live orders are in this
     * queue at all.
     *
     * THE PAIR THE STORE'S SCREEN TURNS ON: free = 0 with a shortfall means
     * reserve is false and send_to_production is true. There is nothing to
     * hold, so the answer is to make it.
     *
     * @return array{reserve: bool, release: bool, repoint: bool, send_to_production: bool}
     */
    private function abilities(string $shortfall, string $free, string $reserved, ?ProductionRequest $request): array
    {
        $short = bccomp($shortfall, '0', 4) === 1;
        $holdsSomething = bccomp($reserved, '0', 4) === 1;

        return [
            'reserve' => $short && bccomp($free, '0', 4) === 1,
            'release' => $holdsSomething,
            'repoint' => $holdsSomething,
            'send_to_production' => $short && $request === null,
        ];
    }

    /**
     * "held for {customer} since {date}" — the line's own live holds, oldest
     * first.
     *
     * The customer and the order ride on every hold even though they are the
     * row's own, so the shape does not change if a future screen ever shows
     * a hold beside a row it does not belong to (the contested-stock
     * question the owner has not answered yet).
     *
     * @param  Collection<int, StockReservation>|null  $holds
     * @return list<array<string, mixed>>
     */
    private function holdPayload(SalesOrderLine $line, ?Collection $holds): array
    {
        if ($holds === null) {
            return [];
        }

        $customer = $line->salesOrder?->customer;

        return $holds->map(fn (StockReservation $hold) => [
            'reservation_id' => (int) $hold->id,
            'quantity' => (string) $hold->quantity,
            'consumed_quantity' => (string) $hold->consumed_quantity,
            'held_since' => $hold->created_at?->toIso8601String(),
            'customer' => $customer === null ? null : ['id' => (int) $customer->id, 'name' => $customer->name],
            'sales_order_id' => (int) $line->sales_order_id,
        ])->values()->all();
    }

    /**
     * What a set of holds is still holding — outstanding, never quantity, so
     * a partially re-pointed or partially delivered hold is not counted
     * twice (see StockReservation::outstandingQuantity).
     *
     * @param  Collection<int, StockReservation>|null  $holds
     */
    private function foldOutstanding(?Collection $holds): string
    {
        $total = '0.0000';

        foreach ($holds ?? [] as $hold) {
            $total = bcadd($total, $hold->outstandingQuantity(), 4);
        }

        return $total;
    }
}
