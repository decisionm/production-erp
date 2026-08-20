<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use Illuminate\Console\Command;
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
 *                  packaging variant, or has been produced in a shift entry.
 *                  All three mean the factory MAKES it — the strongest signal
 *                  available, and it is what a sales order is for.
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
                    ->selectRaw("i.id, i.sku, ? , 'produced in a shift entry'", [$finishedGood])
                    ->join('shift_production_entries as spe', 'spe.finished_item_id', '=', 'i.id')
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
            ->get();
    }

    /**
     * @param  array<int, array{id: int, sku: string, category: ItemCategory, reasons: list<array{category: string, because: string}>}>  $clean
     * @param  array<int, array{id: int, sku: string, category: ItemCategory, reasons: list<array{category: string, because: string}>}>  $conflicts
     */
    private function report(array $clean, array $conflicts): void
    {
        $active = Item::query()->where('is_active', true)->count();
        $already = Item::query()->whereNotNull('category')->count();

        $byCategory = [];
        foreach ($clean as $proposal) {
            $byCategory[$proposal['category']->value] = ($byCategory[$proposal['category']->value] ?? 0) + 1;
        }

        $this->info('Proposed from evidence:');
        foreach ($byCategory as $category => $count) {
            $this->line(sprintf('  %-18s %d', $category, $count));
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
        $unknown = max(0, $active - $already - $proposedCount);

        $this->newLine();
        $this->line(sprintf('  active items          %d', $active));
        $this->line(sprintf('  already classified    %d', $already));
        $this->line(sprintf('  proposed here         %d', $proposedCount));
        $this->line(sprintf('  STILL UNCLASSIFIED    %d  <- these need a person; they stay NULL', $unknown));
    }
}
