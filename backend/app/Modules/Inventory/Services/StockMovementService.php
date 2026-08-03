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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    /**
     * $allowNegative is OPT-IN PER CALL, deliberately not a global setting on
     * this service: exactly one caller passes it (a shift's completion
     * consumption, and therefore handover), and every other issue — work
     * orders, rework, subcontract, deliveries, maintenance — keeps the hard
     * refusal it has always had. A service-wide switch would have flipped
     * all of them at once, which is a different and much larger decision
     * than the one the completion incident actually calls for.
     *
     * When it is on and the recorded balance cannot cover the issue, the
     * issue happens anyway, the balance goes negative, and $shortfallKg
     * comes back as the amount issued beyond what was recorded (null when
     * there was enough) so the caller can write that fact down. It is
     * by-reference rather than part of the return value because the return
     * type is load-bearing for five other callers that read
     * $movement->unit_cost off it.
     */
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
        bool $allowNegative = false,
        ?string &$shortfallKg = null,
    ): StockMovement {
        $shortfallKg = null;

        return DB::transaction(function () use ($itemId, $warehouseId, $quantity, $reference, $movementDate, $notes, $createdBy, $batchId, $serialNumberId, $allowNegative, &$shortfallKg) {
            [$costAtIssue, $shortfallKg] = $this->decrementBalance($itemId, $warehouseId, $quantity, $allowNegative);

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
        // A TRANSFER TO THE PLACE IT ALREADY IS, IS NOT A TRANSFER.
        //
        // Defensive, and it earns its place: under one accounting godown every
        // "from" and "to" in this factory is the same warehouse. A caller that
        // slipped through would decrement and re-increment one balance and mint
        // a paired movement — total stock unchanged, but every report counting
        // transfers double-counts, and the ledger fills with moves that never
        // happened. Refused loudly rather than silently absorbed, because a
        // caller asking for this has a bug worth seeing.
        if ($fromWarehouseId === $toWarehouseId) {
            throw ValidationException::withMessages([
                'warehouse' => 'A transfer must move stock between two different warehouses.',
            ]);
        }

        return DB::transaction(function () use ($itemId, $fromWarehouseId, $toWarehouseId, $quantity, $reference, $movementDate, $notes, $createdBy, $batchId, $serialNumberId) {
            // A transfer never runs negative: moving material you do not
            // have is not a truth about the floor, it is a typo.
            [$costAtTransfer] = $this->decrementBalance($itemId, $fromWarehouseId, $quantity);
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
     * Every material currently held at ONE warehouse, item-name ordered — the
     * "what is in this location right now" read. Unpaginated on purpose: the
     * callers are single-location screens (the factory day bin), where a
     * page-1-of-N answer would understate the balance a supervisor is about
     * to consume against.
     *
     * @return Collection<int, StockBalance>
     */
    public function balancesForWarehouse(int $warehouseId): Collection
    {
        return StockBalance::query()
            ->with('item')
            ->where('warehouse_id', $warehouseId)
            ->join('items', 'items.id', '=', 'stock_balances.item_id')
            ->orderBy('items.name')
            ->select('stock_balances.*')
            ->get();
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

    /**
     * Every ISSUE movement stamped with one reference (e.g. "SPE #12") —
     * the cross-module read Production uses to price a completed batch's
     * consumption lines off the unit_cost each issue recorded at the moment
     * it happened. The reference alone is NOT unique (the same entry's FG
     * receipt shares it), so callers match further by item/warehouse.
     *
     * @return Collection<int, StockMovement>
     */
    public function issuesForReference(string $reference): Collection
    {
        return StockMovement::query()
            ->where('reference', $reference)
            ->where('type', StockMovementType::Issue)
            ->orderBy('id')
            ->get();
    }

    /**
     * Every transfer INTO one warehouse on one calendar date, newest first,
     * with item and the acting user loaded — the "today's loads into the
     * factory day bin" read. Filtered on type+warehouse+date, never on the
     * reference string: manual transfer forms may leave reference empty.
     *
     * @return Collection<int, StockMovement>
     */
    public function transfersIntoWarehouseOn(int $warehouseId, string $date): Collection
    {
        return StockMovement::query()
            ->with(['item', 'createdBy'])
            ->where('warehouse_id', $warehouseId)
            ->where('type', StockMovementType::TransferIn)
            ->whereDate('movement_date', $date)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get();
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
     * The shortfall is measured HERE, under the same row lock that does the
     * decrement — a caller reading the balance first would be reading it
     * without a lock, and could report a gap that a concurrent movement had
     * already changed.
     *
     * bcsub(requested, balance) rather than "how far below zero we ended"
     * so a bin that was ALREADY negative reports the whole new gap, which
     * is the figure the accountant has to make good.
     *
     * @return array{0: string, 1: ?string} [average cost at the moment of
     *                                      decrement, for the caller to stamp
     *                                      onto the movement; kg issued beyond
     *                                      the recorded balance, or null]
     */
    private function decrementBalance(int $itemId, int $warehouseId, string $quantity, bool $allowNegative = false): array
    {
        $balance = $this->lockBalance($itemId, $warehouseId);

        $shortfall = null;
        if (bccomp($balance->quantity, $quantity, 4) < 0) {
            if (! $allowNegative) {
                throw InsufficientStockException::forItem($itemId, $warehouseId, $balance->quantity, $quantity);
            }

            $shortfall = bcsub($quantity, $balance->quantity, 4);
        }

        $costAtDecrement = $balance->average_cost;
        $balance->update(['quantity' => bcsub($balance->quantity, $quantity, 4)]);

        return [$costAtDecrement, $shortfall];
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
