<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Exceptions\BagOverloadException;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\WarehouseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The FACTORY DAY BIN — the one central place raw material is moved to when
 * it comes out of the store, before any machine runs.
 *
 * Deliberately NOT a new ledger. The day bin is simply a WAREHOUSE:
 *
 *  - loading it is the existing store → warehouse stock transfer
 *    (POST /inventory/stock-movements/transfers). Material changes location,
 *    nothing is consumed, no Tally voucher is posted.
 *  - its balance is the ordinary stock_balances row for (item, day-bin
 *    warehouse) — no second set of balance maths to reconcile, and it still
 *    maps to a Tally godown like any other warehouse.
 *  - consumption reduces it automatically, because every material line at
 *    batch completion already carries its OWN warehouse_id: a line issued
 *    from the day-bin warehouse decrements that warehouse's balance through
 *    the same StockMovementService::recordIssue every other issue uses.
 *
 * This class only answers "which warehouse is the day bin" and "what is in
 * it", so the per-machine day_bin_movements ledger and the barcode bin-bay
 * (the optional bag-level detail) stay exactly as they are.
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
     * The CENTRALIZED bag-scan load: one barcode scan on the Shift Floor
     * moves a bag's kg out of the store warehouse into the factory day-bin
     * warehouse — for all machines at once, so no work center is picked and
     * no per-machine day_bin_movements ledger row is written. The stock
     * transfer IS the record (no new ledger, exactly like the manual
     * transfer form this replaces for the scan case).
     *
     * Bag state is Inventory's; the status/remaining handling here mirrors
     * TraceabilityService::loadBagToDayBin line for line (full load empties
     * the bag → Consumed, partial load leaves it InStore), and the bag
     * decrement + warehouse transfer share ONE transaction so they can
     * never drift. day_bin_work_center_id stays untouched — the central
     * bin is not a machine.
     *
     * $recordedBy is the AUTHENTICATED user (the audit identity on the
     * stock movements); $supervisorId is only a note of who was acting
     * supervisor at the scan, never the identity.
     *
     * @return array{bag: MaterialBag, balance: StockBalance}
     */
    public function loadBag(string $barcode, ?string $quantityKg, int $recordedBy, ?int $supervisorId = null): array
    {
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

        return DB::transaction(function () use ($barcode, $quantityKg, $recordedBy, $supervisorId, $warehouse) {
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

            // Same rule as TraceabilityService::loadBagToDayBin: a load that
            // drives remaining_kg to 0 leaves the bag Consumed (it holds
            // nothing any more); a partial load pours off the weighed kg and
            // the bag stays InStore.
            $fullLoad = bccomp($quantity, $remaining, 4) === 0;
            $bag->remaining_kg = bcsub($remaining, $quantity, 4);
            if ($fullLoad) {
                $bag->status = MaterialBagStatus::Consumed;
            }
            $bag->save();

            $lot = $bag->lot()->first();

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

            $balance = StockBalance::query()
                ->with('item')
                ->where('item_id', $lot->item_id)
                ->where('warehouse_id', $warehouse->id)
                ->firstOrFail();

            return [
                'bag' => $bag->load('lot.item'),
                'balance' => $balance,
            ];
        });
    }

    /**
     * What the factory day bin holds right now — always visible without
     * picking a machine, which is the whole point of the central bin.
     *
     * `materials` is empty (not an error) when nothing is in the bin, and the
     * whole read answers `warehouse: null` when no bin is configured yet, so
     * the screen can prompt instead of failing.
     *
     * `summary` and `todays_loads` are the owner's one-look answer: per raw
     * material, what is in the bin vs still in the store (plus bags), and
     * every load that went into the bin today with who did it. Both empty
     * (not an error) until a bin is configured — there is no bin to summarize.
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
     * THE DAY-BIN RECONCILIATION — the central check that replaces the
     * per-batch "unaccounted kg" figure.
     *
     * WHY IT MOVED HERE. Nothing weighs out a fixed quantity of resin to
     * each machine. A batch's resin consumption is DERIVED from its output
     * (good kg + rejection kg + lumps kg), so a per-batch "issued minus
     * consumed" was ~0 by construction — an arithmetic identity wearing the
     * clothes of a check, and it only ever confused the people reading it.
     * Missing material is a CENTRAL question, so it is asked once a day
     * against the one bin every machine draws from:
     *
     *     opening + loaded − consumed   vs   what is PHYSICALLY in the bin
     *
     * Per raw material, for one calendar date:
     *
     *   opening_kg  — the bin balance at 00:00 on that date. This schema
     *                 stores no historical balances (stock_balances holds
     *                 only the CURRENT quantity), so it is derived by
     *                 rolling the ledger back:
     *
     *                     opening = current_balance
     *                             − Σ signed(every bin movement at or after
     *                                        00:00 on the date, up to now)
     *
     *                 signed receipt +, transfer_in +, issue −,
     *                 transfer_out − : the same signs that built the current
     *                 balance in the first place, applied in reverse. The
     *                 window is "at or AFTER the date", NOT "on the date" —
     *                 for a past date every movement since must come off
     *                 too, and that is exactly what makes yesterday's
     *                 reconciliation (the accountant's morning question)
     *                 come out right.
     *
     *   loaded_kg   — transfers INTO the bin on that date: store → bin,
     *                 bag scan and manual transfer form alike.
     *
     *   consumed_kg — issues OUT of the bin on that date: batch consumption
     *                 lines ("SPE #id") and any manual issue equally. There
     *                 is deliberately no reference filter — material that
     *                 left the bin left the bin, whoever booked it.
     *
     *   expected_closing_kg = opening + loaded − consumed.
     *
     * THE TAUTOLOGY, STATED PLAINLY SO NOBODY MISTAKES IT FOR A CHECK.
     * expected_closing_kg is NOT a verification of anything. For today, and
     * whenever the date's only bin movements are transfers-in and
     * issues-out, it equals the bin's live stock balance BY CONSTRUCTION:
     * opening was derived by subtracting the very movements that are added
     * back here, so the two cannot disagree. Comparing them proves the
     * arithmetic, not the material. The genuine check is the PHYSICAL COUNT
     * a person walks out and takes — the frontend collects that number and
     * compares it against expected_closing_kg client-side. This endpoint
     * supplies the expected side only; it never stores or judges a count.
     *
     *   other_movements_kg — the one leak in the identity above, surfaced
     *                 rather than hidden. A receipt booked straight into the
     *                 bin, or a transfer back OUT of the bin to the store
     *                 (both reachable through the ordinary
     *                 /inventory/stock-movements/transfers form the Day Bin
     *                 page already posts to), is neither a load nor
     *                 consumption, so it sits in neither figure. Normally
     *                 '0.0000'. When it is not, the full identity is
     *
     *                     live_balance = expected_closing + other_movements
     *
     *                 and the frontend can say so instead of reporting a
     *                 physical-count discrepancy the screen cannot explain.
     *
     * TIMING BASIS, AND A DELIBERATE DIVERGENCE FROM THE REST OF THE MODULE.
     * Batch consumption issues are stamped with the wall-clock moment they
     * were recorded (ShiftProductionEntryService passes no movement date, so
     * recordIssue defaults to now()). This read therefore bounds a day on
     * stock_movements.movement_date — NOT on production_date, which is how
     * every other day-scoped read in this module (ShiftSummaryService,
     * ProductionReportService, ProductionDowntimeService) bounds one.
     *
     * That divergence is intended, for two reasons. A stock movement carries
     * no production_date at all, so the column is not available here. More
     * importantly, production_date is a SHIFT-ATTRIBUTION date: by factory
     * convention an overnight shift files under the date it STARTED, so a
     * batch keyed at 02:00 belongs to the previous day (see
     * NightShiftProductionDateTest). What is physically in a bin at a given
     * moment is a wall-clock fact, and this figure exists to be compared
     * against a physical count taken at a wall-clock moment. Attributing the
     * night's consumption backwards would make expected_closing describe a
     * bin nobody could have looked into.
     *
     * WHERE THE DAY IS ACTUALLY CUT — do not read "midnight" as local
     * midnight. The window is midnight to midnight in the APPLICATION
     * timezone, and config/app.php hardcodes that to UTC (there is no
     * APP_TIMEZONE override) while the factory runs IST. The cut therefore
     * falls at 05:30 IST, not 00:00 IST, and a night shift's consumption
     * splits across two reconciliation dates at that instant rather than
     * landing whole on either.
     *
     * This does NOT affect the comparison the page exists for on TODAY's
     * date. Wherever the boundary sits, the arithmetic collapses to
     * expected_closing = live_balance − other_movements: opening is derived
     * by subtracting the very movements added back afterwards, so every
     * movement inside the window cancels and only the boundary-independent
     * remainder survives. A count taken now therefore reconciles against now
     * regardless of the cut — exactly when other_movements is '0.0000', and
     * offset by it otherwise (the identity stated in the two paragraphs
     * ABOVE). What the cut affects is only which past date a movement made
     * between 00:00 and 05:30 IST is filed under. Straightening that out means
     * setting app.timezone for the whole application — a global change that
     * would move production_date too, well outside this endpoint — so it is
     * recorded here rather than worked around locally.
     *
     * Materials with no movements on the date AND no opening balance are
     * omitted — an all-zero row is noise on a page whose job is to make one
     * real gap visible. Soft-deleted items are still listed: a retired
     * master that somehow still holds bin stock is precisely the kind of
     * material this page must not hide.
     *
     * `warehouse: null` (with an empty material list) when no bin is
     * configured — the same normal state every other read here answers.
     *
     * @return array{
     *     date: string,
     *     warehouse: ?Warehouse,
     *     materials: Collection<int, array{
     *         item: Item,
     *         opening_kg: string,
     *         loaded_kg: string,
     *         consumed_kg: string,
     *         expected_closing_kg: string,
     *         other_movements_kg: string,
     *     }>,
     * }
     */
    public function reconciliation(?string $date = null): array
    {
        $date = $this->resolveReconciliationDate($date);
        $warehouse = $this->warehouse();

        if ($warehouse === null) {
            return ['date' => $date, 'warehouse' => null, 'materials' => collect()];
        }

        $dayStart = CarbonImmutable::parse($date)->startOfDay();
        $dayEnd = $dayStart->addDay();

        // ONE pass over every bin movement at or after 00:00 on the date.
        // Aggregated in PHP with bcmath rather than SQL SUM() on purpose:
        // the test database is SQLite, whose SUM() over a DECIMAL column
        // comes back as a float, and a stock figure that has been through a
        // float is not a stock figure this codebase is willing to print.
        $movementsByItem = StockMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('movement_date', '>=', $dayStart)
            ->orderBy('id')
            ->get(['id', 'item_id', 'type', 'quantity', 'movement_date'])
            ->groupBy('item_id');

        $balanceByItem = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->get()
            ->keyBy('item_id');

        $itemIds = $movementsByItem->keys()->merge($balanceByItem->keys())->unique();

        $materials = Item::withTrashed()
            ->whereIn('id', $itemIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Item $item) => $this->reconcileMaterial(
                $item,
                $movementsByItem->get($item->id) ?? collect(),
                (string) ($balanceByItem->get($item->id)?->quantity ?? '0'),
                $dayEnd,
            ))
            ->filter()
            ->values();

        return ['date' => $date, 'warehouse' => $warehouse, 'materials' => $materials];
    }

    /**
     * One material's row, or null when the date has nothing to say about it
     * (no movement on the date and nothing in the bin at 00:00).
     *
     * $movements is every bin movement for this item at or after 00:00 on
     * the date — the ones before $dayEnd are the date's own, the rest are
     * later history that exists only to be rolled back off the current
     * balance.
     *
     * @param  Collection<int, StockMovement>  $movements
     * @return array{
     *     item: Item,
     *     opening_kg: string,
     *     loaded_kg: string,
     *     consumed_kg: string,
     *     expected_closing_kg: string,
     *     other_movements_kg: string,
     * }|null
     */
    private function reconcileMaterial(Item $item, Collection $movements, string $currentBalance, CarbonImmutable $dayEnd): ?array
    {
        $signedSinceDayStart = '0.0000';
        $loaded = '0.0000';
        $consumed = '0.0000';
        $other = '0.0000';
        $movedOnDate = false;

        foreach ($movements as $movement) {
            $quantity = bcadd((string) $movement->quantity, '0', 4);

            // Every movement carries a POSITIVE quantity; the type is what
            // says which way it went.
            $signed = match ($movement->type) {
                StockMovementType::Receipt, StockMovementType::TransferIn => $quantity,
                StockMovementType::Issue, StockMovementType::TransferOut => bcsub('0', $quantity, 4),
            };

            $signedSinceDayStart = bcadd($signedSinceDayStart, $signed, 4);

            if ($movement->movement_date->greaterThanOrEqualTo($dayEnd)) {
                continue; // Later history: rolled back above, but not the date's own.
            }

            // A movement ON the date keeps the material on the page even
            // when the figures net to zero (a 5 kg receipt and a 5 kg
            // transfer back out is a day worth looking at, not an absence).
            $movedOnDate = true;

            match ($movement->type) {
                StockMovementType::TransferIn => $loaded = bcadd($loaded, $quantity, 4),
                StockMovementType::Issue => $consumed = bcadd($consumed, $quantity, 4),
                default => $other = bcadd($other, $signed, 4),
            };
        }

        $opening = bcsub(bcadd($currentBalance, '0', 4), $signedSinceDayStart, 4);

        if (! $movedOnDate && bccomp($opening, '0', 4) === 0) {
            return null;
        }

        return [
            'item' => $item,
            'opening_kg' => $opening,
            'loaded_kg' => $loaded,
            'consumed_kg' => $consumed,
            'expected_closing_kg' => bcsub(bcadd($opening, $loaded, 4), $consumed, 4),
            'other_movements_kg' => $other,
        ];
    }

    /**
     * The date being reconciled: the ?date=YYYY-MM-DD query value, or today
     * when absent.
     *
     * Round-tripped through the format rather than merely parsed, so
     * "2026-13-45" is refused instead of silently rolling over into
     * February 2027 and answering with confident figures for a date nobody
     * asked about. Validation lives here rather than in a FormRequest for
     * the same reason loadBag's does: this is the layer that knows what a
     * usable date means to the day bin.
     */
    private function resolveReconciliationDate(?string $date): string
    {
        $date = trim((string) $date);

        if ($date === '') {
            return CarbonImmutable::now()->toDateString();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date);
        } catch (\Throwable) {
            $parsed = null;
        }

        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages([
                'date' => 'Give the date to reconcile as YYYY-MM-DD.',
            ]);
        }

        return $date;
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
