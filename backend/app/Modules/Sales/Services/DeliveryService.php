<?php

namespace App\Modules\Sales\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\FinishedCarton;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Exceptions\DispatchNotQualityApprovedException;
use App\Modules\Sales\Exceptions\OverDeliveryException;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

/**
 * Posting a Delivery is the one place Sales actually moves stock. It never
 * touches Inventory's tables directly — it goes through StockMovementService,
 * the same as any other caller of that module, so Inventory's valuation and
 * balance-locking logic stays in one place.
 */
class DeliveryService
{
    /** Loaded on every delivery the service hands back, so the resource never lazy-loads. */
    private const WITH = ['lines.item', 'warehouse', 'salesOrder.customer'];

    /** How many deliveries cursor() reads — and decorates — per query. */
    private const EXPORT_CHUNK = 500;

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly SalesDocumentQuery $query,
        private readonly SalesDocumentTraceService $trace,
        // The delivery is the one event that SPENDS a stock hold — see the
        // consumeForDelivery call inside create().
        private readonly StockReservationService $reservations,
    ) {}

    /**
     * The list, filtered (Phase 3.5). $filters is the validated
     * ListDeliveriesRequest input; an empty array is the unfiltered list —
     * newest first, same page size as before. Every row is stamped with its
     * Delivery Note TallyLink and its scanned-carton count (two queries for
     * the page, through the other modules' services).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $page = $this->listQuery($filters)->paginate($perPage)->withQueryString();
        $this->trace->decorateDeliveries($page->getCollection());

        return $page;
    }

    /**
     * Every matching delivery, in the list's order, one at a time — the
     * Export Center's read (DeliveriesExport, Phase 4.5): the SAME filters
     * and the SAME ordering as paginate(), off the same builder, each chunk
     * stamped with its Delivery Note TallyLink and carton count exactly as
     * a page is (two queries per chunk, never per row). Builder::lazy, not
     * Builder::cursor(): cursor() skips the eager loads the resource prints.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, Delivery>
     */
    public function cursor(array $filters = []): LazyCollection
    {
        return $this->listQuery($filters)
            ->lazy(self::EXPORT_CHUNK)
            ->chunk(self::EXPORT_CHUNK)
            ->flatMap(function (LazyCollection $chunk): LazyCollection {
                $this->trace->decorateDeliveries($chunk);

                return $chunk;
            });
    }

    /**
     * How many deliveries the list would carry — one COUNT over the
     * filtered query (the export's cap check; also the list's meta.total).
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->filtered($filters)->count();
    }

    /**
     * One delivery with its chain — the show endpoint: the order it fulfils
     * (with customer), the cartons that physically left on it, and its
     * Delivery Note link, as `trace`.
     */
    public function show(Delivery $delivery): Delivery
    {
        $this->decorate($delivery);
        $delivery->trace = $this->trace->deliveryTrace($delivery);

        return $delivery;
    }

    /**
     * Open sales-order lines still awaiting delivery — Delivery itself has
     * no status field (a Delivery row only ever represents stock that has
     * already gone out), so "pending" is counted from the demand side.
     */
    public function pendingCount(): int
    {
        return SalesOrder::query()
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered])
            ->count();
    }

    /**
     * @param  array{sales_order_id: int, warehouse_id: int, reference?: string, delivered_date?: string, notes?: string, lines: array<int, array{sales_order_line_id: int, quantity: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): Delivery
    {
        return DB::transaction(function () use ($data, $createdBy) {
            // HEAD OF THE GLOBAL LOCK ORDER (Cursor review, PR #33): the
            // dispatch takes the SAME order-then-lines locks that reserve()
            // and send-to-production take, so the demand cap over there can
            // never be judged against a quantity_delivered this dispatch is
            // mid-way through moving — a hold committed onto a line this
            // delivery just finished would be an orphan on a shipped line.
            // The balance lock (recordIssue) still comes after, in order.
            $order = self::orderLockQuery((int) $data['sales_order_id'])->firstOrFail();
            $order->setRelation('lines', self::lineLockQuery((int) $order->id)->get());

            if (! in_array($order->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered], true)) {
                throw InvalidStatusTransitionException::make('sales order', $order->status->value, 'delivered');
            }

            $delivery = Delivery::create([
                'sales_order_id' => $order->id,
                'warehouse_id' => $data['warehouse_id'],
                'reference' => $data['reference'] ?? null,
                'delivered_date' => $data['delivered_date'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            // DISPATCH BY SCAN: when the payload names carton codes, the
            // delivery's lines are DERIVED from the physical cartons that
            // actually left — each scanned code resolved, validated against
            // the order's items, and stamped dispatched onto this delivery.
            // The quantities then flow through the exact same per-line path
            // (over-delivery guard, stock issue, Tally event) as a typed
            // delivery. Traceability rides free: carton → batch → delivery.
            if (! empty($data['carton_codes'])) {
                $data['lines'] = $this->linesFromCartons($order, $data['carton_codes'], $delivery->id);
            }

            foreach ($data['lines'] as $lineData) {
                // Form request validation ties sales_order_line_id to this
                // sales_order_id, so a miss here means a genuine bug, not
                // a normal user error — let it fail loudly.
                $soLine = $order->lines->firstWhere('id', $lineData['sales_order_line_id']);

                $remaining = bcsub($soLine->quantity, $soLine->quantity_delivered, 4);
                if (bccomp((string) $lineData['quantity'], $remaining, 4) > 0) {
                    throw OverDeliveryException::forLine($soLine->id, $remaining, (string) $lineData['quantity']);
                }

                // THE INTERNAL QUALITY GATE — DEC-20260831-003, and the reason
                // the owner's sequence reads "Quality approves, THEN Sales
                // dispatches". Judged inside the same transaction and under the
                // same line lock as the over-delivery cap above, so a
                // withdrawal committing mid-dispatch cannot be missed.
                //
                // Capped on the QUANTITY Quality signed for, not on a boolean:
                // an approval is of a held quantity, and dispatching past it is
                // shipping stock nobody inspected. Before this, DEC-20260807-013
                // refused only an already-REJECTED carton, and a batch merely
                // not yet through QC went out freely.
                if (! $soLine->isQualityApproved()) {
                    throw DispatchNotQualityApprovedException::forLine($soLine->id);
                }

                $approvedRemaining = bcsub($soLine->qualityApprovedQuantity(), (string) $soLine->quantity_delivered, 4);
                if (bccomp((string) $lineData['quantity'], $approvedRemaining, 4) > 0) {
                    throw DispatchNotQualityApprovedException::beyondApproved(
                        $soLine->id,
                        bccomp($approvedRemaining, '0', 4) === 1 ? $approvedRemaining : '0.0000',
                        (string) $lineData['quantity'],
                    );
                }

                $delivery->lines()->create([
                    'sales_order_line_id' => $soLine->id,
                    'item_id' => $soLine->item_id,
                    'quantity' => $lineData['quantity'],
                ]);

                $this->stock->recordIssue(
                    itemId: $soLine->item_id,
                    warehouseId: $data['warehouse_id'],
                    quantity: (string) $lineData['quantity'],
                    reference: $data['reference'] ?? "Delivery for SO #{$order->id}",
                    movementDate: $data['delivered_date'] ?? null,
                    notes: $data['notes'] ?? null,
                    createdBy: $createdBy,
                    purpose: StockMovementPurpose::Dispatch,
                );

                $soLine->increment('quantity_delivered', $lineData['quantity']);

                // THE HOLD THIS DISPATCH WAS MADE AGAINST IS SPENT HERE —
                // inside the delivery's own transaction, and AFTER the
                // increment above, because consumeForDelivery judges "is this
                // line finished?" on the STORED quantity_delivered and would
                // otherwise leave a shipped line's leftover holds standing
                // (StockReservationServiceTest pins that ordering).
                //
                // It moves NO stock: recordIssue already did, a line above.
                // A delivery from a warehouse this line holds nothing in
                // spends no hold and is not an error (S3) — the holds sit in
                // the FG store and the van may legally have loaded elsewhere.
                $this->reservations->consumeForDelivery(
                    $soLine,
                    (string) $lineData['quantity'],
                    (int) $data['warehouse_id'],
                );
            }

            $this->recomputeOrderStatus($order->fresh('lines'));

            // TallySync listens and enqueues a Delivery Note voucher; Sales
            // stays unaware. In-transaction so the queued voucher is atomic
            // with the dispatch.
            event(new DeliveryDispatched($delivery));

            // Decorated like a list row: the Delivery Note the event just
            // queued is already visible on the dispatch response.
            return $this->decorate($delivery);
        });
    }

    /**
     * The list's builder: every filter applied, the relations the resource
     * prints, then the list's order — what paginate() pages and cursor()
     * streams (both decorate afterwards).
     *
     * @param  array<string, mixed>  $filters
     */
    private function listQuery(array $filters): Builder
    {
        $query = $this->filtered($filters)->with(self::WITH);
        $this->query->applySort($query, $filters['sort'] ?? null, ['delivered_date']);

        return $query;
    }

    /**
     * The filtered deliveries, nothing loaded and nothing ordered — the one
     * builder listQuery() and count() both start from.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        $query = Delivery::query();
        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Every filter of ListDeliveriesRequest. `q` matches the delivery
     * number in any spelling ("DN-5", "dn 5", "5"), the delivery's
     * reference, or the customer's name or code — never notes. The date
     * range is FACTORY days on delivered_date (a datetime), not UTC ones.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['customer_id'])) {
            $query->whereHas('salesOrder', fn (Builder $order) => $order->where('customer_id', (int) $filters['customer_id']));
        }

        if (! empty($filters['sales_order_id'])) {
            $query->where('sales_order_id', (int) $filters['sales_order_id']);
        }

        $this->query->applyFactoryDayRange($query, 'delivered_date', $filters['from'] ?? null, $filters['to'] ?? null);

        if (! empty($filters['item_id'])) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('item_id', (int) $filters['item_id']));
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = trim((string) $filters['q']);
            $id = $this->query->documentId($term, 'DN');

            $query->where(function (Builder $any) use ($term, $id) {
                if ($id !== null) {
                    $any->orWhere('deliveries.id', $id);
                }
                $any->orWhere(fn (Builder $reference) => $this->query->whereLike($reference, 'reference', $term));
                $any->orWhereHas('salesOrder.customer', fn (Builder $customer) => $this->query->whereCustomerMatches($customer, $term));
            });
        }
    }

    /** The relations the resource prints plus the TallyLink and carton count, on ONE delivery. */
    private function decorate(Delivery $delivery): Delivery
    {
        $delivery->load(self::WITH);
        $this->trace->decorateDeliveries([$delivery]);

        return $delivery;
    }

    /**
     * Resolve scanned carton codes into delivery lines. Every carton must
     * exist, be in stock, and hold an item this order actually carries —
     * refusals name the exact carton, because the person holding the scanner
     * is looking at it. Cartons are locked, stamped dispatched, and tied to
     * the delivery; quantities are grouped per order line.
     *
     * @param  array<int, string>  $codes
     * @return array<int, array{sales_order_line_id: int, quantity: string}>
     */
    /**
     * The dispatch's head locks, in their own methods so the lock each
     * carries is a thing a test can pin — SQLite drops FOR UPDATE (the
     * conflictingPackagingQuery precedent). These serialise a dispatch
     * against reserve()/repoint()/send-to-production, which take the same
     * two locks in the same order.
     *
     * @return Builder<SalesOrder>
     */
    public static function orderLockQuery(int $salesOrderId)
    {
        return SalesOrder::query()->whereKey($salesOrderId)->lockForUpdate();
    }

    /** @return Builder<SalesOrderLine> */
    public static function lineLockQuery(int $salesOrderId)
    {
        return SalesOrderLine::query()->where('sales_order_id', $salesOrderId)->lockForUpdate();
    }

    private function linesFromCartons(SalesOrder $order, array $codes, int $deliveryId): array
    {
        $byLine = [];

        foreach (array_values(array_unique($codes)) as $code) {
            $carton = FinishedCarton::query()->with('entry')->where('carton_no', $code)->lockForUpdate()->first();

            if ($carton === null) {
                throw ValidationException::withMessages([
                    'carton_codes' => "No carton carries the code {$code} — check the label.",
                ]);
            }
            if ($carton->status !== FinishedCarton::STATUS_IN_STOCK) {
                throw ValidationException::withMessages([
                    'carton_codes' => "Carton {$code} was already dispatched — it cannot leave twice.",
                ]);
            }

            // QUALITY REJECTED boxes never leave (DEC-20260807-013): the
            // sticker on the box is permanent, so the scan is where the
            // batch's quality truth speaks. Batches merely NOT YET through
            // QC/approval pass exactly as before — tightening that gate is
            // open owner question Q27, not this guard's call.
            if ($carton->entry?->status === ShiftProductionEntryStatus::Rejected) {
                throw ValidationException::withMessages([
                    'carton_codes' => "Carton {$code} is QUALITY REJECTED — its batch failed quality/approval and its boxes must not ship.",
                ]);
            }

            $soLine = $order->lines->firstWhere('item_id', $carton->item_id);
            if ($soLine === null) {
                throw ValidationException::withMessages([
                    'carton_codes' => "Carton {$code} holds an item this order does not carry — wrong pallet?",
                ]);
            }

            $carton->update(['status' => FinishedCarton::STATUS_DISPATCHED, 'delivery_id' => $deliveryId]);

            $byLine[$soLine->id] = bcadd($byLine[$soLine->id] ?? '0.0000', (string) $carton->pieces, 4);
        }

        return collect($byLine)
            ->map(fn ($quantity, $lineId) => ['sales_order_line_id' => (int) $lineId, 'quantity' => $quantity])
            ->values()
            ->all();
    }

    private function recomputeOrderStatus(SalesOrder $order): void
    {
        $fullyDelivered = $order->lines->every(
            fn ($line) => bccomp($line->quantity_delivered, $line->quantity, 4) >= 0
        );

        if ($fullyDelivered) {
            $order->update(['status' => SalesOrderStatus::Completed]);

            return;
        }

        $anyDelivered = $order->lines->contains(
            fn ($line) => bccomp($line->quantity_delivered, '0', 4) > 0
        );

        if ($anyDelivered) {
            $order->update(['status' => SalesOrderStatus::PartiallyDelivered]);
        }
    }
}
