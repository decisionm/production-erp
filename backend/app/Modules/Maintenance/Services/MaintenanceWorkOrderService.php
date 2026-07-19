<?php

namespace App\Modules\Maintenance\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Maintenance\Exceptions\MaintenanceWorkOrderClosedException;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Spare parts consumption never touches Inventory's tables directly — it
 * goes through StockMovementService, the same pattern Production's work
 * orders use. Cancellation is only allowed from "open" (before any parts
 * are issued or work has started), which sidesteps having to reverse
 * stock movements for an in-progress job.
 */
class MaintenanceWorkOrderService
{
    public function __construct(private readonly StockMovementService $stock) {}

    public function paginate(?int $assetId, int $perPage = 20): LengthAwarePaginator
    {
        return MaintenanceWorkOrder::query()
            ->when($assetId, fn ($query) => $query->where('asset_id', $assetId))
            ->with(['asset', 'assignee', 'parts.item', 'parts.warehouse'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{asset_id: int, type: string, description?: string, reported_date?: string, assigned_to?: int}  $data
     */
    public function create(array $data): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'asset_id' => $data['asset_id'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'reported_date' => $data['reported_date'] ?? now()->toDateString(),
            'assigned_to' => $data['assigned_to'] ?? null,
            'status' => MaintenanceWorkOrderStatus::Open,
            'labor_cost' => 0,
            'parts_cost' => 0,
            'total_cost' => 0,
        ])->load(['asset', 'assignee']);
    }

    public function createForSchedule(MaintenanceSchedule $schedule): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'asset_id' => $schedule->asset_id,
            'maintenance_schedule_id' => $schedule->id,
            'type' => MaintenanceWorkOrderType::Preventive,
            'status' => MaintenanceWorkOrderStatus::Open,
            'reported_date' => now()->toDateString(),
            'labor_cost' => 0,
            'parts_cost' => 0,
            'total_cost' => 0,
        ])->load(['asset', 'assignee']);
    }

    public function addPart(MaintenanceWorkOrder $workOrder, int $itemId, int $warehouseId, string $quantity): MaintenanceWorkOrder
    {
        if (! in_array($workOrder->status, [MaintenanceWorkOrderStatus::Open, MaintenanceWorkOrderStatus::InProgress], true)) {
            throw MaintenanceWorkOrderClosedException::forWorkOrder($workOrder->id, $workOrder->status->value);
        }

        return DB::transaction(function () use ($workOrder, $itemId, $warehouseId, $quantity) {
            $movement = $this->stock->recordIssue(
                itemId: $itemId,
                warehouseId: $warehouseId,
                quantity: $quantity,
                reference: "MWO #{$workOrder->id}",
            );

            $workOrder->parts()->create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'unit_cost' => $movement->unit_cost,
            ]);

            $newPartsCost = bcadd($workOrder->parts_cost, bcmul($quantity, $movement->unit_cost, 4), 4);

            $workOrder->update([
                'parts_cost' => $newPartsCost,
                'total_cost' => bcadd($newPartsCost, $workOrder->labor_cost, 4),
            ]);

            return $workOrder->fresh(['asset', 'assignee', 'parts.item', 'parts.warehouse']);
        });
    }

    public function start(MaintenanceWorkOrder $workOrder): MaintenanceWorkOrder
    {
        if ($workOrder->status !== MaintenanceWorkOrderStatus::Open) {
            throw InvalidStatusTransitionException::make(
                'maintenance work order',
                $workOrder->status->value,
                MaintenanceWorkOrderStatus::InProgress->value,
            );
        }

        $workOrder->update(['status' => MaintenanceWorkOrderStatus::InProgress, 'started_at' => now()]);

        return $workOrder;
    }

    public function complete(MaintenanceWorkOrder $workOrder, string $laborCost = '0'): MaintenanceWorkOrder
    {
        if ($workOrder->status !== MaintenanceWorkOrderStatus::InProgress) {
            throw InvalidStatusTransitionException::make(
                'maintenance work order',
                $workOrder->status->value,
                MaintenanceWorkOrderStatus::Completed->value,
            );
        }

        $workOrder->update([
            'status' => MaintenanceWorkOrderStatus::Completed,
            'completed_at' => now(),
            'labor_cost' => $laborCost,
            'total_cost' => bcadd($workOrder->parts_cost, $laborCost, 4),
        ]);

        return $workOrder;
    }

    public function cancel(MaintenanceWorkOrder $workOrder): MaintenanceWorkOrder
    {
        if ($workOrder->status !== MaintenanceWorkOrderStatus::Open) {
            throw InvalidStatusTransitionException::make(
                'maintenance work order',
                $workOrder->status->value,
                MaintenanceWorkOrderStatus::Cancelled->value,
            );
        }

        $workOrder->update(['status' => MaintenanceWorkOrderStatus::Cancelled]);

        return $workOrder;
    }
}
