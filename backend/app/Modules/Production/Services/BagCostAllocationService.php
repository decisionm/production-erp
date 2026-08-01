<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Exceptions\DuplicateAllocationException;
use App\Modules\Production\Models\BatchResinAllocation;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WHAT A BATCH'S RESIN ACTUALLY COST — bag-layer FIFO costing, as an
 * ANALYTIC LAYER BESIDE THE STOCK LEDGER.
 *
 * ============================ THE BOUNDARY ============================
 *
 * This service does not touch StockMovementService and does not touch
 * stock_balances.average_cost. completeBatch books stock exactly as it did
 * before this class existed. The Accounts-approved Tally valuation — moving
 * average on the stock ledger — stays EXACTLY as is, and bag rates never
 * reach Tally at all (its voucher lines carry quantities only).
 *
 * That separation is the whole design. The ledger answers "what is the
 * company's stock worth", on a basis the accountant has already approved
 * and signed off. This layer answers a different question — "which bags did
 * THIS batch burn, and what were those bags bought for" — and it must be
 * able to answer it without perturbing the first answer by a single paisa.
 * Two questions, two mechanisms, one of them deliberately inert.
 *
 * ========================== HOW ALLOCATION WORKS ==========================
 *
 * A scan is a LOAD INTO A MACHINE, never a consumption: the owner's ruling,
 * and it is why layers exist at all. Each Load row (day_bin_movements, type
 * load) is a LAYER of material standing at that machine, carrying its bag
 * and therefore its lot and therefore its purchase rate.
 *
 * When a batch completes, its consumption is drawn from that machine's
 * layers in PHYSICAL FIFO ORDER — recorded_at ascending, id ascending as
 * the tiebreak, which is the order the material physically went into the
 * machine. A layer's remaining is its loaded kg minus every un-reversed
 * allocation ever made against it ACROSS ALL ENTRIES; that cross-entry sum
 * is what makes a part-used bag carry into the next batch at its own rate,
 * with no carry-over bookkeeping of any kind. The arithmetic falls out of
 * the layers; nothing has to remember anything.
 *
 * Consumption beyond every layer produces ONE fallback row priced at the
 * stock average. That is not a failure mode to be hidden — a machine that
 * burnt more than was ever scanned into it is exactly the signal the scan
 * discipline exists to raise, and the row says so in rate_source.
 *
 * ===================== WHICH LINES ARE "RESIN" ======================
 *
 * THE SCAN TRAIL DECIDES, and nothing else does. A consumption line is
 * layer-allocated if and only if that (machine, item) pair has Load rows.
 *
 * This module refuses to identify materials by name — see
 * ShiftProductionEntryService::refuseStaleMaterialLines, whose docblock
 * spells out why no name pattern and no suggestion service may ever reach a
 * stored figure. Masterbatch is kg-family too, and is sometimes scanned;
 * a name test would be wrong about it in both directions. The scan trail is
 * evidence rather than inference: material that was scanned into this
 * machine has layers and is costed from them, material that was not has no
 * layers and is priced exactly as materialCost() prices it today.
 *
 * Under this phase's scope (barcode traceability for resin bags only) that
 * puts resin in the layer bucket and everything else — masterbatch,
 * packaging, other — in the second, without anyone naming an item. If the
 * factory later scans masterbatch bags too, it starts being costed from its
 * own bags on the day the scanning starts, which is the correct behaviour
 * and requires no code change.
 *
 * Every consumption line lands in EXACTLY ONE bucket. The split is derived
 * from the allocation rows that were actually written (not from a fresh
 * layer query at read time), so a bag scanned AFTER a batch completed can
 * never silently move that batch's line out of `other_cost` and into a
 * `resin_cost` that has no rows behind it.
 *
 * One consequence of deriving it that way, noted so nobody "fixes" it: a
 * line of a scanned material whose quantity is zero writes no allocation
 * rows, so its item is absent from the resin bucket and the line is counted
 * under `other_cost`. Both buckets price it at zero, so no figure moves —
 * it is a labelling edge on an empty line, not an error.
 *
 * ======================= CORRECTIONS REPLACE =======================
 *
 * Nothing here is ever deleted. A correction stamps reversed_at on the live
 * run (reverse(), called from reverseCompletionEffects) and the re-run
 * completion writes run N+1 beside it. At most ONE run per entry may be
 * un-reversed, and allocate() refuses rather than allowing a second — a
 * double allocation would charge a batch for its resin twice and would be
 * invisible in every total.
 *
 * Because a reversed row is excluded from every layer-remaining sum, an
 * amended batch gives its layers back automatically: the KILOGRAMS after a
 * correction equal a world in which the wrong completion never happened,
 * while the audit trail still records that it did. The RATE attribution is
 * looser, and honestly so: re-allocating an older batch walks the layers as
 * they stand now, which can include bags loaded after that batch physically
 * ran — a known, accepted tradeoff of not keeping per-batch layer
 * snapshots, not a bug the arithmetic hides.
 *
 * ========================== PRICES ARE FROZEN ==========================
 *
 * rate_per_kg is copied at allocation time and never re-read. A GRN cost is
 * PROVISIONAL until purchase-invoice and landed-cost adjustments land, and
 * a closed batch must never be silently rewritten when they do. A revised
 * rate reaches future batches as a new audited cost version (rate_source
 * 'bag_version'); it does not edit a row that has already been reported.
 *
 * ZERO IS NOT A PRICE anywhere in here — see BagLotRateResolver. A lot with
 * no cost source produces a null rate and rate_source 'unknown', and any
 * total containing an unpriced slice is reported as null rather than as a
 * confident understatement.
 */
class BagCostAllocationService
{
    public function __construct(
        private readonly BagLotRateResolver $rates,
        // Read-only: currentAverageCost for the beyond-layers fallback. This
        // service never WRITES a stock movement — see the boundary note.
        private readonly StockMovementService $stock,
    ) {}

    /**
     * Book this batch's resin consumption against the machine's bag-load
     * layers. Called from completeBatch AFTER the consumption lines exist
     * and inside its transaction, so a completion either books its costs or
     * books nothing.
     *
     * @throws DuplicateAllocationException if a live run already exists
     */
    public function allocate(ShiftProductionEntry $entry, ?int $userId = null): void
    {
        $workCenterId = $entry->work_center_id;

        if ($workCenterId === null) {
            return;
        }

        // ONE ALLOCATOR PER MACHINE AT A TIME. Layer remainders are read
        // with plain SELECTs below, and two transactions reading the same
        // committed remainder would both draw it — an amendment of an older
        // batch on this machine can run at the same wall-clock moment as a
        // different batch completing on it. The work-center row is the lock
        // the module already uses for exactly this class of race
        // (startBatch, MaterialCostVersionService::append); taken here it
        // serialises every allocator touching this machine's layers.
        WorkCenter::query()->whereKey($workCenterId)->lockForUpdate()->first();

        if ($this->liveRows($entry)->exists()) {
            throw DuplicateAllocationException::make($entry->id);
        }

        $run = ((int) BatchResinAllocation::query()
            ->where('shift_production_entry_id', $entry->id)
            ->max('allocation_run')) + 1;

        // Layer state is opened ONCE per item and mutated as slices are
        // drawn, so two consumption lines of the same resin in one batch
        // cannot both draw the same kilogram.
        $state = [];

        foreach ($entry->materialConsumptions()->orderBy('id')->get() as $consumption) {
            $itemId = (int) $consumption->item_id;

            if (! array_key_exists($itemId, $state)) {
                $state[$itemId] = $this->openLayerState((int) $workCenterId, $itemId);
            }

            // THE DISCRIMINATOR: no scan trail, no layer costing. The line is
            // priced by materialCost() as `other_cost` instead.
            if ($state[$itemId]['layers']->isEmpty()) {
                continue;
            }

            $this->drawLine($entry, $consumption, $run, $userId, $state[$itemId]);
        }
    }

    /**
     * Stamp the entry's live allocation rows reversed. Called from
     * reverseCompletionEffects inside the amendment transaction; the re-run
     * completion then allocates as run N+1.
     *
     * Returns how many rows were reversed (0 is normal — a batch whose
     * machine never had a scan has nothing to reverse).
     */
    public function reverse(ShiftProductionEntry $entry): int
    {
        return $this->liveRows($entry)->update(['reversed_at' => now()]);
    }

    /**
     * THE COST OF THIS BATCH, as the screen shows it.
     *
     * resin_cost is the sum of the live layer amounts; other_cost is every
     * consumption line with no layers, priced exactly as materialCost()
     * prices it today (that method is CALLED, never reimplemented — its
     * newest-first movement pairing is subtle and a parallel copy would
     * drift from it).
     *
     * NULL IS A REAL ANSWER and is never a partial figure: if any slice or
     * any line is unpriced, its bucket is null and so is the total. The
     * `reason` field says why in words, so the screen can explain itself
     * rather than showing an empty box.
     *
     * cost_per_accepted_unit divides by quantity_produced, which the quality
     * gate rewrites to the QC-NET figure — so this is cost per ACCEPTED
     * piece, read at read time, and it moves the moment a check nets the
     * batch. That is the intended behaviour: rejected pieces did not stop
     * costing what they cost, so the accepted ones carry the whole bill.
     *
     * $withDetail gates the per-layer breakdown — rates, bag barcodes,
     * supplier lots. Totals and the per-unit figure are for everyone;
     * the identities and the rates behind them are finance's.
     *
     * $materialCost lets a caller that has ALREADY priced this entry hand
     * the result in rather than paying for it twice. The resource does
     * exactly that: it renders `material_cost` from the same computation,
     * and materialCost() is not cheap — it runs a stock_movements query per
     * entry, which on a 20-row approval page is 20 queries, or 40 if this
     * method quietly repeats them. Null means "compute it yourself", so
     * direct callers (and every test) stay one-argument simple.
     *
     * @param  array{lines: list<array<string, mixed>>, total_cost: ?string}|null  $materialCost
     * @return array<string, mixed>
     */
    public function summary(ShiftProductionEntry $entry, bool $withDetail = false, ?array $materialCost = null): array
    {
        $base = [
            'as_of' => now()->toIso8601String(),
            'reason' => null,
            'allocation_run' => null,
            'material_cost_total' => null,
            'resin_cost' => null,
            'other_cost' => null,
            'cost_per_accepted_unit' => null,
            'accepted_quantity' => null,
            'sources' => [
                'resin_cost' => 'bag_load_fifo_layers',
                'other_cost' => 'issue_unit_cost',
                'cost_per_accepted_unit' => 'quantity_produced_qc_net',
            ],
        ];

        if ($entry->batch_status !== BatchStatus::Completed) {
            return [
                ...$base,
                'reason' => 'this batch has not been completed yet, so nothing has been consumed to cost',
            ];
        }

        $rows = $this->liveRows($entry)
            ->when($withDetail, fn ($query) => $query->with([
                'materialBag',
                'materialLot',
                'item' => fn ($relation) => $relation->withTrashed(),
            ]))
            ->orderBy('id')
            ->get();

        // The resin bucket is defined by the rows that were ACTUALLY
        // written, never by re-asking which items have layers now — see the
        // class docblock.
        $layerItemIds = $rows->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->all();

        $resinCost = '0.0000';
        $unpricedSlices = 0;

        foreach ($rows as $row) {
            if ($row->amount === null) {
                $unpricedSlices++;

                continue;
            }

            $resinCost = bcadd($resinCost, (string) $row->amount, 4);
        }

        if ($unpricedSlices > 0) {
            $resinCost = null;
        }

        $otherCost = '0.0000';
        $unpricedLines = 0;
        $otherLines = [];

        $materialCost ??= app(ShiftProductionEntryService::class)->materialCost($entry);

        foreach ($materialCost['lines'] ?? [] as $line) {
            // Filtered on item, which is exactly how the bucket is defined —
            // so two lines sharing an item (or an item and a warehouse) can
            // never be split between the two buckets.
            if (in_array((int) $line['item_id'], $layerItemIds, true)) {
                continue;
            }

            $otherLines[] = $line;

            if ($line['cost'] === null) {
                $unpricedLines++;

                continue;
            }

            $otherCost = bcadd($otherCost, (string) $line['cost'], 4);
        }

        if ($unpricedLines > 0) {
            $otherCost = null;
        }

        $total = ($resinCost !== null && $otherCost !== null)
            ? bcadd($resinCost, $otherCost, 4)
            : null;

        $accepted = $entry->quantity_produced !== null ? (string) $entry->quantity_produced : null;
        $perUnit = null;
        $reason = null;

        if ($total === null) {
            $reason = $unpricedSlices > 0
                ? 'some of the resin drawn came from bags with no recorded purchase rate, so this batch cannot be costed in full'
                : 'some consumed material was issued without a recorded cost, so this batch cannot be costed in full';
        } elseif ($accepted === null || bccomp($accepted, '0', 4) !== 1) {
            $reason = 'this batch has no accepted quantity, so there is nothing to divide the cost across';
        } else {
            $perUnit = bcdiv($total, $accepted, 4);
        }

        $summary = [
            ...$base,
            'reason' => $reason,
            'allocation_run' => $rows->first()?->allocation_run,
            'material_cost_total' => $total,
            'resin_cost' => $resinCost,
            'other_cost' => $otherCost,
            'cost_per_accepted_unit' => $perUnit,
            'accepted_quantity' => $accepted,
        ];

        if ($withDetail) {
            $summary['layers'] = $rows->map(fn (BatchResinAllocation $row) => [
                'day_bin_movement_id' => $row->day_bin_movement_id,
                'item_id' => (int) $row->item_id,
                'item_name' => $row->item?->name,
                'material_bag_id' => $row->material_bag_id,
                'bag_barcode' => $row->materialBag?->barcode,
                'material_lot_id' => $row->material_lot_id,
                'supplier_lot_no' => $row->materialLot?->supplier_lot_no,
                'quantity_kg' => (string) $row->quantity_kg,
                'rate_per_kg' => $row->rate_per_kg !== null ? (string) $row->rate_per_kg : null,
                'amount' => $row->amount !== null ? (string) $row->amount : null,
                'rate_source' => $row->rate_source,
            ])->all();

            $summary['other_lines'] = $otherLines;
        }

        return $summary;
    }

    /**
     * Draw one consumption line from its machine's layers, oldest first,
     * mutating the shared layer state as it goes.
     *
     * @param  array{layers: Collection<int, DayBinMovement>, remaining: array<int, string>}  $state
     */
    private function drawLine(
        ShiftProductionEntry $entry,
        ShiftMaterialConsumption $consumption,
        int $run,
        ?int $userId,
        array &$state,
    ): void {
        $outstanding = bcadd((string) $consumption->quantity_issued_kg, '0', 4);

        if (bccomp($outstanding, '0', 4) !== 1) {
            return;
        }

        foreach ($state['layers'] as $layer) {
            if (bccomp($outstanding, '0', 4) !== 1) {
                break;
            }

            $available = $state['remaining'][$layer->id];

            // An exhausted layer is skipped, not stopped at: an earlier
            // batch may have emptied it while a later one is still open.
            if (bccomp($available, '0', 4) !== 1) {
                continue;
            }

            $draw = bccomp($available, $outstanding, 4) === 1 ? $outstanding : $available;

            $bag = $layer->materialBag;
            $lot = $bag?->lot;
            [$rate, $source] = $this->rates->rateFor($lot);

            BatchResinAllocation::create([
                'shift_production_entry_id' => $entry->id,
                'allocation_run' => $run,
                'day_bin_movement_id' => $layer->id,
                'material_bag_id' => $bag?->id,
                'material_lot_id' => $lot?->id,
                'item_id' => $consumption->item_id,
                'quantity_kg' => $draw,
                'rate_per_kg' => $rate,
                'amount' => $rate !== null ? bcmul($draw, $rate, 4) : null,
                'rate_source' => $source,
                'created_by' => $userId,
            ]);

            $state['remaining'][$layer->id] = bcsub($available, $draw, 4);
            $outstanding = bcsub($outstanding, $draw, 4);
        }

        if (bccomp($outstanding, '0', 4) !== 1) {
            return;
        }

        // BEYOND EVERY LAYER. The machine burnt more than was ever scanned
        // into it, so this surplus has no bag to be priced from and falls
        // back to the ordinary stock average — flagged as such, because the
        // gap between the two is the scan discipline's own error bar.
        //
        // Zero is not a price here either: an average of zero means the bin
        // holds no recorded value, so the slice is recorded unpriced rather
        // than as free material. Same rule as materialCost().
        $average = $this->stock->currentAverageCost(
            (int) $consumption->item_id,
            (int) $consumption->warehouse_id,
        );

        $priced = is_numeric($average) && bccomp((string) $average, '0', 4) === 1;

        BatchResinAllocation::create([
            'shift_production_entry_id' => $entry->id,
            'allocation_run' => $run,
            'day_bin_movement_id' => null,
            'material_bag_id' => null,
            'material_lot_id' => null,
            'item_id' => $consumption->item_id,
            'quantity_kg' => $outstanding,
            'rate_per_kg' => $priced ? bcadd((string) $average, '0', 4) : null,
            'amount' => $priced ? bcmul($outstanding, (string) $average, 4) : null,
            'rate_source' => $priced
                ? BagLotRateResolver::SOURCE_AVERAGE_FALLBACK
                : BagLotRateResolver::SOURCE_UNKNOWN,
            'created_by' => $userId,
        ]);
    }

    /**
     * This machine's layers for one material, in physical FIFO order, with
     * each one's remaining kg.
     *
     * Remaining is accumulated with bcadd in PHP rather than SQL SUM() for
     * the reason machineResinEstimate documents: the test database is
     * SQLite, whose SUM() over a DECIMAL column comes back as a float, and a
     * stock figure that has been through a float is not one this codebase
     * will print.
     *
     * @return array{layers: Collection<int, DayBinMovement>, remaining: array<int, string>}
     */
    private function openLayerState(int $workCenterId, int $itemId): array
    {
        $layers = DayBinMovement::query()
            ->with('materialBag.lot')
            ->where('work_center_id', $workCenterId)
            ->where('item_id', $itemId)
            ->where('type', DayBinMovementType::Load->value)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        if ($layers->isEmpty()) {
            return ['layers' => $layers, 'remaining' => []];
        }

        $drawn = [];

        BatchResinAllocation::query()
            ->live()
            ->whereIn('day_bin_movement_id', $layers->pluck('id'))
            ->get(['day_bin_movement_id', 'quantity_kg'])
            ->each(function (BatchResinAllocation $row) use (&$drawn) {
                $key = (int) $row->day_bin_movement_id;
                $drawn[$key] = bcadd($drawn[$key] ?? '0.0000', (string) $row->quantity_kg, 4);
            });

        // MATERIAL THAT WENT BACK OUT OF THE MACHINE. A Return is not a
        // consumption and costs nothing, but it does mean those kilograms
        // are no longer standing at this machine to be drawn from — and the
        // bag they went back into is in the store holding them again, ready
        // to be scanned somewhere else. Without this the same kilogram could
        // be charged to a batch here AND to a batch wherever it goes next.
        $returned = $this->returnedByLayer($workCenterId, $itemId, $layers);

        $remaining = [];

        foreach ($layers as $layer) {
            $value = bcsub(
                bcsub(
                    bcadd((string) $layer->quantity_kg, '0', 4),
                    $returned[$layer->id] ?? '0.0000',
                    4,
                ),
                $drawn[$layer->id] ?? '0.0000',
                4,
            );

            // Floored, never negative: a layer that has been returned and
            // drawn past its own size is a data error somewhere upstream,
            // and negative capacity would quietly hand the NEXT layer extra
            // kilograms to give away.
            $remaining[$layer->id] = bccomp($value, '0', 4) === 1 ? $value : '0.0000';
        }

        return ['layers' => $layers, 'remaining' => $remaining];
    }

    /**
     * How much of each Load layer has since been returned out of the machine.
     *
     * A return can only come off material that was already standing there
     * when it happened, so only layers recorded at or before the return are
     * eligible — load A, return, load B must come off A, never off B.
     *
     * ATTRIBUTION:
     *   - A return that NAMES A BAG comes off that bag's own layers,
     *     newest-first. Which of one bag's loads it lands on is arbitrary
     *     AND COST-NEUTRAL — same bag, same lot, same rate — so no pricing
     *     policy is being decided here.
     *   - A bagless return (the regrind/return destination is still an open
     *     question for the factory) is absorbed FIFO, the ordering this
     *     module uses everywhere else. This is the one case where the choice
     *     moves which rate a later batch pays, and it is flagged as such
     *     rather than hidden.
     *
     * A return this machine has no matching load for absorbs nothing. It
     * cannot reduce a layer that does not exist, and inventing one would be
     * worse than leaving the surplus to the beyond-layers fallback, which is
     * exactly the signal that case should raise.
     *
     * @param  Collection<int, DayBinMovement>  $layers
     * @return array<int, string>
     */
    private function returnedByLayer(int $workCenterId, int $itemId, Collection $layers): array
    {
        $returns = DayBinMovement::query()
            ->where('work_center_id', $workCenterId)
            ->where('item_id', $itemId)
            ->where('type', DayBinMovementType::Return->value)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['id', 'material_bag_id', 'quantity_kg', 'recorded_at']);

        if ($returns->isEmpty()) {
            return [];
        }

        $absorbed = [];

        foreach ($returns as $return) {
            $outstanding = bcadd((string) $return->quantity_kg, '0', 4);

            $candidates = $layers->filter(
                fn (DayBinMovement $layer) => $layer->recorded_at <= $return->recorded_at,
            );

            if ($return->material_bag_id !== null) {
                $candidates = $candidates
                    ->filter(fn (DayBinMovement $layer) => (int) $layer->material_bag_id === (int) $return->material_bag_id)
                    ->sortByDesc(fn (DayBinMovement $layer) => [$layer->recorded_at, $layer->id]);
            }

            foreach ($candidates as $layer) {
                if (bccomp($outstanding, '0', 4) !== 1) {
                    break;
                }

                $capacity = bcsub(
                    bcadd((string) $layer->quantity_kg, '0', 4),
                    $absorbed[$layer->id] ?? '0.0000',
                    4,
                );

                if (bccomp($capacity, '0', 4) !== 1) {
                    continue;
                }

                $take = bccomp($capacity, $outstanding, 4) === 1 ? $outstanding : $capacity;

                $absorbed[$layer->id] = bcadd($absorbed[$layer->id] ?? '0.0000', $take, 4);
                $outstanding = bcsub($outstanding, $take, 4);
            }
        }

        return $absorbed;
    }

    /** @return Builder<BatchResinAllocation> */
    private function liveRows(ShiftProductionEntry $entry)
    {
        return BatchResinAllocation::query()
            ->live()
            ->where('shift_production_entry_id', $entry->id);
    }
}
