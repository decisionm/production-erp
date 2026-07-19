<?php

namespace App\Modules\Sales\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Sales\Exceptions\OverDeliveryException;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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

            return $delivery->load(['lines.item', 'warehouse', 'salesOrder']);
        });
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
