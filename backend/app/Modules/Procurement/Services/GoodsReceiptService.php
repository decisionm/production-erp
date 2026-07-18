<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Procurement\Exceptions\OverReceiptException;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Posting a GRN is the one place Procurement actually moves stock. It never
 * touches Inventory's tables directly — it goes through StockMovementService,
 * the same as any other caller of that module, so Inventory's valuation and
 * balance-locking logic stays in one place.
 */
class GoodsReceiptService
{
    public function __construct(private readonly StockMovementService $stock) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return GoodsReceiptNote::query()
            ->with(['lines.item', 'warehouse', 'purchaseOrder.vendor'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{purchase_order_id: int, warehouse_id: int, reference?: string, received_date?: string, notes?: string, lines: array<int, array{purchase_order_line_id: int, quantity: string, unit_cost?: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): GoodsReceiptNote
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $order = PurchaseOrder::with('lines')->findOrFail($data['purchase_order_id']);

            if (! in_array($order->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw InvalidStatusTransitionException::make('purchase order', $order->status->value, 'received');
            }

            $grn = GoodsReceiptNote::create([
                'purchase_order_id' => $order->id,
                'warehouse_id' => $data['warehouse_id'],
                'reference' => $data['reference'] ?? null,
                'received_date' => $data['received_date'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            foreach ($data['lines'] as $lineData) {
                // Form request validation ties purchase_order_line_id to this
                // purchase_order_id, so a miss here means a genuine bug, not
                // a normal user error — let it fail loudly.
                $poLine = $order->lines->firstWhere('id', $lineData['purchase_order_line_id']);

                $remaining = bcsub($poLine->quantity, $poLine->quantity_received, 4);
                if (bccomp((string) $lineData['quantity'], $remaining, 4) > 0) {
                    throw OverReceiptException::forLine($poLine->id, $remaining, (string) $lineData['quantity']);
                }

                $unitCost = (string) ($lineData['unit_cost'] ?? $poLine->unit_price);

                $grn->lines()->create([
                    'purchase_order_line_id' => $poLine->id,
                    'item_id' => $poLine->item_id,
                    'quantity' => $lineData['quantity'],
                    'unit_cost' => $unitCost,
                ]);

                $this->stock->recordReceipt(
                    itemId: $poLine->item_id,
                    warehouseId: $data['warehouse_id'],
                    quantity: (string) $lineData['quantity'],
                    unitCost: $unitCost,
                    reference: $data['reference'] ?? "GRN for PO #{$order->id}",
                    movementDate: $data['received_date'] ?? null,
                    notes: $data['notes'] ?? null,
                    createdBy: $createdBy,
                );

                $poLine->increment('quantity_received', $lineData['quantity']);
            }

            $this->recomputeOrderStatus($order->fresh('lines'));

            return $grn->load(['lines.item', 'warehouse', 'purchaseOrder']);
        });
    }

    private function recomputeOrderStatus(PurchaseOrder $order): void
    {
        $fullyReceived = $order->lines->every(
            fn ($line) => bccomp($line->quantity_received, $line->quantity, 4) >= 0
        );

        if ($fullyReceived) {
            $order->update(['status' => PurchaseOrderStatus::Closed]);

            return;
        }

        $anyReceived = $order->lines->contains(
            fn ($line) => bccomp($line->quantity_received, '0', 4) > 0
        );

        if ($anyReceived) {
            $order->update(['status' => PurchaseOrderStatus::PartiallyReceived]);
        }
    }
}
