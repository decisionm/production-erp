<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Exceptions\BagOverloadException;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE FACTORY'S INTERNAL WIP LOCATION, and the per-machine resin estimate
 * built on top of it.
 *
 * WHAT THE OWNER ACTUALLY RULED (31-Jul, decisive — it replaces the earlier
 * "central bin plus an evening physical bin weight" design this class was
 * first written for): "Our factory does not use a Day Bin warehouse or an
 * evening physical bin weight. Replace that idea with estimated resin
 * remaining for each machine: previous carryover plus barcode-scanned loads
 * minus calculated consumption." And: "Scanning a bag means material was
 * loaded into the selected machine; it does not mean the whole quantity was
 * consumed."
 *
 * So there are now two separate questions, and this class keeps them apart:
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
 *  2. WHICH MACHINE THE MATERIAL WENT INTO — operational metadata, never a
 *     location. A load names its machine and appends a day_bin_movements Load
 *     row (the per-machine ledger that has existed since Phase 6), which is
 *     what makes machineResinEstimate() possible. The class/route names stay
 *     'day bin' because renaming a live API surface mid-freeze costs more than
 *     it explains; the BEHAVIOUR is the machine estimate.
 *
 * THE PHYSICAL COUNT IS GONE. The old reconciliation read compared a derived
 * "expected closing" against a weight somebody walked out and took. The
 * factory does not take that weight, so the endpoint asked a question nobody
 * answers — it is removed rather than left on the screen looking answerable.
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
        // The per-machine ledger a load is recorded in. Same service the
        // per-machine bin-bay path already writes through, so both routes
        // into a machine's material land in one place and the estimate
        // cannot see half the loads.
        private readonly DayBinLedgerService $ledger,
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
     * THE BAG SCAN, WHICH NOW NAMES ITS MACHINE. One barcode scan on the
     * Shift Floor moves a bag's kg out of the store warehouse into the
     * internal WIP warehouse AND records which machine it was emptied into.
     *
     * The owner's words: "Scanning a bag means material was loaded into the
     * selected machine; it does not mean the whole quantity was consumed."
     * So the scan produces two records, both inside one transaction:
     *
     *   - the stock transfer store → WIP warehouse. Unchanged. In the books
     *     the material is simply somewhere else, and Tally still sees one
     *     godown — there is no warehouse per machine and there must never be.
     *   - a day_bin_movements Load row for (machine, item, bag, kg). This is
     *     the operational attribution, and it is what
     *     machineResinEstimate() sums. Written through DayBinLedgerService,
     *     the same service the per-machine bin-bay path uses, so a machine's
     *     material has exactly one ledger.
     *
     * PARTIAL LOADS AND BAG REMAINING ARE UNCHANGED, deliberately — the
     * owner asked for both to keep working exactly as they do. The
     * status/remaining handling mirrors TraceabilityService::loadBagToDayBin
     * line for line: a full load empties the bag (→ Consumed) and stamps the
     * machine on it, a partial load pours off the weighed kg and leaves the
     * bag InStore in the store, unstamped, because that is where it still
     * physically is. The ledger row carries the machine either way — the
     * bag pointer says "this bag is at that machine now", the ledger row
     * says "these kg went into that machine", and only the second is a
     * quantity.
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
        int $workCenterId,
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

        $barcode = trim($barcode);
        if ($barcode === '') {
            throw ValidationException::withMessages([
                'barcode' => 'Scan or type a bag barcode.',
            ]);
        }

        return DB::transaction(function () use ($barcode, $quantityKg, $workCenterId, $recordedBy, $supervisorId, $warehouse, $ackReason, $ackNote) {
            $bag = MaterialBag::query()->where('barcode', $barcode)->lockForUpdate()->first();
            if ($bag === null) {
                throw ValidationException::withMessages([
                    'barcode' => 'Unknown bag barcode — no registered bag carries this code.',
                ]);
            }

            if ($bag->status === MaterialBagStatus::Consumed || bccomp((string) $bag->remaining_kg, '0', 4) !== 1) {
                throw ValidationException::withMessages([
                    'barcode' => "Bag {$bag->barcode} is already consumed — nothing is left in it to load.",
                ]);
            }

            if ($bag->current_warehouse_id === null) {
                throw ValidationException::withMessages([
                    'barcode' => "Bag {$bag->barcode} has no store warehouse recorded, so there is nowhere to move its stock from — register the lot with its warehouse first.",
                ]);
            }

            if ($bag->current_warehouse_id === $warehouse->id) {
                throw ValidationException::withMessages([
                    'barcode' => "Bag {$bag->barcode} already sits in the day-bin warehouse — there is nothing to move.",
                ]);
            }

            $remaining = bcadd((string) $bag->remaining_kg, '0', 4);
            $quantity = $quantityKg !== null ? bcadd($quantityKg, '0', 4) : $remaining;

            if (bccomp($quantity, '0', 4) !== 1 || bccomp($quantity, $remaining, 4) === 1) {
                throw BagOverloadException::make($bag->barcode, $quantity, $remaining);
            }

            // THE LOT (and therefore the material) IS RESOLVED BEFORE ANY
            // MUTATION, because the acknowledgement gate below needs to know
            // which material this machine is being topped up with, and a 422
            // must never leave a half-applied scan behind it.
            $lot = $bag->lot()->first();

            $this->guardMachineBalance(
                workCenterId: $workCenterId,
                itemId: (int) $lot->item_id,
                ackReason: $ackReason,
            );

            // Same rule as TraceabilityService::loadBagToDayBin: a load that
            // drives remaining_kg to 0 leaves the bag Consumed (it holds
            // nothing any more) and the bag itself is now at that machine; a
            // partial load pours off the weighed kg and the bag stays InStore,
            // unstamped, because it is still physically in the store.
            $fullLoad = bccomp($quantity, $remaining, 4) === 0;
            $bag->remaining_kg = bcsub($remaining, $quantity, 4);
            if ($fullLoad) {
                $bag->status = MaterialBagStatus::Consumed;
                $bag->day_bin_work_center_id = $workCenterId;
            }
            $bag->save();

            $notes = null;
            if ($supervisorId !== null) {
                $supervisor = User::query()->find($supervisorId);
                $notes = 'Acting supervisor: '.($supervisor?->name ?? "user #{$supervisorId}");
            }

            $this->stock->recordTransfer(
                itemId: $lot->item_id,
                fromWarehouseId: $bag->current_warehouse_id,
                toWarehouseId: $warehouse->id,
                quantity: $quantity,
                reference: self::BAG_LOAD_REFERENCE_PREFIX.$bag->barcode,
                notes: $notes,
                createdBy: $recordedBy,
            );

            // WHICH MACHINE GOT IT. Inside the same transaction as the bag
            // decrement and the transfer, so a scan can never leave stock
            // moved with no machine against it — an unattributed kg would
            // silently overstate every machine's estimated remaining except
            // the one that actually burnt it.
            //
            // No shift_production_entry_id: a scan is a load into a MACHINE,
            // not into a batch. The floor loads before Start Batch as often
            // as during a run, and the estimate is a running per-machine
            // total that needs no segment window (see machineResinEstimate).
            $movement = $this->ledger->record([
                'work_center_id' => $workCenterId,
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

            $balance = StockBalance::query()
                ->with('item')
                ->where('item_id', $lot->item_id)
                ->where('warehouse_id', $warehouse->id)
                ->firstOrFail();

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
     * THE SCAN ACKNOWLEDGEMENT GATE.
     *
     * When the running estimate still says this machine holds a meaningful
     * quantity of this material and somebody scans another bag into it, the
     * scan is refused until one word explains why. Above the threshold that
     * discrepancy is worth a question; below it, nothing is asked and the
     * ordinary scan stays one tap.
     *
     * IT DOES NOT ASK FOR A WEIGHT, AND MUST NOT. The factory does no
     * routine day-bin weighing, and this gate deliberately introduces none —
     * it names the estimated figure and offers four words, because a scale
     * nobody walks to produces a number nobody trusts. The old
     * reconciliation asked a question the factory does not answer; that
     * mistake is not repeated here.
     *
     * THE ESTIMATE IS READ BEFORE THIS LOAD IS RECORDED, which is the whole
     * correctness of the thing: computed afterwards, every 25 kg bag would
     * trip a 5 kg threshold and the gate would fire on every scan, which is
     * the same as not existing.
     *
     * A FIRST-EVER SCAN IS NEVER GATED, and that falls out for free rather
     * than being special-cased: machineResinEstimate reports no pair at all
     * until a material has been scanned into a machine at least once (no
     * scan, no baseline), so there is no figure to exceed the threshold and
     * nobody is asked to explain a machine the system has never seen loaded.
     *
     * @throws ValidationException 422 naming the estimated figure and the choices
     */
    private function guardMachineBalance(int $workCenterId, int $itemId, ?string $ackReason): void
    {
        if ($ackReason !== null) {
            return;
        }

        // THE GATE ONLY SPEAKS WHEN THE ESTIMATE CAN BE TRUSTED — between
        // batches. Consumption is booked at completeBatch, so while a batch
        // is running the estimate has not yet been charged for anything that
        // run has melted: a machine an hour into its shift reads as still
        // holding its whole first bag, and gating on that figure turned the
        // routine second-bag-of-the-run scan into a demanded explanation
        // (the live diff audit called it: operators would reflexively answer
        // confirm_extra within a week and the signal would die). Between
        // batches every completed run HAS been charged, the figure is real,
        // and a bag's worth still showing is a genuine question.
        $running = ShiftProductionEntry::query()
            ->where('work_center_id', $workCenterId)
            ->where('batch_status', BatchStatus::InProgress->value)
            ->exists();

        if ($running) {
            return;
        }

        $estimated = $this->estimatedRemainingFor($workCenterId, $itemId);

        if ($estimated === null) {
            return;
        }

        $threshold = (string) (float) config('production.tolerances.machine_balance_ack_kg', 5.0);

        if (bccomp($estimated, $threshold, 4) < 0) {
            return;
        }

        throw ValidationException::withMessages([
            'balance_ack_reason' => sprintf(
                'This machine is still estimated to hold %s kg of this material. Say what happened to it before loading more — %s.',
                rtrim(rtrim($estimated, '0'), '.'),
                implode(', ', self::ACK_REASONS),
            ),
        ]);
    }

    /**
     * One machine's estimated remaining kg of one material, or null when
     * there is no baseline to estimate against. Reuses machineResinEstimate
     * rather than re-deriving the arithmetic, so the figure the gate quotes
     * is exactly the figure every screen shows.
     */
    private function estimatedRemainingFor(int $workCenterId, int $itemId): ?string
    {
        $machine = $this->machineResinEstimate($workCenterId)->first();

        if ($machine === null) {
            return null;
        }

        $material = $machine['materials']
            ->first(fn (array $row) => (int) $row['item']->id === $itemId);

        return $material['estimated_remaining_kg'] ?? null;
    }

    /**
     * WHAT THE FACTORY HOLDS IN WIP RIGHT NOW, as stock — the LOCATION
     * question, deliberately not the machine one. It is the ordinary balance
     * of the internal WIP warehouse and is readable without picking a
     * machine, because in the books there is nothing per machine to pick:
     * that is what machineResinEstimate() answers instead.
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
     * ESTIMATED RESIN REMAINING PER MACHINE — the figure that replaced the
     * central-bin bookkeeping, in the owner's own arithmetic:
     *
     *     estimated remaining = Σ scanned loads into that machine
     *                         − Σ calculated consumption of that machine's
     *                           batches
     *
     * per machine, per material.
     *
     * WHY THERE IS NO DAILY CUTOFF, AND NO SEPARATE CARRYOVER TERM. The
     * owner asked for "previous carryover plus barcode-scanned loads minus
     * calculated consumption". A running total IS that: yesterday's carryover
     * is nothing more than yesterday's loads minus yesterday's consumption,
     * already inside the same two sums. Cutting the window at a date would
     * force a separate opening figure that this schema does not store, and
     * the old reconciliation read had to derive one by rolling the ledger
     * backwards — which is exactly the machinery the pivot removed.
     *
     * WHERE THE RUNNING TOTAL STARTS, AND WHY IT IS NOT "ALL TIME". It starts
     * at the FIRST SCAN of that material into that machine, and a pair with
     * no scan at all is not reported.
     *
     * Not a refinement — without it the read is wrong on the day it ships.
     * Consumption rows go back to before any of this existed, and scanning is
     * behind a config flag (production.traceability_enabled) that a
     * deployment may not have turned on at all. An all-time subtraction would
     * therefore open with every machine reporting a deficit equal to its
     * ENTIRE consumption history — a page of large negative numbers on
     * rollout morning, indistinguishable from the one signal this endpoint
     * exists to raise (material genuinely burnt without a scan).
     *
     * Starting at the first scan states the honest assumption instead: the
     * factory does not know what was in a hopper before it began scanning, so
     * that carryover is taken as zero and the count begins where the evidence
     * does. Everything after the first scan is measured, including a machine
     * that was scanned once and then ran unscanned for a week — which is
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
     * out, so a machine that ran on material nobody scanned reads negative.
     * That is the honest signal and the one worth acting on — it means loads
     * are being missed at the scanner. Clamping it at zero would erase
     * exactly the case the estimate exists to expose. (DayBinLedgerService's
     * balanceBeforeSegment does floor at zero; that is a headroom guard for a
     * count, a different question, and its clamp is not copied here.)
     *
     * KG MATERIALS ONLY. The "Other materials (exceptions)" repeater files
     * ANY item's quantity into a column named quantity_issued_kg, so a batch
     * that issued 13,333 caps has a 13,333 "kg" consumption row. Restricting
     * to the kg family — this database's only raw-material signal, the same
     * gate the pickers use — is what stops the page reporting "estimated
     * remaining −13,333 kg" for cartons.
     *
     * A pair appears once that material has been scanned into that machine at
     * least once — before then there is no baseline to subtract from and no
     * figure worth printing. Soft-deleted items are still listed: a retired
     * master a machine still holds is precisely what must not be hidden.
     *
     * @return Collection<int, array{
     *     work_center: WorkCenter,
     *     materials: Collection<int, array{
     *         item: Item, loaded_kg: string, consumed_kg: string,
     *         estimated_remaining_kg: string, last_load_at: ?CarbonImmutable,
     *     }>,
     * }>
     */
    public function machineResinEstimate(?int $workCenterId = null): Collection
    {
        // bcmath accumulation in PHP rather than SQL SUM(): the test database
        // is SQLite, whose SUM() over a DECIMAL column comes back as a float,
        // and a stock figure that has been through a float is not one this
        // codebase will print. Same rule as everywhere else in the module.
        $loaded = [];
        $firstLoadAt = [];
        $lastLoadAt = [];

        DayBinMovement::query()
            ->where('type', DayBinMovementType::Load->value)
            ->when($workCenterId !== null, fn ($query) => $query->where('work_center_id', $workCenterId))
            ->orderBy('id')
            ->get(['work_center_id', 'item_id', 'quantity_kg', 'recorded_at'])
            ->each(function (DayBinMovement $movement) use (&$loaded, &$firstLoadAt, &$lastLoadAt) {
                $key = "{$movement->work_center_id}@{$movement->item_id}";
                $loaded[$key] = bcadd($loaded[$key] ?? '0.0000', (string) $movement->quantity_kg, 4);

                $at = CarbonImmutable::parse($movement->recorded_at);
                if (! isset($firstLoadAt[$key]) || $at->lessThan($firstLoadAt[$key])) {
                    $firstLoadAt[$key] = $at;
                }
                if (! isset($lastLoadAt[$key]) || $at->greaterThan($lastLoadAt[$key])) {
                    $lastLoadAt[$key] = $at;
                }
            });

        // No scan anywhere in scope means no baseline anywhere — there is
        // nothing to estimate against, and reporting each machine's whole
        // consumption history as a deficit would be inventing a shortage.
        if ($loaded === []) {
            return collect();
        }

        $keys = collect(array_keys($loaded));
        $machineIds = $keys->map(fn (string $key) => (int) explode('@', $key)[0])->unique();
        $itemIds = $keys->map(fn (string $key) => (int) explode('@', $key)[1])->unique();

        $consumed = [];

        // The machine a consumption belongs to is its BATCH's machine — the
        // consumption row itself carries only the warehouse it was issued
        // from, which is one shared location for the whole factory.
        //
        // Narrowed to the machines that have scans, and to rows written at or
        // after the EARLIEST of their first scans, so a database with years of
        // pre-scanner history is not dragged through PHP to be discarded.
        // The exact per-pair cutoff is applied below.
        ShiftMaterialConsumption::query()
            ->join(
                'shift_production_entries',
                'shift_production_entries.id',
                '=',
                'shift_material_consumptions.shift_production_entry_id',
            )
            ->whereIn('shift_production_entries.work_center_id', $machineIds)
            ->whereIn('shift_material_consumptions.item_id', $itemIds)
            ->where('shift_material_consumptions.created_at', '>=', min($firstLoadAt))
            ->get([
                'shift_production_entries.work_center_id as wc_id',
                'shift_material_consumptions.item_id',
                'shift_material_consumptions.quantity_issued_kg',
                'shift_material_consumptions.created_at',
            ])
            ->each(function ($row) use (&$consumed, $firstLoadAt) {
                $key = "{$row->wc_id}@{$row->item_id}";

                // Consumption from before this material was ever scanned into
                // this machine came out of a hopper nobody logged. It is not
                // this estimate's to subtract — see the docblock.
                if (! isset($firstLoadAt[$key])
                    || CarbonImmutable::parse($row->created_at)->lessThan($firstLoadAt[$key])) {
                    return;
                }

                $consumed[$key] = bcadd($consumed[$key] ?? '0.0000', (string) $row->quantity_issued_kg, 4);
            });

        $machines = WorkCenter::query()->whereIn('id', $machineIds)->orderBy('code')->get();
        $items = Item::withTrashed()
            ->whereIn('id', $itemIds)
            ->orderBy('name')
            ->get()
            // The PHP twin of scopeKgUom — rows are already in memory, and a
            // trashed master must be judged on the UOM it still carries.
            ->filter(fn (Item $item) => $item->hasKgUom())
            ->keyBy('id');

        return $machines
            ->map(function (WorkCenter $machine) use ($items, $loaded, $consumed, $lastLoadAt) {
                $materials = $items
                    ->map(function (Item $item) use ($machine, $loaded, $consumed, $lastLoadAt) {
                        $key = "{$machine->id}@{$item->id}";

                        // No scan of this material into this machine = no
                        // baseline, so no row. Its consumption is not zero, it
                        // is unmeasurable here, and a 0 kg line would say the
                        // opposite.
                        if (! isset($loaded[$key])) {
                            return null;
                        }

                        $loadedKg = $loaded[$key];
                        $consumedKg = $consumed[$key] ?? '0.0000';

                        return [
                            'item' => $item,
                            'loaded_kg' => $loadedKg,
                            'consumed_kg' => $consumedKg,
                            'estimated_remaining_kg' => bcsub($loadedKg, $consumedKg, 4),
                            'last_load_at' => $lastLoadAt[$key] ?? null,
                        ];
                    })
                    ->filter()
                    ->values();

                return ['work_center' => $machine, 'materials' => $materials];
            })
            // A machine whose only scanned material turned out to be non-kg
            // drops out entirely rather than listing as an empty card.
            ->filter(fn (array $row) => $row['materials']->isNotEmpty())
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
