<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Fast shop-floor capture: which machine, which shift, what came off it —
 * logged in seconds instead of the next morning. Deliberately lighter than
 * WorkOrder: no BOM/release/complete lifecycle, no per-entry costed
 * material consumption. It receives the produced quantity into stock the
 * same way WorkOrder::complete() does (through StockMovementService, never
 * touching Inventory's tables directly), stamped at the item's current
 * moving-average cost since there's no costed BOM consumption here to
 * derive a more precise figure from — see TALLY-PRODUCTION-SYNC-PLAN.md
 * §4 for the reasoning (this is the "Option A" tradeoff: speed of entry
 * over per-entry costing precision).
 */
class ShiftProductionEntryService
{
    public function __construct(private readonly StockMovementService $stock) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return ShiftProductionEntry::query()
            ->with(['shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator'])
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{shift_id: int, work_center_id: int, item_id: int, warehouse_id: int, production_date?: string, quantity_produced: string, quantity_scrap?: string, scrap_reason_id?: int, operator_id?: int, notes?: string}  $data
     */
    public function create(array $data, ?int $createdBy): ShiftProductionEntry
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $entry = ShiftProductionEntry::create([
                'shift_id' => $data['shift_id'],
                'work_center_id' => $data['work_center_id'],
                'item_id' => $data['item_id'],
                'warehouse_id' => $data['warehouse_id'],
                'production_date' => $data['production_date'] ?? now()->toDateString(),
                'quantity_produced' => $data['quantity_produced'],
                'quantity_scrap' => $data['quantity_scrap'] ?? '0',
                'scrap_reason_id' => $data['scrap_reason_id'] ?? null,
                'operator_id' => $data['operator_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            $unitCost = $this->stock->currentAverageCost($data['item_id'], $data['warehouse_id']);

            $this->stock->recordReceipt(
                itemId: $data['item_id'],
                warehouseId: $data['warehouse_id'],
                quantity: (string) $data['quantity_produced'],
                unitCost: $unitCost,
                reference: "SPE #{$entry->id}",
                createdBy: $createdBy,
            );

            return $entry->fresh(['shift', 'workCenter', 'item', 'warehouse', 'scrapReason', 'operator']);
        });
    }
}
