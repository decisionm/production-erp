<?php

namespace App\Modules\Sales\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\FinishedCarton;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Exceptions\OverDeliveryException;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posting a Delivery is the one place Sales actually moves stock. It never
 * touches Inventory's tables directly — it goes through StockMovementService,
 * the same as any other caller of that module, so Inventory's valuation and
 * balance-locking logic stays in one place.
 */
class DeliveryService
{
    public function __construct(private readonly StockMovementService $stock) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Delivery::query()
            ->with(['lines.item', 'warehouse', 'salesOrder.customer'])
            ->orderByDesc('id')
            ->paginate($perPage);
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
            $order = SalesOrder::with('lines')->findOrFail($data['sales_order_id']);

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
                );

                $soLine->increment('quantity_delivered', $lineData['quantity']);
            }

            $this->recomputeOrderStatus($order->fresh('lines'));

            // TallySync listens and enqueues a Delivery Note voucher; Sales
            // stays unaware. In-transaction so the queued voucher is atomic
            // with the dispatch.
            event(new DeliveryDispatched($delivery));

            return $delivery->load(['lines.item', 'warehouse', 'salesOrder']);
        });
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
