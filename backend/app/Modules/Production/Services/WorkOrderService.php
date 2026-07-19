<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Exceptions\MissingBomException;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * A work order never touches Inventory's tables directly — material issue
 * and finished-goods receipt both go through StockMovementService, the
 * same as Procurement's GoodsReceiptService does. quantity_completed is
 * decoupled from quantity_planned (the shop floor doesn't always produce
 * exactly what was planned); the finished-goods unit cost is actual
 * consumed material cost divided by whatever quantity was actually
 * completed, not a standard cost.
 */
class WorkOrderService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BomService $boms,
        private readonly BatchService $batches,
    ) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return WorkOrder::query()
            ->with(['item', 'warehouse', 'materials.component'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{item_id: int, bom_id?: int, routing_id?: int, warehouse_id: int, scheduled_date?: string, quantity_planned: string}  $data
     */
    public function create(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data) {
            $bom = isset($data['bom_id'])
                ? Bom::with('lines')->findOrFail($data['bom_id'])
                : $this->boms->activeFor($data['item_id']);

            if (! $bom) {
                throw MissingBomException::forItem($data['item_id']);
            }

            $workOrder = WorkOrder::create([
                'item_id' => $data['item_id'],
                'bom_id' => $bom->id,
                'routing_id' => $data['routing_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'scheduled_date' => $data['scheduled_date'] ?? null,
                'quantity_planned' => $data['quantity_planned'],
                'quantity_completed' => 0,
                'material_cost' => 0,
                'status' => WorkOrderStatus::Draft,
            ]);

            foreach ($bom->lines as $line) {
                $workOrder->materials()->create([
                    'component_item_id' => $line->component_item_id,
                    'quantity_required' => bcmul($line->quantity_per, (string) $data['quantity_planned'], 4),
                ]);
            }

            return $workOrder->load(['item', 'warehouse', 'materials.component']);
        });
    }

    public function release(WorkOrder $workOrder): WorkOrder
    {
        if ($workOrder->status !== WorkOrderStatus::Draft) {
            throw InvalidStatusTransitionException::make('work order', $workOrder->status->value, WorkOrderStatus::Released->value);
        }

        return DB::transaction(function () use ($workOrder) {
            $materialCost = '0.0000';

            foreach ($workOrder->materials as $material) {
                $movement = $this->stock->recordIssue(
                    itemId: $material->component_item_id,
                    warehouseId: $workOrder->warehouse_id,
                    quantity: (string) $material->quantity_required,
                    reference: "WO #{$workOrder->id}",
                );

                $materialCost = bcadd($materialCost, bcmul($material->quantity_required, $movement->unit_cost, 4), 4);
                $material->update(['quantity_issued' => $material->quantity_required]);
            }

            $workOrder->update([
                'status' => WorkOrderStatus::Released,
                'released_at' => now(),
                'material_cost' => $materialCost,
            ]);

            return $workOrder->fresh(['item', 'warehouse', 'materials.component']);
        });
    }

    /**
     * $batchNumber is only meaningful when the finished item is batch-
     * tracked (Item::tracking_type === batch) — when given, a Batch is
     * created for the completed quantity and stamped onto the receipt so
     * this production run's output is traceable as its own lot. Ignored
     * for non-batch-tracked items rather than erroring, since most items
     * won't be batch-tracked and callers shouldn't have to know that.
     */
    public function complete(WorkOrder $workOrder, string $quantityCompleted, ?string $batchNumber = null): WorkOrder
    {
        if ($workOrder->status !== WorkOrderStatus::Released) {
            throw InvalidStatusTransitionException::make('work order', $workOrder->status->value, WorkOrderStatus::Completed->value);
        }

        return DB::transaction(function () use ($workOrder, $quantityCompleted, $batchNumber) {
            $unitCost = bcdiv($workOrder->material_cost, $quantityCompleted, 4);

            $item = Item::findOrFail($workOrder->item_id);
            $batchId = null;
            if ($batchNumber !== null && $item->tracking_type === ItemTrackingType::Batch) {
                $batchId = $this->batches->create([
                    'item_id' => $workOrder->item_id,
                    'batch_number' => $batchNumber,
                    'manufactured_date' => now()->toDateString(),
                ])->id;
            }

            $this->stock->recordReceipt(
                itemId: $workOrder->item_id,
                warehouseId: $workOrder->warehouse_id,
                quantity: $quantityCompleted,
                unitCost: $unitCost,
                reference: "WO #{$workOrder->id}",
                batchId: $batchId,
            );

            $workOrder->update([
                'status' => WorkOrderStatus::Completed,
                'completed_at' => now(),
                'quantity_completed' => $quantityCompleted,
            ]);

            return $workOrder->fresh(['item', 'warehouse', 'materials.component']);
        });
    }
}
