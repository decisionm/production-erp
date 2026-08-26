<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\IncomingQcHoldException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
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
 *
 * $purpose (StockMovementPurpose) is WHY the movement happened, beside the
 * type that says which way it went. OPTIONAL on every writer, and null means
 * 'unknown' — never a guess — so every caller that predates the column keeps
 * writing exactly what it wrote before, plus one honest word. The writers
 * that know their intent name it: a GRN says receipt, a batch says
 * consumption and output, a delivery says dispatch, the Tally opening and
 * reconcile services say opening and reconcile.
 */
class StockMovementService
{
    public function __construct(private readonly IncomingQcHold $qcHold) {}

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
        ?StockMovementPurpose $purpose = null,
    ): StockMovement {
        $this->assertIdentityBelongsToItem($itemId, $batchId, $serialNumberId);

        return DB::transaction(function () use ($itemId, $warehouseId, $quantity, $unitCost, $reference, $movementDate, $notes, $createdBy, $batchId, $serialNumberId, $purpose) {
            $this->lockSerial($serialNumberId);
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
                'purpose' => $purpose ?? StockMovementPurpose::Unknown,
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
     *
     * IT DOES NOT REACH PAST AN INCOMING-QC HOLD, and that is deliberate —
     * see decrementBalance(). Material still waiting for QC has not been
     * consumed by anyone, so there is nothing about it to write down yet.
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
        ?StockMovementPurpose $purpose = null,
    ): StockMovement {
        return $this->writeIssue(
            $itemId, $warehouseId, $quantity, $reference, $movementDate, $notes,
            $createdBy, $batchId, $serialNumberId, $allowNegative, $shortfallKg, $purpose,
            honourIncomingQcHold: true,
        );
    }

    /**
     * THE ONE ISSUE THAT MAY TAKE HELD KILOGRAMS OFF A BALANCE — quality
     * rejecting an arrival, and nothing else.
     *
     * A separate, narrowly named door rather than a flag on recordIssue():
     * a boolean parameter is something a future caller can pass, and
     * eventually something a payload can reach. This one names its single
     * purpose, and the grep for its callers is the whole audit.
     *
     * It skips the hold check for TWO reasons, and the second is the
     * load-bearing one:
     *
     *  1. Arithmetic. IncomingInspectionService flips the rejected bags to
     *     `rejected_qc` BEFORE it calls this, so those kilograms are already
     *     out of the hold — but a store whose balance has previously been
     *     driven below its bag total would still refuse a genuine rejection,
     *     and a rejection is quality telling the truth about material that
     *     has already failed. It must not be blocked by a stock-record
     *     problem the QC desk cannot fix.
     *  2. LOCK ORDER. dispositionBags() holds a lock on the bags of ONE GRN
     *     line and then issues. Were that issue to take the item-wide
     *     `waiting_qc` lock this class's guard takes, two inspections
     *     running on two GRN lines of the same material would each hold
     *     bags the other is waiting for — a deadlock cycle. Taking NO bag
     *     lock here removes the cycle; the balance lock, which both still
     *     take, is enough to keep the two arithmetics apart.
     *
     * It is not a bypass in any useful sense: the quantity is not chosen by
     * anyone, it is the summed remaining_kg of the bags the inspection has
     * just rejected whole.
     */
    public function recordIncomingQcRejectionIssue(
        int $itemId,
        int $warehouseId,
        string $quantity,
        ?string $reference = null,
        ?string $movementDate = null,
        ?string $notes = null,
        ?int $createdBy = null,
    ): StockMovement {
        $ignored = null;

        return $this->writeIssue(
            $itemId, $warehouseId, $quantity, $reference, $movementDate, $notes,
            $createdBy, null, null, false, $ignored, null,
            honourIncomingQcHold: false,
        );
    }

    private function writeIssue(
        int $itemId,
        int $warehouseId,
        string $quantity,
        ?string $reference,
        ?string $movementDate,
        ?string $notes,
        ?int $createdBy,
        ?int $batchId,
        ?int $serialNumberId,
        bool $allowNegative,
        ?string &$shortfallKg,
        ?StockMovementPurpose $purpose,
        bool $honourIncomingQcHold,
    ): StockMovement {
        $this->assertIdentityBelongsToItem($itemId, $batchId, $serialNumberId);

        $shortfallKg = null;

        return DB::transaction(function () use ($itemId, $warehouseId, $quantity, $reference, $movementDate, $notes, $createdBy, $batchId, $serialNumberId, $allowNegative, &$shortfallKg, $purpose, $honourIncomingQcHold) {
            $this->lockSerial($serialNumberId);
            [$costAtIssue, $shortfallKg] = $this->decrementBalance($itemId, $warehouseId, $quantity, $allowNegative, $honourIncomingQcHold);

            if ($serialNumberId !== null) {
                $this->consumeSerial($serialNumberId);
            }

            return StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'batch_id' => $batchId,
                'serial_number_id' => $serialNumberId,
                'type' => StockMovementType::Issue,
                'purpose' => $purpose ?? StockMovementPurpose::Unknown,
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
        ?StockMovementPurpose $purpose = null,
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

        $this->assertIdentityBelongsToItem($itemId, $batchId, $serialNumberId);

        return DB::transaction(function () use ($itemId, $fromWarehouseId, $toWarehouseId, $quantity, $reference, $movementDate, $notes, $createdBy, $batchId, $serialNumberId, $purpose) {
            // The unit first, before either balance — see lockSerial().
            $this->lockSerial($serialNumberId);

            // A transfer never runs negative: moving material you do not
            // have is not a truth about the floor, it is a typo. Nor may it
            // move kilograms the source store is holding for incoming QC —
            // otherwise the hold is escaped by relocating the balance to a
            // store the bags are not in (they never move). decrementBalance()
            // refuses both.
            [$costAtTransfer] = $this->decrementBalance($itemId, $fromWarehouseId, $quantity);
            $this->incrementBalance($itemId, $toWarehouseId, $quantity, $costAtTransfer);

            if ($serialNumberId !== null) {
                $this->relocateSerial($serialNumberId, $fromWarehouseId, $toWarehouseId);
            }

            $transferGroup = (string) Str::uuid();
            $date = $movementDate ?? now();

            $out = StockMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $fromWarehouseId,
                'batch_id' => $batchId,
                'serial_number_id' => $serialNumberId,
                'type' => StockMovementType::TransferOut,
                'purpose' => $purpose ?? StockMovementPurpose::Unknown,
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
                'purpose' => $purpose ?? StockMovementPurpose::Unknown,
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

    /**
     * ONE ITEM×WAREHOUSE BALANCE PER ROW, PAGED HONESTLY.
     *
     * `$search` matches the item's SKU or name, or the warehouse's code or
     * name — the four things a store user reads off the row. It reaches
     * ARCHIVED and soft-deleted items on purpose (`withTrashed`): the stock is
     * still standing there whatever the master's state, and a balance that
     * cannot be found is a balance nobody reconciles.
     *
     * THE EAGER LOAD NEEDS `withTrashed` TOO, and this is not the same clause
     * twice. `whereHas` decides which ROWS come back; the eager load is a
     * separate query with its own SoftDeletes scope, so a balance of a deleted
     * item came back on the list with `item: null` — found by a search for the
     * very SKU that is then not on screen. Every read below that names an item
     * carries the same clause for the same reason.
     *
     * THE SECOND ORDER-BY IS NOT DECORATION. `order by item_id` alone ties for
     * an item held in two stores, and a tie is not a total order: the database
     * may hand back those rows in either order per query, so walking page 1
     * then page 2 could serve one balance twice and skip another entirely.
     */
    public function paginateBalances(int $perPage = 20, ?string $search = null, ?int $itemId = null): LengthAwarePaginator
    {
        return StockBalance::query()
            ->with(['item' => fn ($item) => $item->withTrashed(), 'warehouse'])
            ->when($itemId !== null, fn ($query) => $query->where('item_id', $itemId))
            ->when($search !== null, fn ($query) => $query->where(function ($outer) use ($search) {
                $like = "%{$search}%";
                $outer
                    ->whereHas('item', fn ($item) => $item->withTrashed()
                        ->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like)))
                    ->orWhereHas('warehouse', fn ($warehouse) => $warehouse
                        ->where(fn ($q) => $q->where('code', 'like', $like)->orWhere('name', 'like', $like)));
            }))
            ->orderBy('item_id')
            ->orderBy('warehouse_id')
            ->paginate($perPage);
    }

    /**
     * Every material currently held at ONE warehouse, item-name ordered — the
     * "what is in this location right now" read. Unpaginated on purpose: the
     * callers are single-location screens (the factory day bin), where a
     * page-1-of-N answer would understate the balance a supervisor is about
     * to consume against.
     *
     * The `join` carries no `deleted_at` clause, so a deleted item's balance
     * is on this list and always was — only its eager-loaded model was null.
     * `withTrashed` on the eager load makes the row readable, it does not add
     * a row.
     *
     * @return Collection<int, StockBalance>
     */
    public function balancesForWarehouse(int $warehouseId): Collection
    {
        return StockBalance::query()
            ->with(['item' => fn ($item) => $item->withTrashed()])
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
     * The movements a caller already knows BY ID, in one query — how
     * Procurement's purchase-order trace reads the ledger row a goods
     * receipt line NAMED (goods_receipt_note_lines.stock_movement_id,
     * Phase 6). Exact by construction: no reference, no narrowing, no
     * chance of another receipt's row. Ids that name no row are simply
     * absent. Additive; reads only.
     *
     * @param  list<int>  $ids
     * @return Collection<int, StockMovement>
     */
    public function byIds(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return new Collection;
        }

        return StockMovement::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * The same read for MANY references at once — one whereIn query, the
     * rows grouped by reference in the same id order issuesForReference()
     * returns them (Phase 7, P7-03 (e)). Production prices a page of
     * completed batches from this: twenty entries used to cost twenty
     * stock_movements reads. A reference with no issue movement is simply
     * absent from the result; the caller treats absence as an empty pool.
     * Additive — issuesForReference() keeps answering exactly as before.
     *
     * @param  list<string>  $references
     * @return array<string, Collection<int, StockMovement>>
     */
    public function issuesForReferences(array $references): array
    {
        $references = array_values(array_unique(array_map('strval', $references)));
        if ($references === []) {
            return [];
        }

        $grouped = [];
        $movements = StockMovement::query()
            ->whereIn('reference', $references)
            ->where('type', StockMovementType::Issue)
            ->orderBy('id')
            ->get();

        foreach ($movements->groupBy('reference') as $reference => $rows) {
            $grouped[(string) $reference] = $rows->values();
        }

        return $grouped;
    }

    /**
     * Every RECEIPT movement stamped with one reference — the cross-module
     * read Procurement's purchase-order trace uses for a receipt line booked
     * BEFORE stock_movement_id existed (Phase 6). The ledger carries no GRN
     * foreign key, so such a line is matched by the reference
     * GoodsReceiptService stamped (the receipt's own `reference`, else
     * "GRN for PO #{id}") and the caller narrows further by item and
     * warehouse. Inexact where two referenceless arrivals share one order —
     * which is why byIds() above is the road every new row takes. Additive;
     * reads only.
     *
     * @return Collection<int, StockMovement>
     */
    public function receiptsForReference(string $reference): Collection
    {
        return StockMovement::query()
            ->where('reference', $reference)
            ->where('type', StockMovementType::Receipt)
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
            ->with(['item' => fn ($item) => $item->withTrashed(), 'createdBy'])
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
            ->with(['item' => fn ($item) => $item->withTrashed(), 'warehouse'])
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * A MOVEMENT NEVER CARRIES ANOTHER ITEM'S IDENTITY — the one tracking rule
     * that belongs below the HTTP layer, because there is no caller for whom
     * it is right.
     *
     * `batch_id` and `serial_number_id` are traceability tags, and the ledger
     * is append-only, so a tag written against the wrong item is permanent:
     * BatchService::ledger derives a batch's whereabouts entirely from the
     * movements tagged with it and would report material the batch never held.
     * The generic API doors could do this with one curl (see
     * TrackingIdentityIntegrityTest); this closes it for every writer, added
     * later or not.
     *
     * The REST of the tracking contract — required-or-forbidden by mode, a
     * serial number's state and its whereabouts — stays in the FormRequests.
     * Those are rules about what a client may ASK FOR, and re-judging them
     * here would re-judge production completion, Tally reconcile and goods
     * receipt, none of which pass identity at all.
     */
    private function assertIdentityBelongsToItem(int $itemId, ?int $batchId, ?int $serialNumberId): void
    {
        // `->exists()` rather than reading item_id back and comparing: the
        // raw value's PHP TYPE depends on the driver (a string under MySQL's
        // emulated prepares, an int under SQLite), and a strict compare
        // against an int would refuse a batch that does belong to the item —
        // on production only, where no test would have seen it.
        if ($batchId !== null && ! Batch::whereKey($batchId)->where('item_id', $itemId)->exists()) {
            throw ValidationException::withMessages([
                'batch_id' => 'That batch belongs to a different item.',
            ]);
        }

        if ($serialNumberId !== null && ! SerialNumber::whereKey($serialNumberId)->where('item_id', $itemId)->exists()) {
            throw ValidationException::withMessages([
                'serial_number_id' => 'That serial number belongs to a different item.',
            ]);
        }
    }

    /**
     * A UNIT LEAVES STOCK ONCE — the second half of the identity invariant,
     * and the half that needs the WRITE ITSELF to be the check.
     *
     * The tracking FormRequests already refuse a serial number that is not in
     * stock, but they judge it BEFORE the transaction opens, so what they
     * prove is that the unit was in stock a moment ago. Nothing re-read the
     * status inside the transaction and the update was unconditional, so two
     * issues of one serial both wrote: two Issue movements, one physical unit,
     * the balance decremented twice. The row lock on the item×warehouse
     * balance serialises them, it does not make the second one true — and it
     * hides the damage entirely whenever the store holds other units of the
     * same item, because then the quantity check has stock to spare.
     *
     * `where('status', ...)` makes the transition itself the test: the
     * database decides who got there first, and the loser is told so instead
     * of silently overwriting. Every OTHER writer in the app — delivery,
     * rework, work orders, subcontract, maintenance, production, Tally
     * reconcile, goods receipt — passes no serial number at all
     * (StockMovementController is the only caller that does), so this is a
     * no-op for them rather than a new gate.
     *
     * RECEIPTS ARE DELIBERATELY NOT GUARDED HERE. Whether a consumed or
     * scrapped unit may come back in is a real question about returns that
     * the factory has not answered, and refusing it in the writer would be
     * answering it. The receipt door refuses only the already-in-stock case,
     * where re-receiving silently teleports the one row.
     */
    private function consumeSerial(int $serialNumberId): void
    {
        $changed = SerialNumber::whereKey($serialNumberId)
            ->where('status', SerialNumberStatus::InStock)
            ->update([
                'status' => SerialNumberStatus::Consumed,
                'warehouse_id' => null,
            ]);

        if ($changed === 0) {
            throw ValidationException::withMessages([
                'serial_number_id' => 'That serial number is not in stock, so it cannot be issued.',
            ]);
        }
    }

    /**
     * A transfer moves a unit that is in stock, OUT OF THE STORE IT IS IN.
     *
     * The source store used to be an HTTP-layer rule only, on the ground that
     * an in-stock unit always has a location. That is true and it is not
     * enough: judgeLeaving reads the location BEFORE the transaction opens, so
     * two transfers of one unit out of RM-STORE both saw it there, and a
     * status-only condition is satisfied by BOTH of them — the unit stays
     * `in_stock` across a transfer, so nothing about the row changed to make
     * the second one false. Result: one physical unit, two transfer-out
     * movements, RM-STORE decremented twice. The balance lock does not catch
     * it, because a store holding other units of the same item has the
     * quantity to spare.
     *
     * Naming the source in the condition is what makes the second write false:
     * the first commits `warehouse_id = FG-STORE`, and the second no longer
     * matches a row that has to be in RM-STORE to leave it. The `whereNull`
     * arm keeps judgeLeaving's exact tolerance — it lets a unit with no
     * recorded location through rather than newly refusing it here.
     */
    private function relocateSerial(int $serialNumberId, int $fromWarehouseId, int $toWarehouseId): void
    {
        $changed = SerialNumber::whereKey($serialNumberId)
            ->where('status', SerialNumberStatus::InStock)
            ->where(fn ($q) => $q->whereNull('warehouse_id')->orWhere('warehouse_id', $fromWarehouseId))
            ->update(['warehouse_id' => $toWarehouseId]);

        if ($changed === 0) {
            // Which of the two conditions failed is the whole answer for the
            // person holding the box: "already issued" and "already moved
            // somewhere else" are different facts about the same barcode.
            $stillInStock = SerialNumber::whereKey($serialNumberId)
                ->where('status', SerialNumberStatus::InStock)
                ->exists();

            throw ValidationException::withMessages([
                'serial_number_id' => $stillInStock
                    ? 'That serial number has already left that store, so it cannot be transferred out of it.'
                    : 'That serial number is not in stock, so it cannot be transferred.',
            ]);
        }
    }

    /**
     * THE OUTERMOST LOCK OF EVERY WRITE THAT NAMES A UNIT.
     *
     * A conditional update alone decides a race only where the condition
     * actually changes; taking the row first is what serialises two requests
     * that both passed validation a moment apart, and what makes the loser's
     * re-read see the winner's committed state rather than the state it
     * validated against. `lockForUpdate` is a no-op on SQLite, which is why
     * every guard downstream is ALSO a conditional update checked by affected
     * rows — the refusal is provable in the test suite, and serialised for
     * real under MySQL.
     *
     * IT IS TAKEN BEFORE THE QC BAGS AND BEFORE THE BALANCE, in all three
     * writers including the receipt, and the receipt is the point. Were the
     * receipt to keep taking the balance first and reach the serial row only
     * at its update, a receipt and an issue of the same unit would hold each
     * other's next lock — a real cycle. The receipt's lock adds no refusal
     * and so decides nothing about returns (see consumeSerial); it only fixes
     * the order.
     */
    private function lockSerial(?int $serialNumberId): void
    {
        if ($serialNumberId === null) {
            return;
        }

        SerialNumber::whereKey($serialNumberId)->lockForUpdate()->first();
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
    private function decrementBalance(
        int $itemId,
        int $warehouseId,
        string $quantity,
        bool $allowNegative = false,
        bool $honourIncomingQcHold = true,
    ): array {
        // THE ARRIVAL HOLD, ENFORCED AT THE BALANCE ITSELF.
        //
        // Every outflow in this system ends here: recordIssue() decrements,
        // and recordTransfer() decrements the source before it increments
        // the destination. So this one function is where "material waiting
        // for incoming QC may not leave the store" can be true for ALL of
        // them at once — the typed store-issue line, the generic
        // /stock-movements/issues and /transfers writers, a shift
        // completion's material consumption, a work order, rework,
        // subcontract, a delivery, maintenance, and anything added later
        // that reaches for stock without knowing this rule exists.
        //
        // Guarding it one door at a time was tried and failed: closing the
        // typed line left the generic issue open, and closing both left a
        // TRANSFER able to move held kilograms into a second store where the
        // bags — which never move — no longer count against them. Laundering
        // by relocation, with the material never inspected. The hold has to
        // be read where the balance is written, or it is not enforced.
        //
        // AND IT OUTRANKS $allowNegative. That flag exists so a shift's
        // completion is never refused over a bin the ledger has not caught
        // up with (config/production.php 'stock'): the resin WAS consumed,
        // and refusing to write it down does not un-consume it. Held
        // kilograms are the opposite case — the material is standing in the
        // store, uninspected, and has NOT been consumed by anyone. Letting
        // an issue run negative past a hold would write down a consumption
        // that has not happened yet, so above a hold the refusal wins. With
        // no hold (held = 0, which is every item that has no bags) the flag
        // behaves exactly as it always has, to the character.
        //
        // LOCK ORDER, WHOLE: serial row → QC bags → balance.
        //
        // BAGS ARE LOCKED BEFORE THE BALANCE, here and in every caller.
        // IncomingInspectionService::dispositionBags locks its bags and then
        // reaches the balance through its rejection issue; StoreIssueService
        // locks the same bags before its own balance read. Nothing takes
        // them the other way round, so no pair of these can cycle. The one
        // path that must not take the item-wide bag lock at all —
        // a rejection issue, which already holds one GRN line's bags — comes
        // through recordIncomingQcRejectionIssue() and passes false here.
        //
        // AND THE SERIAL ROW IS LOCKED BEFORE BOTH — by lockSerial(), first
        // statement of all three writers' transactions, receipt included, so
        // no writer reaches a serial row while already holding a balance.
        // Callers that pass no serial number simply start one rung down.
        $held = '0.0000';
        $heldBags = 0;

        if ($honourIncomingQcHold) {
            [$held, $heldBags] = $this->qcHold->lockAndSum($itemId, $warehouseId);
        }

        $balance = $this->lockBalance($itemId, $warehouseId);

        if (bccomp($held, '0', 4) === 1) {
            $available = $this->qcHold->available($balance->quantity, $held);

            if (bccomp($quantity, $available, 4) === 1) {
                throw IncomingQcHoldException::forItem($itemId, $warehouseId, $available, $held, $heldBags, $quantity);
            }
        }

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
