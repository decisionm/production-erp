<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Valuation: moving average cost per item+warehouse (the simpler of the
 * two options — FIFO lot tracking is deliberately out of scope for now,
 * see DEVELOPMENT-PLAN.md Phase 1). stock_movements is an append-only
 * ledger; stock_balances holds the running quantity/average cost derived
 * from it, updated transactionally with a row lock so concurrent
 * movements on the same item+warehouse can't race each other.
 *
 * batchId/serialNumberId are pure traceability tags on the movement, not
 * a valuation dimension — balances stay aggregated at item+warehouse
 * exactly as above regardless of whether a movement is tagged. "Where is
 * batch X" / "where did it go" is answered by querying movements
 * (BatchService::ledger), not by a running per-batch balance — batch-
 * level FIFO/FEFO costing is a genuinely different, bigger feature this
 * doesn't attempt. A serial-tracked item's SerialNumber row is kept in
 * sync as a side effect (its status/warehouse reflects the last movement
 * against it) since, unlike a batch, a serial number's current location
 * is exactly what it's for.
 */
class StockMovementService
{
    public function recordReceipt(
        int $itemId,
        int $warehouseId,
        string $quantity,
        string $unitCost,
        ?string $reference = null,
        ?string $movementDate = null,
        ?string $notes = null,
        ?int $createdBy = null,
        ?int $batchId = null,
        ?int $serialNumberId = null,
    ): StockMovement {
        return DB::transaction(function () use ($itemId, $warehouseId, $quantity, $unitCost, $reference, $movementDate, $notes, $createdBy, $batchId, $serialNumberId) {
            $this->incrementBalance($itemId, $warehouseId, $quantity, $unitCost);

            if ($serialNumberId !== null) {
                SerialNumber::whereKey($serialNumberId)->update([
                    'status' => SerialNumberStatus::InStock,
                    'warehouse_id' => $warehouseId,
                ]);
            }

            return StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'batch_id' => $batchId,
                'serial_number_id' => $serialNumberId,
                'type' => StockMovementType::Receipt,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'reference' => $reference,
                'movement_date' => $movementDate ?? now(),
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);
        });
    }

    public function recordIssue(
        int $itemId,
        int $warehouseId,
        string $quantity,
        ?string $reference = null,
        ?string $movementDate = null,
        ?string $notes = null,
        ?int $createdBy = null,
        ?int $batchId = null,
        ?int $serialNumberId = null,
    ): StockMovement {
        return DB::transaction(function () use ($itemId, $warehouseId, $quantity, $reference, $movementDate, $notes, $createdBy, $batchId, $serialNumberId) {
            $costAtIssue = $this->decrementBalance($itemId, $warehouseId, $quantity);

            if ($serialNumberId !== null) {
                SerialNumber::whereKey($serialNumberId)->update([
                    'status' => SerialNumberStatus::Consumed,
                    'warehouse_id' => null,
                ]);
            }

            return StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'batch_id' => $batchId,
                'serial_number_id' => $serialNumberId,
                'type' => StockMovementType::Issue,
                'quantity' => $quantity,
                'unit_cost' => $costAtIssue,
                'reference' => $reference,
                'movement_date' => $movementDate ?? now(),
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * @return array{0: StockMovement, 1: StockMovement} [transfer_out, transfer_in]
     */
    public function recordTransfer(
        int $itemId,
        int $fromWarehouseId,
        int $toWarehouseId,
        string $quantity,
        ?string $reference = null,
        ?string $movementDate = null,
        ?string $notes = null,
        ?int $createdBy = null,
        ?int $batchId = null,
        ?int $serialNumberId = null,
    ): array {
        return DB::transaction(function () use ($itemId, $fromWarehouseId, $toWarehouseId, $quantity, $reference, $movementDate, $notes, $createdBy, $batchId, $serialNumberId) {
            $costAtTransfer = $this->decrementBalance($itemId, $fromWarehouseId, $quantity);
            $this->incrementBalance($itemId, $toWarehouseId, $quantity, $costAtTransfer);

            if ($serialNumberId !== null) {
                SerialNumber::whereKey($serialNumberId)->update(['warehouse_id' => $toWarehouseId]);
            }

            $transferGroup = (string) Str::uuid();
            $date = $movementDate ?? now();

            $out = StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $fromWarehouseId,
                'batch_id' => $batchId,
                'serial_number_id' => $serialNumberId,
                'type' => StockMovementType::TransferOut,
                'quantity' => $quantity,
                'unit_cost' => $costAtTransfer,
                'reference' => $reference,
                'transfer_group' => $transferGroup,
                'movement_date' => $date,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            $in = StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $toWarehouseId,
                'batch_id' => $batchId,
                'serial_number_id' => $serialNumberId,
                'type' => StockMovementType::TransferIn,
                'quantity' => $quantity,
                'unit_cost' => $costAtTransfer,
                'reference' => $reference,
                'transfer_group' => $transferGroup,
                'movement_date' => $date,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            return [$out, $in];
        });
    }

    public function paginateBalances(int $perPage = 20): LengthAwarePaginator
    {
        return StockBalance::query()
            ->with(['item', 'warehouse'])
            ->orderBy('item_id')
            ->paginate($perPage);
    }

    /**
     * Items whose total on-hand quantity (summed across warehouses) has
     * fallen below their reorder_level. Items with reorder_level = 0 (the
     * default) are never flagged — that's "no threshold set", not "reorder
     * at zero".
     */
    public function lowStockCount(): int
    {
        return Item::query()
            ->where('reorder_level', '>', 0)
            ->whereRaw(
                'reorder_level > (select coalesce(sum(quantity), 0) from stock_balances where stock_balances.item_id = items.id)'
            )
            ->count();
    }

    /**
     * Total on-hand quantity for an item across all warehouses — for other
     * modules that need a single net figure (e.g. Production's MRP net
     * requirements) without caring about warehouse-level detail.
     */
    public function totalOnHand(int $itemId): string
    {
        return (string) StockBalance::query()
            ->where('item_id', $itemId)
            ->get()
            ->reduce(fn (string $carry, StockBalance $balance) => bcadd($carry, $balance->quantity, 4), '0.0000');
    }

    /**
     * The current moving-average cost for an item at a specific warehouse —
     * for callers that need to stamp a receipt with a reasonable cost
     * without themselves knowing/tracking it (e.g. shift production entries,
     * which don't run a costed BOM consumption like a Work Order does).
     * '0.0000' for a combination that's never had a balance row yet, same
     * as any first-ever movement.
     */
    public function currentAverageCost(int $itemId, int $warehouseId): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->first()?->average_cost ?? '0.0000');
    }

    public function paginateMovements(?int $itemId = null, ?int $warehouseId = null, int $perPage = 20): LengthAwarePaginator
    {
        return StockMovement::query()
            ->with(['item', 'warehouse'])
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function incrementBalance(int $itemId, int $warehouseId, string $quantity, string $unitCost): string
    {
        $balance = $this->lockBalance($itemId, $warehouseId);

        $newQuantity = bcadd($balance->quantity, $quantity, 4);
        $newAverageCost = bccomp($newQuantity, '0', 4) > 0
            ? bcdiv(
                bcadd(bcmul($balance->quantity, $balance->average_cost, 4), bcmul($quantity, $unitCost, 4), 4),
                $newQuantity,
                4
            )
            : '0.0000';

        $balance->update(['quantity' => $newQuantity, 'average_cost' => $newAverageCost]);

        return $newAverageCost;
    }

    /**
     * @return string the average cost at the moment of decrement, for the caller to stamp onto the movement
     */
    private function decrementBalance(int $itemId, int $warehouseId, string $quantity): string
    {
        $balance = $this->lockBalance($itemId, $warehouseId);

        if (bccomp($balance->quantity, $quantity, 4) < 0) {
            throw InsufficientStockException::forItem($itemId, $warehouseId, $balance->quantity, $quantity);
        }

        $costAtDecrement = $balance->average_cost;
        $balance->update(['quantity' => bcsub($balance->quantity, $quantity, 4)]);

        return $costAtDecrement;
    }

    private function lockBalance(int $itemId, int $warehouseId): StockBalance
    {
        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        return $balance ?? StockBalance::create([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'quantity' => '0.0000',
            'average_cost' => '0.0000',
        ]);
    }
}
