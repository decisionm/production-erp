<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use App\Modules\Inventory\Services\ItemIdentityService;
use App\Modules\Production\Models\Enums\BatchStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PROPOSE an item category from evidence the database already holds, and
 * write it only when told to.
 *
 * The factory's rules need a category on every item: a purchase order is
 * raised for raw and packing material, a sales order for finished goods, a
 * store request for production input. 624 active items have no category and
 * a person cannot classify 624 rows by hand in a day.
 *
 * So this command turns an AUTHORING job into a REVIEW job. It proposes only
 * what the data can actually support, refuses to guess the rest, and prints
 * both lists. Counted on live 20-Aug: about 166 of 624 are derivable; 458 are
 * not, and those stay NULL. `AGENTS.md` — a missing figure is reported
 * missing, never interpolated.
 *
 * DRY RUN IS THE DEFAULT. Nothing is written without --write, matching every
 * other master-data path in this repo.
 *
 * WHAT COUNTS AS EVIDENCE, and why each is sound rather than convenient:
 *
 *   FinishedGood   the item carries a production standard, or is named by a
 *                  packaging variant, or has been produced in a COMPLETED
 *                  shift entry (a cancelled or still-running batch has made
 *                  nothing, so it proves nothing). All three mean the factory
 *                  MAKES it — the strongest signal available, and it is what a
 *                  sales order is for.
 *
 *   PackingMaterial the item appears in a packing-material mapping. That
 *                  mapping exists precisely to say "this is what a product is
 *                  packed in", so it is a definition, not an inference.
 *
 *   RawMaterial    the item is dosed as a masterbatch. Again a definition.
 *
 * Deliberately NOT used as evidence:
 *
 *   `is_production_input` alone cannot separate raw from packing — it is one
 *   flag covering both — so it would have to guess which, and guessing is the
 *   thing this command exists to avoid. It is reported alongside the proposal
 *   so a reviewer can see it, and it is never the basis of one.
 *
 *   The UOM. `MeasurementType`'s own docblock warns packing film is measured
 *   in kilograms and is not resin. Classifying by unit would file every film
 *   as raw material.
 *
 *   The item NAME. Reading "tray" out of a name is the `LOCAL-` prefix
 *   mistake again: a fact derived from free text that a document rule then
 *   acts on.
 *
 * A CONFLICT IS REPORTED, NEVER RESOLVED. An item that is both packed-with
 * and produced has contradictory evidence, and which one it really is
 * belongs to the factory.
 */
class ClassifyItems extends Command
{
    protected $signature = 'inventory:classify-items
        {--write : Actually write (default is a dry run)}';

    protected $description = 'Propose an item category from existing evidence; dry run unless --write.';

    public function handle(): int
    {
        $proposals = $this->propose();
        $conflicts = array_filter($proposals, fn (array $p): bool => count($p['reasons']) > 1
            && count(array_unique(array_column($p['reasons'], 'category'))) > 1);

        $clean = array_filter($proposals, fn (array $p): bool => ! isset($conflicts[$p['id']]));

        $this->report($clean, $conflicts);

        if (! $this->option('write')) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --write to apply the proposals above.');

            return self::SUCCESS;
        }

        if ($conflicts !== []) {
            $this->newLine();
            $this->error(sprintf(
                '%d item(s) carry contradictory evidence and are NOT written. Resolve them by hand first.',
                count($conflicts),
            ));
        }

        $written = 0;

        foreach ($clean as $proposal) {
            $item = Item::find($proposal['id']);

            // Never overwrite a category a person already set. The proposal is
            // evidence; a human answer outranks it (SOURCE-PRIORITY).
            if ($item === null || $item->category !== null) {
                continue;
            }

            $item->update(['category' => $proposal['category']]);
            $written++;
        }

        $this->newLine();
        $this->info(sprintf('Wrote %d categor%s.', $written, $written === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }

    /**
     * Every item the evidence can speak to, with the reasons it can.
     *
     * @return array<int, array{id: int, sku: string, category: ItemCategory, reasons: list<array{category: string, because: string}>}>
     */
    private function propose(): array
    {
        $proposals = [];

        foreach ($this->evidence() as $row) {
            $id = (int) $row->item_id;

            $proposals[$id] ??= [
                'id' => $id,
                'sku' => (string) $row->sku,
                'category' => ItemCategory::from($row->category),
                'reasons' => [],
            ];

            $proposals[$id]['reasons'][] = [
                'category' => $row->category,
                'because' => $row->because,
            ];
        }

        return $proposals;
    }

    /**
     * One row per (item, piece of evidence). Written as a UNION of definitions
     * rather than a heuristic, so every row here can name the table that
     * asserts it.
     */
    private function evidence(): Collection
    {
        $finishedGood = ItemCategory::FinishedGood->value;
        $packing = ItemCategory::PackingMaterial->value;
        $raw = ItemCategory::RawMaterial->value;

        return DB::table('items as i')
            ->selectRaw("i.id as item_id, i.sku, ? as category, 'carries a production standard' as because", [$finishedGood])
            ->join('production_standards as ps', 'ps.item_id', '=', 'i.id')
            ->whereNull('i.deleted_at')
            ->union(
                DB::table('items as i')
                    ->selectRaw("i.id, i.sku, ? , 'named by a packaging variant'", [$finishedGood])
                    ->join('production_standard_packagings as psp', 'psp.item_id', '=', 'i.id')
                    ->whereNull('i.deleted_at')
            )
            ->union(
                DB::table('items as i')
                    ->selectRaw("i.id, i.sku, ? , 'produced in a completed shift entry'", [$finishedGood])
                    ->join('shift_production_entries as spe', 'spe.finished_item_id', '=', 'i.id')
                    // "the factory MAKES it" is a claim about production that
                    // actually happened. A batch still `in_progress` has
                    // produced nothing yet, and a `cancelled` one was withdrawn
                    // as a mistake and had its stock reversed — neither is
                    // evidence, and a batch entered against the wrong item and
                    // then cancelled is precisely how a wrong category would
                    // get written. Only `completed` survives. BatchStatus has
                    // exactly these three cases, so naming the one that counts
                    // excludes the other two by construction.
                    ->where('spe.batch_status', '=', BatchStatus::Completed->value)
                    // Belt-and-braces for legacy or manually repaired rows:
                    // a cancellation timestamp is terminal evidence even if a
                    // stale batch_status was not changed with it.
                    ->whereNull('spe.cancelled_at')
                    ->whereNull('i.deleted_at')
            )
            ->union(
                DB::table('items as i')
                    ->selectRaw("i.id, i.sku, ? , 'appears in a packing-material mapping'", [$packing])
                    ->join('packing_material_mappings as pmm', 'pmm.item_id', '=', 'i.id')
                    ->whereNull('i.deleted_at')
                    ->whereNull('pmm.deleted_at')
            )
            ->union(
                DB::table('items as i')
                    ->selectRaw("i.id, i.sku, ? , 'dosed as a masterbatch'", [$raw])
                    ->join('masterbatch_dosings as md', 'md.masterbatch_item_id', '=', 'i.id')
                    ->whereNull('i.deleted_at')
                    ->whereNull('md.deleted_at')
            )
            ->union($this->stockGroupEvidence())
            ->get();
    }

    /**
     * THE TALLY STOCK GROUP, which the owner made evidence.
     *
     * Until DEC-20260827-001 this command could only classify the ~166 items
     * the ERP's own tables happened to describe; the other 458 stayed NULL
     * because nothing in the database could say what they were. The group
     * tree could always say — the factory files every item under Finished
     * Goods, Packing Material, Raw Material, Master Batch, Caps & Closures or
     * Scrap — but which ERP category each group MEANT was Q60, an owner
     * question, and reading it early would have been the guess this command
     * exists to avoid.
     *
     * With the answer recorded the group becomes a definition like any other
     * row in this union, and it is deliberately just one more piece of
     * evidence: an item whose group disagrees with its production standard
     * lands in the CONFLICTS list and is reported, never silently resolved.
     *
     * `ItemIdentityService` holds the same mapping for the read-only warning
     * surface and this reads it from there, so the screen and the write can
     * never drift into two answers. Groups the decision left unmapped — and
     * items in no group at all — produce no row here and stay NULL.
     */
    private function stockGroupEvidence(): Builder
    {
        $identity = app(ItemIdentityService::class);

        $cases = [];
        $bindings = [];

        foreach (ItemGroup::query()->get(['id', 'name']) as $group) {
            $suggestion = $identity->suggestedCategoryForGroupName($group->name);

            if ($suggestion === null) {
                continue;
            }

            $cases[] = 'WHEN i.item_group_id = ? THEN ?';
            $bindings[] = $group->id;
            $bindings[] = $suggestion->value;
        }

        $query = DB::table('items as i')
            ->join('item_groups as g', 'g.id', '=', 'i.item_group_id')
            ->whereNull('i.deleted_at');

        if ($cases === []) {
            // No mapped group in this database — a query that selects nothing,
            // rather than a CASE with no branches (which is a syntax error).
            return $query->selectRaw("i.id, i.sku, ? , 'its Tally stock group'", [ItemCategory::Other->value])
                ->whereRaw('1 = 0');
        }

        return $query
            ->selectRaw(
                'i.id, i.sku, CASE '.implode(' ', $cases).' END, '
                    ."CONCAT('its Tally stock group ', g.name)",
                $bindings,
            )
            ->whereIn('i.item_group_id', array_values(array_filter(
                ItemGroup::query()->pluck('id', 'name')->all(),
                fn ($id, $name) => $identity->suggestedCategoryForGroupName($name) !== null,
                ARRAY_FILTER_USE_BOTH,
            )));
    }

    /**
     * @param  array<int, array{id: int, sku: string, category: ItemCategory, reasons: list<array{category: string, because: string}>}>  $clean
     * @param  array<int, array{id: int, sku: string, category: ItemCategory, reasons: list<array{category: string, because: string}>}>  $conflicts
     */
    private function report(array $clean, array $conflicts): void
    {
        $active = Item::query()->where('is_active', true)->count();
        $already = Item::query()->where('is_active', true)->whereNotNull('category')->count();
        $unclassified = Item::query()->where('is_active', true)->whereNull('category')->count();

        // WHICH proposals --write would actually apply. handle() skips only a
        // row whose category a person already set; it still classifies inactive
        // historical masters, and the dry run must say that truthfully.
        $writableIds = Item::query()
            ->whereIn('id', array_column($clean, 'id'))
            ->whereNull('category')
            ->pluck('id')
            ->all();
        $writable = array_flip($writableIds);

        // STILL UNCLASSIFIED is deliberately about ACTIVE masters only, so its
        // subtraction uses the narrower active-and-null intersection.
        $activeApplicableIds = Item::query()
            ->whereIn('id', $writableIds)
            ->where('is_active', true)
            ->pluck('id')
            ->all();
        $activeApplicable = array_flip($activeApplicableIds);

        $byCategory = [];
        foreach ($clean as $proposal) {
            $byCategory[$proposal['category']->value] = ($byCategory[$proposal['category']->value] ?? 0) + 1;
        }

        $this->info('Proposed from evidence:');
        foreach ($byCategory as $category => $count) {
            $this->line(sprintf('  %-18s %d', $category, $count));
        }

        // EVERY clean proposal, named, BEFORE --write could act on one. Counts
        // alone turn this back into an authoring job done blind: a reviewer
        // asked to approve "166 finished goods" cannot approve anything, and
        // the one row that is wrong is invisible until it has been written.
        // The conflicts below have always printed in full; the rows that WILL
        // be written are the ones that most needed it.
        if ($clean !== []) {
            $this->newLine();
            $this->line('Every proposal, with the evidence behind it:');

            foreach ($clean as $proposal) {
                $this->line(sprintf(
                    '  #%-6d %-32s -> %-18s%s',
                    $proposal['id'],
                    $proposal['sku'],
                    $proposal['category']->value,
                    ! isset($writable[$proposal['id']])
                        ? '  (no change: already classified)'
                        : (isset($activeApplicable[$proposal['id']]) ? '' : '  (would be written; item is inactive)'),
                ));

                foreach ($proposal['reasons'] as $reason) {
                    $this->line(sprintf('           %s', $reason['because']));
                }
            }
        }

        if ($conflicts !== []) {
            $this->newLine();
            $this->error('CONTRADICTORY EVIDENCE — reported, never resolved. These need a person:');
            foreach ($conflicts as $conflict) {
                $this->line(sprintf('  %s', $conflict['sku']));
                foreach ($conflict['reasons'] as $reason) {
                    $this->line(sprintf('      %-18s %s', $reason['category'], $reason['because']));
                }
            }
        }

        $proposedCount = count($clean);
        $writableCount = count($writableIds);
        $activeApplicableCount = count($activeApplicableIds);

        // ONE subtraction, from the population the line is about. This used to
        // be `$active - $already - $proposedCount`, which subtracted twice over
        // and from the wrong sets: `$already` counted classified items whether
        // active or not, and `$proposedCount` included proposals for inactive
        // and for already-classified items — rows that are not in the
        // active-and-NULL population and that --write would not touch anyway.
        // On live that under-reports the number of items still needing a
        // person, which is the one figure this command exists to be honest
        // about. `max(0, ...)` is gone with it: it was hiding the error.
        $unknown = $unclassified - $activeApplicableCount;

        $this->newLine();
        $this->line(sprintf('  active items          %d', $active));
        $this->line(sprintf('  already classified    %d  (active only)', $already));
        $this->line(sprintf(
            '  proposed here         %d%s',
            $proposedCount,
            $proposedCount === $writableCount
                ? sprintf('  (%d active)', $activeApplicableCount)
                : sprintf('  (%d would be written; %d active)', $writableCount, $activeApplicableCount),
        ));
        $this->line(sprintf('  STILL UNCLASSIFIED    %d  <- these need a person; they stay NULL', $unknown));
    }
}
