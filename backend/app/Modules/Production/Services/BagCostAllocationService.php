<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Exceptions\DuplicateAllocationException;
use App\Modules\Production\Models\BatchResinAllocation;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHAT A BATCH'S RESIN COST — an ACCOUNTING ALLOCATION at the common resin
 * pool's weighted average, beside the stock ledger and never inside it.
 *
 * ==================== THE CLAIM THAT DIED, AND WHY ====================
 *
 * This class used to walk bag-load FIFO layers per machine and report which
 * BAGS a batch burnt. The owner's correction (2-Aug) removed the ground that
 * stood on: the factory has ONE COMMON resin input point serving every
 * machine, a bag is never assigned or scanned to a machine, and there is
 * therefore no physical path from a bag to a batch to trace. The old answer
 * was not merely imprecise — it named specific bag barcodes and supplier
 * lots against specific batches, which is the most confidently wrong thing a
 * costing screen can do.
 *
 * So the layers are gone, the per-machine FIFO walk is gone, the returns
 * netting that existed to keep layers honest is gone, and the per-batch bag
 * and lot identities are gone from every payload. What replaced them is one
 * WEIGHTED AVERAGE PER EXACT MATERIAL (ResinPoolService): kg entering the
 * common input fold into that material's pool at their own purchase rate,
 * and a batch's consumption is drawn from the pool at the pool's average.
 *
 * THAT IS A REAL COSTING BASIS AND IT SAYS WHAT IT IS. Every surface that
 * shows this figure carries the sentence "Accounting allocation at the common
 * resin pool's weighted average — not physical bag traceability", and it is
 * shown to EVERYONE, not gated behind finance, because the disclaimer is not
 * an anatomy detail — it is what the number means.
 *
 * NEVER ACROSS MATERIALS. One pool per item_id, enforced by a unique index.
 * Two grades of the same polymer are two items and two pools; blending them
 * would produce a rate that prices nothing that exists.
 *
 * ============================ THE BOUNDARY ============================
 *
 * Unchanged, and still the whole design. This service does not touch
 * StockMovementService and does not touch stock_balances.average_cost.
 * completeBatch books stock exactly as it did before this class existed. The
 * Accounts-approved Tally valuation — moving average on the stock ledger —
 * stays EXACTLY as is, and pool rates never reach Tally at all (its voucher
 * lines carry quantities only). Two questions, two mechanisms, one of them
 * deliberately inert.
 *
 * ===================== WHICH LINES ARE POOL-COSTED =====================
 *
 * THE LOAD TRAIL DECIDES, and nothing else does. A consumption line is
 * pool-costed if and only if that material has a pool — i.e. if and only if
 * something has been loaded into the common input for it at least once.
 *
 * This module refuses to identify materials by name — see
 * ShiftProductionEntryService::refuseStaleMaterialLines, whose docblock
 * spells out why no name pattern and no suggestion service may ever reach a
 * stored figure. Masterbatch is kg-family too and is sometimes loaded; a name
 * test would be wrong about it in both directions. Material that was loaded
 * has a pool and is costed from it; material that was not has none and is
 * priced exactly as materialCost() prices it today.
 *
 * Every consumption line lands in EXACTLY ONE bucket, and the split is
 * derived from the allocation rows that were ACTUALLY WRITTEN (not from a
 * fresh pool query at read time), so material loaded AFTER a batch completed
 * can never silently move that batch's line between buckets. One consequence,
 * noted so nobody "fixes" it: a line of a pooled material whose quantity is
 * zero writes no rows, so its item is absent from the resin bucket and the
 * line is counted under `other_cost`. Both buckets price it at zero, so no
 * figure moves — a labelling edge on an empty line, not an error.
 *
 * ================= A KNOWN ASYMMETRY, WRITTEN DOWN =================
 *
 * The common-input estimate (FactoryDayBinService::commonResinEstimate) sums
 * EVERY Load row, including the legacy per-machine bin-bay path
 * (day-bin/load), which this wave deliberately did not touch. The POOL is
 * folded only by the common-input scan. So kg loaded through the bin-bay path
 * count as loaded in the estimate but were never priced into the pool, and
 * consumption covering them reaches the labelled stock-average fallback
 * sooner than the estimate suggests.
 *
 * That is not hidden and it is not a bug: the estimate answers a KILOGRAM
 * question and the pool answers a MONEY one, exactly the split this module
 * has always kept between the stock ledger and this analytic layer. It is
 * recorded here so it is found by reading rather than by discovering.
 *
 * ======================= CORRECTIONS REPLACE =======================
 *
 * Nothing here is ever deleted. A correction stamps reversed_at on the live
 * run (reverse(), called from reverseCompletionEffects) AND GIVES THE KG BACK
 * TO THE POOL AT THE ALLOCATION'S OWN FROZEN RATE — the same reversal
 * semantics the stock ledger uses, so an amendment can never quietly re-price
 * material it never touched. The re-run completion then writes run N+1 beside
 * it and draws again. At most ONE run per entry may be un-reversed, and
 * allocate() refuses rather than allowing a second: a double allocation would
 * charge a batch for its resin twice and would be invisible in every total.
 *
 * The pool after "wrong completion, then amendment" therefore holds exactly
 * what a never-wrong world would have left in it, as long as nothing else
 * moved the pool in between — and when something did, the arithmetic still
 * reflects that those movements really happened.
 *
 * ========================== PRICES ARE FROZEN ==========================
 *
 * rate_per_kg is copied at allocation time and never re-read. A GRN cost is
 * PROVISIONAL until purchase-invoice and landed-cost adjustments land, and a
 * closed batch must never be silently rewritten when they do. A revised rate
 * reaches the pool as the next load folds it in; it does not edit a row that
 * has already been reported.
 *
 * ZERO IS NOT A PRICE anywhere in here. A pool holding no priced material
 * draws nothing, the consumption falls to the stock average labelled
 * 'average_fallback', and where there is no stock average either the slice is
 * recorded unpriced ('unknown', null rate) and any total containing it is
 * reported as null rather than as a confident understatement.
 */
class BagCostAllocationService
{
    /**
     * The one sentence this figure must never be shown without. It is a
     * constant rather than a literal at each call site because it is the
     * claim being made, and a claim that drifts between screens is a claim
     * nobody can hold anyone to.
     */
    public const ALLOCATION_SENTENCE = 'Accounting allocation at the common resin pool\'s weighted average — not physical bag traceability';

    /** rate_source for a slice drawn from the pool at its running average. */
    public const SOURCE_POOL_AVERAGE = 'pool_weighted_average';

    public function __construct(
        private readonly ResinPoolService $pool,
        // Read-only: currentAverageCost for the beyond-pool fallback. This
        // service never WRITES a stock movement — see the boundary note.
        private readonly StockMovementService $stock,
    ) {}

    /**
     * Book this batch's resin consumption against the common pool. Called
     * from completeBatch AFTER the consumption lines exist and inside its
     * transaction, so a completion either books its costs or books nothing.
     *
     * @throws DuplicateAllocationException if a live run already exists
     */
    public function allocate(ShiftProductionEntry $entry, ?int $userId = null): void
    {
        // ONE ALLOCATOR PER BATCH. The entry row is the natural mutex for
        // "at most one live run per entry" — the pool locks below serialise
        // the MONEY, but two allocators for the SAME entry that happened to
        // touch no common material would both pass the duplicate check
        // without this.
        ShiftProductionEntry::query()->whereKey($entry->id)->lockForUpdate()->first();

        if ($this->liveRows($entry)->exists()) {
            throw DuplicateAllocationException::make($entry->id);
        }

        // ONE DRAW PER MATERIAL PER RUN, so two consumption lines of the same
        // resin in one batch cannot draw the pool twice at two different
        // averages. Ordered by item id, which is also the order the pool row
        // locks are taken in — a fixed order is what stops two allocators
        // deadlocking on the same pair of pools in opposite directions.
        $lines = [];

        foreach ($entry->materialConsumptions()->orderBy('id')->get() as $consumption) {
            $itemId = (int) $consumption->item_id;

            $lines[$itemId] ??= ['quantity' => '0.0000', 'warehouse_id' => (int) $consumption->warehouse_id];
            $lines[$itemId]['quantity'] = bcadd(
                $lines[$itemId]['quantity'],
                (string) $consumption->quantity_issued_kg,
                4,
            );
        }

        ksort($lines);

        $run = ((int) BatchResinAllocation::query()
            ->where('shift_production_entry_id', $entry->id)
            ->max('allocation_run')) + 1;

        foreach ($lines as $itemId => $line) {
            // THE DISCRIMINATOR: no load trail, no pool costing. The line is
            // priced by materialCost() as `other_cost` instead.
            if (! $this->pool->exists($itemId)) {
                continue;
            }

            $this->drawItem($entry, $itemId, $line['quantity'], $line['warehouse_id'], $run, $userId);
        }
    }

    /**
     * Stamp the entry's live allocation rows reversed AND give their kg back
     * to the pool at their own frozen rates. Called from
     * reverseCompletionEffects inside the amendment transaction; the re-run
     * completion then allocates as run N+1.
     *
     * Returns how many rows were reversed (0 is normal — a batch of material
     * that was never loaded into the common input has nothing to reverse).
     */
    public function reverse(ShiftProductionEntry $entry): int
    {
        $rows = $this->liveRows($entry)
            // Ascending item id: the same fixed pool-lock order allocate()
            // uses, for the same deadlock reason.
            ->orderBy('item_id')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            // Only what was actually TAKEN FROM THE POOL goes back into it. A
            // beyond-pool fallback slice was never in the pool — putting it
            // back would invent material the common input never held.
            if ($row->rate_source !== self::SOURCE_POOL_AVERAGE) {
                continue;
            }

            $this->pool->restore(
                (int) $row->item_id,
                (string) $row->quantity_kg,
                $row->rate_per_kg !== null ? (string) $row->rate_per_kg : null,
            );
        }

        return $this->liveRows($entry)->update(['reversed_at' => now()]);
    }

    /**
     * THE COST OF THIS BATCH, as the screen shows it.
     *
     * resin_cost is the sum of the live allocation amounts; other_cost is
     * every consumption line with no pool, priced exactly as materialCost()
     * prices it today (that method is CALLED, never reimplemented — its
     * newest-first movement pairing is subtle and a parallel copy would drift
     * from it).
     *
     * NULL IS A REAL ANSWER and is never a partial figure: if any slice or
     * any line is unpriced, its bucket is null and so is the total. The
     * `reason` field says why in words, so the screen can explain itself
     * rather than showing an empty box.
     *
     * `basis` carries the accounting-allocation sentence FOR EVERYONE. It is
     * not gated with the detail below, because it is not anatomy — it is what
     * the number means, and a reader shown the figure without it would be
     * shown a claim this module does not make.
     *
     * cost_per_accepted_unit divides by quantity_produced, which the quality
     * gate rewrites to the QC-NET figure — so this is cost per ACCEPTED
     * piece, read at read time, and it moves the moment a check nets the
     * batch. Intended: rejected pieces did not stop costing what they cost,
     * so the accepted ones carry the whole bill.
     *
     * $withDetail gates the per-material breakdown — the pool rate and the
     * amount drawn. Totals and the per-unit figure are for everyone; the
     * rates behind them are finance's. THERE ARE NO BAG OR LOT IDENTITIES IN
     * IT ANY MORE, at any permission level, because that claim is dead.
     *
     * $materialCost lets a caller that has ALREADY priced this entry hand the
     * result in rather than paying for it twice. The resource does exactly
     * that: it renders `material_cost` from the same computation, and
     * materialCost() is not cheap — it runs a stock_movements query per
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
            'basis' => self::ALLOCATION_SENTENCE,
            'sources' => [
                'resin_cost' => 'common_resin_pool_weighted_average',
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
                'item' => fn ($relation) => $relation->withTrashed(),
            ]))
            ->orderBy('id')
            ->get();

        // The resin bucket is defined by the rows that were ACTUALLY written,
        // never by re-asking which items have a pool now — see the docblock.
        $pooledItemIds = $rows->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->all();

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
            if (in_array((int) $line['item_id'], $pooledItemIds, true)) {
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
                ? 'some of the resin drawn had no rate in the common pool and no stock average to fall back on, so this batch cannot be costed in full'
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
            $summary['allocations'] = $rows->map(fn (BatchResinAllocation $row) => [
                'item_id' => (int) $row->item_id,
                'item_name' => $row->item?->name,
                'pool_rate' => $row->rate_per_kg !== null ? (string) $row->rate_per_kg : null,
                'quantity' => (string) $row->quantity_kg,
                'amount' => $row->amount !== null ? (string) $row->amount : null,
                // 'pool_weighted_average' | 'average_fallback' | 'unknown'.
                // The pool rate above is whatever THAT source produced, and
                // this field is how a reader tells the three apart.
                'rate_source' => $row->rate_source,
                'sentence' => self::ALLOCATION_SENTENCE,
            ])->all();

            $summary['other_lines'] = $otherLines;
        }

        return $summary;
    }

    /**
     * Draw one material's whole consumption for this batch from its pool,
     * writing at most two rows: what the pool covered, and what it did not.
     */
    private function drawItem(
        ShiftProductionEntry $entry,
        int $itemId,
        string $quantityKg,
        int $warehouseId,
        int $run,
        ?int $userId,
    ): void {
        if (bccomp($quantityKg, '0', 4) !== 1) {
            return;
        }

        [$drawn, $rate, $uncovered] = $this->pool->draw($itemId, $quantityKg);

        if (bccomp($drawn, '0', 4) === 1 && $rate !== null) {
            $this->row($entry, $itemId, $run, $userId, $drawn, $rate, self::SOURCE_POOL_AVERAGE);
        }

        if (bccomp($uncovered, '0', 4) !== 1) {
            return;
        }

        // BEYOND THE POOL. The factory burnt more of this material than the
        // common input was ever priced for, so this surplus has no pool rate
        // to draw at and falls back to the ordinary stock average — flagged
        // as such, because the gap between the two is the load discipline's
        // own error bar. It is also where UNPRICED material lands: kg folded
        // in from a lot with no recorded rate are deliberately kept out of
        // the average (ResinPoolService), so the consumption they should have
        // covered arrives here and is labelled instead of being priced at a
        // number nobody knows.
        //
        // Zero is not a price here either: an average of zero means the bin
        // holds no recorded value, so the slice is recorded unpriced rather
        // than as free material. Same rule as materialCost().
        $average = $this->stock->currentAverageCost($itemId, $warehouseId);
        $priced = is_numeric($average) && bccomp((string) $average, '0', 4) === 1;

        $this->row(
            $entry,
            $itemId,
            $run,
            $userId,
            $uncovered,
            $priced ? bcadd((string) $average, '0', 4) : null,
            $priced ? BagLotRateResolver::SOURCE_AVERAGE_FALLBACK : BagLotRateResolver::SOURCE_UNKNOWN,
        );
    }

    /**
     * One allocation row. Bag, lot and day-bin-movement identities are
     * ALWAYS null now: a pool draw has no bag behind it, and writing one
     * would resurrect exactly the claim the owner's correction removed. The
     * columns stay for the history already written under the old model.
     */
    private function row(
        ShiftProductionEntry $entry,
        int $itemId,
        int $run,
        ?int $userId,
        string $quantityKg,
        ?string $rate,
        string $source,
    ): void {
        BatchResinAllocation::create([
            'shift_production_entry_id' => $entry->id,
            'allocation_run' => $run,
            'day_bin_movement_id' => null,
            'material_bag_id' => null,
            'material_lot_id' => null,
            'item_id' => $itemId,
            'quantity_kg' => $quantityKg,
            'rate_per_kg' => $rate,
            'amount' => $rate !== null ? bcmul($quantityKg, $rate, 4) : null,
            'rate_source' => $source,
            'created_by' => $userId,
        ]);
    }

    /** @return Builder<BatchResinAllocation> */
    private function liveRows(ShiftProductionEntry $entry)
    {
        return BatchResinAllocation::query()
            ->live()
            ->where('shift_production_entry_id', $entry->id);
    }
}
