<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Enums\StockMovementType;
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
    ): StockMovement {
        return DB::transaction(function () use ($itemId, $warehouseId, $quantity, $unitCost, $reference, $movementDate, $notes, $createdBy) {
            $this->incrementBalance($itemId, $warehouseId, $quantity, $unitCost);

            return StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
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
    ): StockMovement {
        return DB::transaction(function () use ($itemId, $warehouseId, $quantity, $reference, $movementDate, $notes, $createdBy) {
            $costAtIssue = $this->decrementBalance($itemId, $warehouseId, $quantity);

            return StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
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
    ): array {
        return DB::transaction(function () use ($itemId, $fromWarehouseId, $toWarehouseId, $quantity, $reference, $movementDate, $notes, $createdBy) {
            $costAtTransfer = $this->decrementBalance($itemId, $fromWarehouseId, $quantity);
            $this->incrementBalance($itemId, $toWarehouseId, $quantity, $costAtTransfer);

            $transferGroup = (string) Str::uuid();
            $date = $movementDate ?? now();

            $out = StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $fromWarehouseId,
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
