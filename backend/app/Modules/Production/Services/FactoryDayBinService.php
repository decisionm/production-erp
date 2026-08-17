<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\MaterialBagIssueResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StoreIssueLedgerReader;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE FACTORY'S INTERNAL WIP LOCATION, and the COMMON RESIN INPUT estimate
 * built on top of it.
 *
 * WHAT THE OWNER ACTUALLY RULED (2-Aug, decisive — it supersedes the
 * per-machine model this class carried until now): the factory has ONE COMMON
 * resin input/loading point serving every machine. A bag is NEVER assigned to
 * a machine and never scanned to one. So the machine selector, the
 * per-machine bag history and the per-machine estimated balance were all
 * describing a factory that does not exist, and they are gone rather than
 * left on the screen looking answerable.
 *
 * Loading records MATERIAL ENTERING THE COMMON INPUT. It is not consumption
 * and posts no Tally entry. Each batch still calculates its own consumption
 * from output + rejection + lumps, exactly as before — that arithmetic is
 * untouched. What is reconciled is TOTAL loads into the common input against
 * TOTAL calculated consumption across ALL machines.
 *
 * So there are two separate questions, and this class keeps them apart:
 *
 *  1. WHERE THE STOCK IS, IN THE BOOKS — still one warehouse. Loading is the
 *     existing store → warehouse stock transfer: material changes location,
 *     nothing is consumed, no Tally voucher is posted, and the balance is the
 *     ordinary stock_balances row for (item, warehouse). Consumption reduces
 *     it automatically, because every material line at batch completion
 *     carries its own warehouse_id and issues through the same
 *     StockMovementService::recordIssue every other issue uses. Tally sees ONE
 *     godown; there is deliberately no warehouse per machine.
 *
 *  2. HOW MUCH IS STANDING IN THE COMMON INPUT — one running figure per
 *     material, Σ loads − Σ consumption across every machine
 *     (commonResinEstimate). A Load row is written with NO work_center_id;
 *     the column is nullable and every historical machine-stamped row is left
 *     exactly as it was, because that is the audit record of how the factory
 *     ran under the previous understanding.
 *
 * The class/route names stay 'day bin' because renaming a live API surface
 * mid-freeze costs more than it explains; the BEHAVIOUR is the common input.
 *
 * THE PHYSICAL COUNT IS GONE, and no per-machine bin weighing replaced it.
 * The old reconciliation read compared a derived "expected closing" against a
 * weight somebody walked out and took. The factory does not take that weight.
 *
 * NOT CONFIGURED IS A NORMAL STATE. Until someone names the warehouse,
 * warehouseId() is null and every caller must behave exactly as it did
 * before this feature existed — never a blocked Start or Complete.
 */
class FactoryDayBinService
{
    /**
     * app_settings key. Stored as the warehouse id (int) rather than a name,
     * so renaming the godown in Tally cannot silently unconfigure the bin.
     */
    public const SETTING_KEY = 'production_day_bin_warehouse_id';

    /**
     * Reference prefix a bag-scan load stamps on its stock transfer
     * ("Day bin load — bag {barcode}"). One definition: loadBag() writes it
     * and the today's-loads read parses the barcode back out of it.
     */
    public const BAG_LOAD_REFERENCE_PREFIX = 'Day bin load — bag ';

    public function __construct(
        private readonly AppSettingService $settings,
        private readonly WarehouseService $warehouses,
        private readonly StockMovementService $stock,
        // The ledger a load is recorded in. Same service the legacy
        // per-machine bin-bay path already writes through, so both routes
        // land in one place and the estimate cannot see half the loads.
        private readonly DayBinLedgerService $ledger,
        // THE MONEY SIDE of a load: the bag's own purchase rate folded into
        // the common pool for its material. Kept separate from the ledger
        // row above, which is the KILOGRAM side — see
        // BagCostAllocationService for why the two are deliberately not one
        // mechanism.
        private readonly ResinPoolService $pool,
        private readonly BagLotRateResolver $rates,
        // THE ONE SCANNER. A barcode → bag → lot → kilograms reading, with
        // the incoming-QC and over-pour refusals that belong to it, lifted
        // out of loadBag() unchanged so the store-issue handover cannot grow
        // a second, quietly divergent copy of it (Phase 7.5, WS-B).
        private readonly MaterialBagIssueResolver $bags,
        // THE STORE-ISSUE LEDGER, read only. The common-input estimate's
        // numerator has to include material issued to Production/WIP now
        // that the Day Bin has left the target workflow (Phase 7.5, WS-C).
        private readonly StoreIssueLedgerReader $storeIssues,
    ) {}

    /**
     * The configured day-bin warehouse id, or null when unset. Returns null
     * for a warehouse that has since been deleted: a dangling id must read
     * as "not configured" (the degrade path) rather than as a live location
     * nothing can be issued from.
     */
    public function warehouseId(): ?int
    {
        return $this->warehouse()?->id;
    }

    public function warehouse(): ?Warehouse
    {
        $stored = $this->settings->get(self::SETTING_KEY);

        if (! is_numeric($stored)) {
            return null;
        }

        return $this->warehouses->find((int) $stored);
    }

    /** Name the day-bin warehouse (null clears it, back to today's behaviour). */
    public function setWarehouseId(?int $warehouseId): void
    {
        $this->settings->set(self::SETTING_KEY, $warehouseId);
    }

    /**
     * THE BAG SCAN AT THE COMMON RESIN INPUT. One barcode scan on the Shift
     * Floor moves a bag's kg out of the store warehouse into the internal WIP
     * warehouse and records that the material entered the common input.
     *
     * THERE IS NO MACHINE HERE, AND THERE MUST NOT BE. The owner's correction
     * (2-Aug): the factory has one common resin input point for all machines
     * and a bag is never assigned or scanned to a machine. The parameter is
     * gone from this signature rather than made optional, because an optional
     * machine is an invitation to start writing one again.
     *
     * The scan produces three records, all inside one transaction:
     *
     *   - the stock transfer store → WIP warehouse. Unchanged. In the books
     *     the material is simply somewhere else, and Tally still sees one
     *     godown.
     *   - a day_bin_movements Load row for (item, bag, kg) with a NULL
     *     work_center_id. This is the kilogram record, and it is what
     *     commonResinEstimate() sums.
     *   - the bag's own purchase rate folded into the common resin pool for
     *     its material (ResinPoolService). This is the money record, and it
     *     is what a batch's cost is later drawn against. A lot with no
     *     recorded rate folds into the pool's unpriced kg and does NOT move
     *     its average — see ResinPoolService for why that is the honest
     *     answer rather than a gap to paper over.
     *
     * PO/GRN/LOT IDENTITY, THE PERMANENT BAG BARCODE, THE ORIGINAL RATE, THE
     * QUANTITY AND THE PARTIAL-BAG BALANCE ARE ALL PRESERVED EXACTLY. What
     * was removed is the false claim built on top of them — that a bag stood
     * at a named machine. A full load empties the bag (→ Consumed); a partial
     * load pours off the weighed kg and leaves the bag InStore in the store,
     * holding its remainder, because that is where it still physically is.
     * Nothing stamps a machine onto the bag any more (material_bags
     * .day_bin_work_center_id keeps its historical values and stops being
     * written by this path).
     *
     * $recordedBy is the AUTHENTICATED user (the audit identity on the
     * stock movements); $supervisorId is only a note of who was acting
     * supervisor at the scan, never the identity.
     *
     * @return array{bag: MaterialBag, balance: StockBalance, movement: DayBinMovement}
     */
    public function loadBag(
        string $barcode,
        ?string $quantityKg,
        int $recordedBy,
        ?int $supervisorId = null,
        ?string $ackReason = null,
        ?string $ackNote = null,
    ): array {
        $warehouse = $this->warehouse();
        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'day_bin' => 'No day-bin warehouse is configured — name it in Production settings before loading bags.',
            ]);
        }

        return DB::transaction(function () use ($barcode, $quantityKg, $recordedBy, $supervisorId, $ackReason, $ackNote) {
            // THE BARCODE, THE BAG, THE LOT AND THE KILOGRAMS — resolved by
            // the one shared scanner (MaterialBagIssueResolver), which is
            // this method's own resolution and its own refusals, moved out
            // unchanged so the store-issue handover (Phase 7.5) reads a
            // barcode exactly as the floor does. Blank barcode, unknown
            // barcode, a consumed or empty bag, the incoming-QC arrival
            // hold, a bag with no store, and the over-pour guard all still
            // refuse with the same words in the same order.
            //
            // THE "ALREADY IN THE DAY BIN" REFUSAL IS STILL GONE, with the
            // transfer it existed to protect: under one accounting godown the
            // store and the day bin are the same warehouse for every bag in
            // the factory, so that check would have refused EVERY SCAN.
            ['bag' => $bag, 'lot' => $lot, 'quantity' => $quantity, 'remaining' => $remaining, 'full' => $fullLoad]
                = $this->bags->resolve($barcode, $quantityKg);

            $this->guardCommonInputBalance(
                itemId: (int) $lot->item_id,
                ackReason: $ackReason,
            );

            // A load that drives remaining_kg to 0 leaves the bag Consumed
            // (it holds nothing any more); a partial load pours off the
            // weighed kg and the bag stays InStore, because it is still
            // physically in the store holding its remainder. No machine is
            // stamped on the bag.
            $this->bags->pour($bag, $quantity, $remaining, $fullLoad);

            $notes = null;
            if ($supervisorId !== null) {
                $supervisor = User::query()->find($supervisorId);
                $notes = 'Acting supervisor: '.($supervisor?->name ?? "user #{$supervisorId}");
            }

            // NO STOCK MOVEMENT. A scan is an OPERATIONAL record, not an
            // accounting one.
            //
            // Loading used to transfer the kilograms from the store warehouse
            // into a day-bin warehouse, which described something real while
            // those were two different places. With one accounting godown they
            // are the same place: the material has not left the company, has
            // not left the godown, and has not been consumed. A transfer would
            // decrement and re-increment one balance, mint a paired movement on
            // every scan, and make any report that counts transfers double-count
            // — all to record a move that did not happen.
            //
            // What the load IS, is recorded below and beside: the day-bin
            // ledger row (bag, kg, who, when) and the common
            // pool fold that gives the material a rate. Company stock changes
            // exactly once, later, when an approved batch's consumption is
            // booked — and that same quantity is what reaches Tally.

            // THE KILOGRAM RECORD. Inside the same transaction as the bag
            // decrement and the transfer, so a scan can never leave stock
            // moved with nothing recorded against it.
            //
            // work_center_id is NULL and shift_production_entry_id is absent,
            // and both absences say the same thing: a scan is material
            // entering the COMMON INPUT, not into a machine and not into a
            // batch. The floor loads before Start Batch as often as during a
            // run, and the estimate is a running factory-wide total that
            // needs no machine and no segment window (commonResinEstimate).
            $movement = $this->ledger->record([
                'work_center_id' => null,
                'item_id' => $lot->item_id,
                'type' => DayBinMovementType::Load->value,
                'material_bag_id' => $bag->id,
                'quantity_kg' => $quantity,
                // Present only when the gate above asked for it. A scan
                // below the threshold carries nulls, which is what "nobody
                // was asked" must look like in the data.
                'balance_ack_reason' => $ackReason,
                'balance_ack_note' => $ackNote,
                'recorded_by' => $recordedBy,
            ]);

            // THE MONEY RECORD. The bag's own lot rate folds into the common
            // pool for this exact material, at this exact quantity — the
            // moving average every future batch of this material will be
            // costed at. A rateless lot (opening stock, the commonest case on
            // day one) folds into the pool's unpriced kg and leaves the
            // average untouched: see ResinPoolService.
            [$rate] = $this->rates->rateFor($lot);
            $this->pool->fold((int) $lot->item_id, $quantity, $rate);

            // THE MATERIAL'S ACCOUNTING BALANCE, READ WHERE IT ACTUALLY SITS
            // — the bag's own store — and deliberately NOT where the day bin
            // points.
            //
            // This used to read the day-bin warehouse and firstOrFail(). That
            // row existed only because the load had just created it by
            // transferring stock in. With the transfer gone there is no such
            // row, and firstOrFail() turned every successful scan into a 404.
            //
            // first(), never firstOrFail(): a material with no balance row yet
            // is a real state on day one, and a scan that recorded the load
            // correctly must not fail because there was nothing to report back.
            $balance = StockBalance::query()
                ->with('item')
                ->where('item_id', $lot->item_id)
                ->where('warehouse_id', $bag->current_warehouse_id)
                ->first();

            return [
                'bag' => $bag->load('lot.item'),
                'balance' => $balance,
                'movement' => $movement,
            ];
        });
    }

    /**
     * The acknowledgement reasons a scan may carry. Four words, because the
     * question is "what happened to the material the estimate still expects"
     * and there are only four honest answers to it:
     *
     *   confirm_extra    it really is still in there; we are loading on top
     *   spill            it went on the floor
     *   return_to_store  it went back to the store
     *   correction       the estimate is wrong
     */
    public const ACK_REASONS = ['confirm_extra', 'spill', 'return_to_store', 'correction'];

    /**
     * THE SCAN ACKNOWLEDGEMENT GATE, NOW KEYED ON THE COMMON INPUT.
     *
     * When the running estimate still says the common input holds a
     * meaningful quantity of this material and somebody loads another bag of
     * it, the scan is refused until one word explains why. Above the
     * threshold that discrepancy is worth a question; below it nothing is
     * asked and the ordinary scan stays one tap. ONE question, the same four
     * reasons, the same audited columns on the Load row.
     *
     * IT DOES NOT ASK FOR A WEIGHT, AND MUST NOT. The factory does no routine
     * bin weighing and this gate introduces none — it names the estimated
     * figure and offers four words, because a scale nobody walks to produces
     * a number nobody trusts.
     *
     * THE RUNNING-BATCH EXEMPTION IS GONE, and that is a re-judgement rather
     * than an oversight. Under the per-machine model the gate stayed silent
     * while that machine's batch ran, because consumption is booked at
     * completeBatch and a machine an hour into its shift still read as
     * holding its whole first bag. With ONE common input serving every
     * machine, some batch is almost always running somewhere, so the same
     * exemption would silence the gate permanently — which is the same as
     * deleting it.
     *
     * So it fires whenever the common estimate is at or over the threshold,
     * and the 422 SAYS WHAT THE FIGURE IS WORTH: it is an estimate that does
     * not yet count batches still running. The operator is told the honest
     * basis instead of being handed a number dressed up as a measurement.
     *
     * THE ESTIMATE IS READ BEFORE THIS LOAD IS RECORDED, which is the whole
     * correctness of the thing: computed afterwards, every 25 kg bag would
     * trip a 5 kg threshold and the gate would fire on every scan.
     *
     * A FIRST-EVER LOAD IS NEVER GATED, and that falls out for free rather
     * than being special-cased: commonResinEstimate reports no row at all
     * until a material has been loaded at least once (no load, no baseline),
     * so there is no figure to exceed the threshold.
     *
     * @throws ValidationException 422 naming the estimated figure and the choices
     */
    private function guardCommonInputBalance(int $itemId, ?string $ackReason): void
    {
        if ($ackReason !== null) {
            return;
        }

        $estimated = $this->estimatedRemainingFor($itemId);

        if ($estimated === null) {
            return;
        }

        $threshold = (string) (float) config('production.tolerances.machine_balance_ack_kg', 5.0);

        if (bccomp($estimated, $threshold, 4) < 0) {
            return;
        }

        throw ValidationException::withMessages([
            'balance_ack_reason' => sprintf(
                'The common resin input is still estimated to hold %s kg of this material (estimated, not counting batches still running). Say what happened to it before loading more — %s.',
                rtrim(rtrim($estimated, '0'), '.'),
                implode(', ', self::ACK_REASONS),
            ),
        ]);
    }

    /**
     * The common input's estimated remaining kg of one material, or null when
     * there is no baseline to estimate against. Reuses commonResinEstimate
     * rather than re-deriving the arithmetic, so the figure the gate quotes is
     * exactly the figure every screen shows.
     */
    private function estimatedRemainingFor(int $itemId): ?string
    {
        $material = $this->commonResinEstimate()
            ->first(fn (array $row) => (int) $row['item']->id === $itemId);

        return $material['estimated_remaining_kg'] ?? null;
    }

    /**
     * WHAT THE FACTORY HOLDS IN WIP RIGHT NOW, as stock — the LOCATION
     * question, deliberately not the machine one. It is the ordinary balance
     * of the internal WIP warehouse and is readable without picking a
     * machine, because in the books there is nothing per machine to pick —
     * and there is no per-machine estimate either (owner's correction,
     * 2-Aug): the "how much is standing in the input?" question is answered
     * factory-wide, by commonResinEstimate().
     *
     * `materials` is empty (not an error) when nothing is there, and the
     * whole read answers `warehouse: null` when no warehouse is configured
     * yet, so the screen can prompt instead of failing.
     *
     * `summary` and `todays_loads` are the owner's one-look answer: per raw
     * material, what is out on the floor vs still in the store (plus bags),
     * and every load that went out today with who did it. Both empty (not an
     * error) until a warehouse is configured — there is nothing to summarize.
     *
     * @return array{
     *     warehouse: ?Warehouse,
     *     materials: Collection<int, StockBalance>,
     *     summary: Collection<int, array{item: Item, bin_kg: string, store_kg: string, unopened_bags: array{count: int, kg: string}}>,
     *     todays_loads: Collection<int, StockMovement>,
     * }
     */
    public function snapshot(): array
    {
        $warehouse = $this->warehouse();

        return [
            'warehouse' => $warehouse,
            'materials' => $warehouse === null
                ? collect()
                // Zero-balance rows are kept: "resin 0 kg" is the answer a
                // supervisor needs before starting, and hiding the line reads
                // as "material we don't track here".
                : $this->stock->balancesForWarehouse($warehouse->id),
            'summary' => $warehouse === null ? collect() : $this->rawMaterialSummary($warehouse),
            'todays_loads' => $warehouse === null
                ? collect()
                : $this->stock->transfersIntoWarehouseOn($warehouse->id, now()->toDateString()),
        ];
    }

    /**
     * The owner's per-material picture, restricted to RAW MATERIALS (kg-uom
     * items — the only raw-material signal this database carries; bottles
     * and caps count in Nos and never belong here): every kg item with a
     * balance row in the bin OR in the store, item-name ordered, each with
     *
     *   bin_kg        — the bin warehouse's own stock balance,
     *   store_kg      — summed balances across Tally-linked warehouses other
     *                   than the bin (the REAL godowns; the bin itself is an
     *                   internal ERP location Tally never sees),
     *   unopened_bags — count and remaining kg of registered bags still
     *                   holding material (remaining_kg > 0; a partially
     *                   poured bag counts with what is actually left in it).
     *
     * @return Collection<int, array{item: Item, bin_kg: string, store_kg: string, unopened_bags: array{count: int, kg: string}}>
     */
    private function rawMaterialSummary(Warehouse $bin): Collection
    {
        $storeWarehouseIds = $this->storeWarehouseIds($bin);

        $binByItem = StockBalance::query()
            ->where('warehouse_id', $bin->id)
            ->get()
            ->keyBy('item_id');

        $storeByItem = StockBalance::query()
            ->whereIn('warehouse_id', $storeWarehouseIds)
            ->get()
            ->groupBy('item_id');

        // EVERY active raw material, not only the ones that happen to have a
        // stock row. Filtering on "has a balance somewhere" hid exactly the
        // material a person came here to load: the owner reported the amber
        // masterbatch missing, and it was missing because none had been loaded
        // yet — a material you cannot see is a material you cannot load, so
        // absence-of-stock made the page useless for the case it exists for.
        // Zero is a fact worth showing; a missing row is not.
        //
        // A kg-family unit is this database's only signal for "raw material"
        // (see Item::scopeKgUom), so kg-measured packing film appears here too.
        // That is the honest consequence of the signal available and is better
        // than a hardcoded list of names that a new masterbatch would fall out
        // of the moment the factory bought one.
        $items = Item::query()
            ->kgUom()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $bagsByItem = MaterialBag::query()
            ->join('material_lots', 'material_lots.id', '=', 'material_bags.material_lot_id')
            ->whereIn('material_lots.item_id', $items->pluck('id'))
            ->where('material_bags.remaining_kg', '>', 0)
            ->get(['material_bags.*', 'material_lots.item_id as lot_item_id'])
            ->groupBy('lot_item_id');

        return $items->map(function (Item $item) use ($binByItem, $storeByItem, $bagsByItem) {
            $storeKg = ($storeByItem->get($item->id) ?? collect())
                ->reduce(fn (string $carry, StockBalance $balance) => bcadd($carry, (string) $balance->quantity, 4), '0.0000');

            $bags = $bagsByItem->get($item->id) ?? collect();

            return [
                'item' => $item,
                'bin_kg' => bcadd((string) ($binByItem->get($item->id)?->quantity ?? '0'), '0', 4),
                'store_kg' => $storeKg,
                'unopened_bags' => [
                    'count' => $bags->count(),
                    'kg' => $bags->reduce(fn (string $carry, MaterialBag $bag) => bcadd($carry, (string) $bag->remaining_kg, 4), '0.0000'),
                ],
            ];
        })->values();
    }

    /**
     * ESTIMATED RESIN REMAINING IN THE COMMON INPUT — one figure per
     * material, for the whole factory:
     *
     *     estimated remaining = Σ every load of that material
     *                         − Σ calculated consumption of that material
     *                           across ALL machines
     *
     * THERE IS NO MACHINE DIMENSION, and its absence is the owner's
     * correction (2-Aug) rather than a simplification: the factory has one
     * common resin input point, a bag is never assigned or scanned to a
     * machine, so a per-machine balance was a number with no physical
     * referent. Reconciliation is TOTAL loads against TOTAL consumption.
     *
     * WHY THERE IS NO DAILY CUTOFF, AND NO SEPARATE CARRYOVER TERM. A running
     * total already IS "previous carryover plus loads minus consumption":
     * yesterday's carryover is nothing more than yesterday's loads minus
     * yesterday's consumption, inside the same two sums. Cutting the window
     * at a date would force a separate opening figure this schema does not
     * store.
     *
     * WHERE THE RUNNING TOTAL STARTS, AND WHY IT IS NOT LITERALLY ALL TIME.
     * It starts at the FIRST LOAD of that material, and a material with no
     * load at all is not reported.
     *
     * Not a refinement — without it the read is wrong on the day it ships.
     * Consumption rows go back to before any of this existed, and loading is
     * behind a config flag (production.traceability_enabled) a deployment may
     * never have turned on. An unbounded subtraction would open with every
     * material reporting a deficit equal to its ENTIRE consumption history —
     * a page of large negative numbers on rollout morning, indistinguishable
     * from the one signal this endpoint exists to raise (material genuinely
     * burnt without a load).
     *
     * Starting at the first load states the honest assumption instead: the
     * factory does not know what stood in the input before it began
     * recording, so that carryover is taken as zero and the count begins
     * where the evidence does. Everything after the first load is measured,
     * including a material loaded once and then run unrecorded for a week —
     * exactly the case that must still read negative.
     *
     * WHY CONSUMPTION IS READ FROM material_consumptions, AND WHY THAT MAKES
     * CORRECTIONS REPLACE RATHER THAN ACCUMULATE. The owner: "A correction
     * must replace the previous calculation. The current resin quantity,
     * totals and voucher preview must never count every correction as fresh
     * consumption." An amendment reverses the completion and re-books it
     * (amendCompletion → reverseCompletionEffects → completeBatch), and that
     * reversal DELETES the entry's consumption rows before the corrected
     * completion writes new ones. So the rows standing right now are always
     * the latest calculation and only the latest — nothing needs to know an
     * amendment happened. The reversal pairs live in stock_movements, which
     * is append-only by design and is deliberately NOT what this sums.
     *
     * WHY IT MAY GO NEGATIVE, AND WHY IT IS SERVED THAT WAY. Consumption is
     * DERIVED from output (pieces × standard weight + lumps), not weighed
     * out, so material run without being loaded reads negative. That is the
     * honest signal and the one worth acting on. Clamping at zero would erase
     * exactly the case the estimate exists to expose.
     *
     * IT IS AN ESTIMATE, AND IT LAGS THE FLOOR BY THE BATCHES STILL RUNNING.
     * Consumption is booked at completeBatch, so material an in-flight batch
     * has already melted is not yet subtracted. Every surface that quotes
     * this figure says so in words — see guardCommonInputBalance's 422.
     *
     * KG MATERIALS ONLY. The "Other materials (exceptions)" repeater files
     * ANY item's quantity into a column named quantity_issued_kg, so a batch
     * that issued 13,333 caps has a 13,333 "kg" consumption row. Restricting
     * to the kg family — this database's only raw-material signal, the same
     * gate the pickers use — is what stops the page reporting "estimated
     * remaining −13,333 kg" for cartons.
     *
     * Soft-deleted items are still listed: a retired master the input still
     * holds is precisely what must not be hidden.
     *
     * @return Collection<int, array{
     *     item: Item, loaded_kg: string, consumed_kg: string,
     *     estimated_remaining_kg: string, last_load_at: ?CarbonImmutable,
     * }>
     */
    public function commonResinEstimate(): Collection
    {
        // bcmath accumulation in PHP rather than SQL SUM(): the test database
        // is SQLite, whose SUM() over a DECIMAL column comes back as a float,
        // and a stock figure that has been through a float is not one this
        // codebase will print. Same rule as everywhere else in the module.
        $loaded = [];
        $firstLoadAt = [];
        $lastLoadAt = [];

        // EVERY Load row, whatever machine it does or does not name. Rows
        // written before the correction carry a work_center_id and are
        // counted exactly like the ones written after it that do not: the
        // material entered the factory's input either way, and re-reading
        // history through the new model is the whole point of a common total.
        DayBinMovement::query()
            ->where('type', DayBinMovementType::Load->value)
            ->orderBy('id')
            ->get(['item_id', 'quantity_kg', 'recorded_at'])
            ->each(function (DayBinMovement $movement) use (&$loaded, &$firstLoadAt, &$lastLoadAt) {
                $key = (int) $movement->item_id;
                $loaded[$key] = bcadd($loaded[$key] ?? '0.0000', (string) $movement->quantity_kg, 4);

                $at = CarbonImmutable::parse($movement->recorded_at);
                if (! isset($firstLoadAt[$key]) || $at->lessThan($firstLoadAt[$key])) {
                    $firstLoadAt[$key] = $at;
                }
                if (! isset($lastLoadAt[$key]) || $at->greaterThan($lastLoadAt[$key])) {
                    $lastLoadAt[$key] = $at;
                }
            });

        // AND EVERY STORE ISSUE, on exactly the same footing (Phase 7.5,
        // WS-C). DEC-20260817-001 retires the Day Bin from the target
        // workflow: material now reaches production as a store issue into
        // Production/WIP, so an estimate that counted only day-bin Load rows
        // would keep subtracting the factory's whole consumption from a
        // numerator that had stopped growing — and would report a deficit
        // that is an artefact of the migration, not a shortage on the floor.
        //
        // NET of returns, per line, and cancelled issues excluded — that is
        // StoreIssueLedgerReader's contract. A partially returned issue
        // contributes only the part that stayed with production.
        foreach ($this->storeIssues->netIssuedKgByItem() as $key => $issued) {
            $loaded[$key] = bcadd($loaded[$key] ?? '0.0000', $issued['kg'], 4);

            if (! isset($firstLoadAt[$key]) || $issued['first_at']->lessThan($firstLoadAt[$key])) {
                $firstLoadAt[$key] = $issued['first_at'];
            }
            if (! isset($lastLoadAt[$key]) || $issued['last_at']->greaterThan($lastLoadAt[$key])) {
                $lastLoadAt[$key] = $issued['last_at'];
            }
        }

        // Nothing loaded and nothing issued means no baseline for anything —
        // there is nothing to estimate against, and reporting the factory's
        // whole consumption history as a deficit would be inventing a
        // shortage.
        if ($loaded === []) {
            return collect();
        }

        $itemIds = array_keys($loaded);
        $consumed = [];

        // ACROSS ALL MACHINES. No join to shift_production_entries any more:
        // which machine burnt it is no longer part of the question, and the
        // consumption row already carries the item and the quantity.
        //
        // Narrowed to rows written at or after the EARLIEST first load, so a
        // database with years of pre-recording history is not dragged through
        // PHP to be discarded. The exact per-material cutoff is applied below.
        ShiftMaterialConsumption::query()
            ->whereIn('item_id', $itemIds)
            ->where('created_at', '>=', min($firstLoadAt))
            ->get(['item_id', 'quantity_issued_kg', 'created_at'])
            ->each(function (ShiftMaterialConsumption $row) use (&$consumed, $firstLoadAt) {
                $key = (int) $row->item_id;

                // Consumption from before this material was ever loaded came
                // out of an input nobody was recording. It is not this
                // estimate's to subtract — see the docblock.
                if (! isset($firstLoadAt[$key])
                    || CarbonImmutable::parse($row->created_at)->lessThan($firstLoadAt[$key])) {
                    return;
                }

                $consumed[$key] = bcadd($consumed[$key] ?? '0.0000', (string) $row->quantity_issued_kg, 4);
            });

        return Item::withTrashed()
            ->whereIn('id', $itemIds)
            ->orderBy('name')
            ->get()
            // The PHP twin of scopeKgUom — rows are already in memory, and a
            // trashed master must be judged on the UOM it still carries.
            ->filter(fn (Item $item) => $item->hasKgUom())
            ->map(function (Item $item) use ($loaded, $consumed, $lastLoadAt) {
                $loadedKg = $loaded[$item->id];
                $consumedKg = $consumed[$item->id] ?? '0.0000';

                return [
                    'item' => $item,
                    'loaded_kg' => $loadedKg,
                    'consumed_kg' => $consumedKg,
                    'estimated_remaining_kg' => bcsub($loadedKg, $consumedKg, 4),
                    'last_load_at' => $lastLoadAt[$item->id] ?? null,
                ];
            })
            ->values();
    }

    /**
     * The Day Bin page's raw-material picker: every ACTIVE kg-uom item with
     * its current store kg (same store definition as the summary above —
     * Tally-linked warehouses excluding the bin), item-name ordered. Items
     * with no stock at all still list at 0 kg: the picker's job is "what
     * could be loaded", and hiding an out-of-stock resin reads as "not a
     * material we track".
     *
     * @return Collection<int, array{item: Item, store_kg: string}>
     */
    public function rawMaterials(): Collection
    {
        $storeWarehouseIds = $this->storeWarehouseIds($this->warehouse());

        $storeByItem = StockBalance::query()
            ->whereIn('warehouse_id', $storeWarehouseIds)
            ->get()
            ->groupBy('item_id');

        return Item::query()
            ->kgUom()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Item $item) => [
                'item' => $item,
                'store_kg' => ($storeByItem->get($item->id) ?? collect())
                    ->reduce(fn (string $carry, StockBalance $balance) => bcadd($carry, (string) $balance->quantity, 4), '0.0000'),
            ])
            ->values();
    }

    /**
     * The warehouses "store kg" means: Tally-linked ones (the real godowns
     * the accountant's books know), never the bin itself — the bin is the
     * internal location the store figure is being contrasted WITH.
     *
     * @return Collection<int, int>
     */
    private function storeWarehouseIds(?Warehouse $bin): Collection
    {
        return Warehouse::query()
            ->whereNotNull('tally_guid')
            ->when($bin !== null, fn ($query) => $query->where('id', '!=', $bin->id))
            ->pluck('id');
    }
}
