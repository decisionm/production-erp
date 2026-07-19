<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\ReworkOrderStatus;
use App\Modules\Production\Models\ReworkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Rework recovers defective output back to good stock rather than
 * discarding it — structurally the same shape as WorkOrder (create()
 * optionally snapshots a BOM into required quantities, release() issues
 * them, complete() receives the recovered good output) with two real
 * differences: the BOM is optional (a pure re-inspection/relabeling
 * rework needs no extra materials at all), and quantity_input (how many
 * defective units are being reworked) is purely informational — unlike a
 * normal work order's raw materials, the defective units going in were
 * never themselves tracked as inventory (see WorkOrderService's
 * yield-loss note), so there's no stock to consume for them.
 */
class ReworkOrderService
{
    public function __construct(
        private readonly StockMovementService $stock,
    ) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return ReworkOrder::query()
            ->with(['item', 'warehouse', 'sourceWorkOrder', 'materials.component'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{item_id: int, source_work_order_id?: int, bom_id?: int, warehouse_id: int, quantity_input: string}  $data
     */
    public function create(array $data): ReworkOrder
    {
        return DB::transaction(function () use ($data) {
            $order = ReworkOrder::create([
                'item_id' => $data['item_id'],
                'source_work_order_id' => $data['source_work_order_id'] ?? null,
                'bom_id' => $data['bom_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'quantity_input' => $data['quantity_input'],
                'quantity_recovered' => 0,
                'material_cost' => 0,
                'labor_cost' => 0,
                'total_cost' => 0,
                'status' => ReworkOrderStatus::Draft,
            ]);

            if (isset($data['bom_id'])) {
                $bom = Bom::with('lines')->findOrFail($data['bom_id']);
                foreach ($bom->lines as $line) {
                    $order->materials()->create([
                        'component_item_id' => $line->component_item_id,
                        'quantity_required' => bcmul($line->quantity_per, (string) $data['quantity_input'], 4),
                    ]);
                }
            }

            return $order->load(['item', 'warehouse', 'sourceWorkOrder', 'materials.component']);
        });
    }

    public function release(ReworkOrder $order): ReworkOrder
    {
        if ($order->status !== ReworkOrderStatus::Draft) {
            throw InvalidStatusTransitionException::make('rework order', $order->status->value, ReworkOrderStatus::Released->value);
        }

        return DB::transaction(function () use ($order) {
            $materialCost = '0.0000';

            foreach ($order->materials as $material) {
                $movement = $this->stock->recordIssue(
                    itemId: $material->component_item_id,
                    warehouseId: $order->warehouse_id,
                    quantity: (string) $material->quantity_required,
                    reference: "RWO #{$order->id}",
                );

                $materialCost = bcadd($materialCost, bcmul($material->quantity_required, $movement->unit_cost, 4), 4);
                $material->update(['quantity_issued' => $material->quantity_required]);
            }

            $order->update([
                'status' => ReworkOrderStatus::Released,
                'released_at' => now(),
                'material_cost' => $materialCost,
                'total_cost' => bcadd($materialCost, $order->labor_cost, 4),
            ]);

            return $order->fresh(['item', 'warehouse', 'sourceWorkOrder', 'materials.component']);
        });
    }

    public function complete(ReworkOrder $order, string $quantityRecovered, string $laborCost): ReworkOrder
    {
        if ($order->status !== ReworkOrderStatus::Released) {
            throw InvalidStatusTransitionException::make('rework order', $order->status->value, ReworkOrderStatus::Completed->value);
        }

        return DB::transaction(function () use ($order, $quantityRecovered, $laborCost) {
            $totalCost = bcadd($order->material_cost, $laborCost, 4);
            $unitCost = bcdiv($totalCost, $quantityRecovered, 4);

            $this->stock->recordReceipt(
                itemId: $order->item_id,
                warehouseId: $order->warehouse_id,
                quantity: $quantityRecovered,
                unitCost: $unitCost,
                reference: "RWO #{$order->id}",
            );

            $order->update([
                'status' => ReworkOrderStatus::Completed,
                'completed_at' => now(),
                'quantity_recovered' => $quantityRecovered,
                'labor_cost' => $laborCost,
                'total_cost' => $totalCost,
            ]);

            return $order->fresh(['item', 'warehouse', 'sourceWorkOrder', 'materials.component']);
        });
    }
}
