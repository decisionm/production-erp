<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WHICH MATERIALS A RUN MAY BOOK AS ACTUAL CONSUMPTION — the controlled list
 * behind the completion drawer's "add a material" dropdown, and the same list
 * the server refuses against.
 *
 * Two sets, and the difference between them is the whole point:
 *
 *   EXPECTED  what this run was planned to consume — the product's BOM
 *             components, the resin and masterbatch resolved for the run, the
 *             packing materials its standard maps to, and what this product
 *             has actually been made with on earlier completed batches. A line
 *             naming one of these is an ordinary completion line and needs
 *             nothing extra.
 *
 *   ALLOWED   every ACTIVE master except the ones that positively are not an
 *             input — items recorded as a FINISHED GOOD, and the product this
 *             run is making — plus everything in EXPECTED, which is added back
 *             unconditionally so a run is never refused the material it was
 *             planned on because somebody deactivated the master.
 *
 * WHY ALLOWED IS THIS WIDE, WHICH LOOKS LIKE A GAP AND IS NOT. The obvious
 * list is "raw material, packing material and consumable" read off
 * `items.category`. DEC-20260827-001 forbids exactly that: it classified the
 * catalogue and then said, in terms, that the classification "does NOT switch
 * on any enforcement — which categories each document may use is Q59 and stays
 * open, so no purchase order, sales order or material request begins refusing
 * anything as a result". Twelve live masters are deliberately NULL, meaning
 * "not recorded yet" and never "none of the above" (DEC-20260827-002), and a
 * category filter would refuse every one of them at the completion drawer —
 * answering Q59 by accident, on a screen, in the owner's absence.
 *
 * So the refusal is narrowed to the one thing the task states outright and the
 * factory's own vouchers confirm: a product is not its own input. In the two
 * Stock Journals held in `tests/fixtures/tally/production-stock-journals.xml`
 * no bottle appears on an OUT line — that is the extent of what was checked,
 * two vouchers of a 34-voucher export, not all 34. Whether spares, tooling and
 * unclassified masters should also be refused is Q59's and Q90's to answer,
 * and widening this list is a one-line change on the day it is.
 *
 * A line naming an ALLOWED-but-not-EXPECTED material is an ADDED line: the
 * 100 ml cartons ran out and today's run goes in a 90 ml box. It is recorded
 * with a reason and the person who authorised it, and it is ADDITIVE — nothing
 * anywhere reduces or clears the material it stood in for. That is the owner's
 * answer, 01-Sep-2026, and it is why no method here returns anything to
 * subtract.
 *
 * Everything outside ALLOWED is refused. Finished goods, scrap, spares and
 * un-categorised masters are not consumption; before this list, the completion
 * payload accepted any `exists:items,id` at all, so a finished bottle could be
 * booked as its own input and reach a Tally voucher as one.
 *
 * ADVISORY ABOUT EXPECTED, ABSOLUTE ABOUT ALLOWED. `expectedItemIds()` is a
 * plan and may legitimately be empty (no BOM, no standard, an ambiguous
 * masterbatch — RunMaterialSuggestionService returns null rather than guessing).
 * An empty EXPECTED makes every line an ADDED line, which is the honest reading:
 * nothing was planned, so nothing on the list was planned for. It never widens
 * or narrows ALLOWED.
 */
class RunConsumableOptionsService
{
    /**
     * The one category a run may never draw an input from. Not a whitelist —
     * see the class docblock for why a whitelist would answer Q59 by accident.
     */
    private const NOT_AN_INPUT = ItemCategory::FinishedGood;

    public function __construct(
        private readonly BomService $boms,
        private readonly RunMaterialSuggestionService $suggestions,
        private readonly PackingMaterialSuggestionService $packing,
    ) {}

    /**
     * The materials this run was planned to consume.
     *
     * @return array<int, int> item ids, ascending
     */
    public function expectedItemIds(ShiftProductionEntry $entry): array
    {
        $product = $entry->item;

        if ($product === null) {
            return [];
        }

        $ids = [];

        foreach ($this->boms->activeFor((int) $product->id)?->lines ?? [] as $line) {
            $ids[] = (int) $line->component_item_id;
        }

        $run = $this->suggestions->forRun(
            $product,
            $entry->productionConfiguration,
            $entry->productionStandard,
        );

        // Both suggesters answer with an `item` block that is NULL when they
        // could not resolve one — two candidates is not an answer, and neither
        // is a missing mapping. A null contributes nothing to EXPECTED rather
        // than contributing a guess.
        foreach (['resin', 'masterbatch'] as $role) {
            $id = $run[$role]['item']['id'] ?? null;

            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        foreach ($this->packing->forStandard($entry->productionStandard) as $line) {
            $id = is_array($line) ? ($line['item']['id'] ?? null) : null;

            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        // THE MATERIALS THE FACTORY HAS ITSELF DECLARED AS PRODUCTION INPUTS —
        // every item its packing-material master names (which carton, tray,
        // film or tape a spec means) and every masterbatch its dosing master
        // carries.
        //
        // These belong in EXPECTED even when this run's own standard resolved
        // nothing, and the reason is what "unplanned" has to mean to be worth
        // asking about. A supervisor booking the tray the office mapped last
        // month is not substituting anything; a plant that has not finished
        // mapping every spec — and this one has not, on purpose, the seed
        // "leaves the rest empty" — would otherwise ask for a written reason on
        // most ordinary lines, and a box asked for that often stops being read.
        // What is left outside is a real answer: a material in nobody's recipe,
        // nobody's packing master and nobody's dosing sheet, which this product
        // has never been made with.
        foreach (DB::table('packing_material_mappings')->distinct()->pluck('item_id') as $id) {
            $ids[] = (int) $id;
        }

        foreach (DB::table('masterbatch_dosings')->distinct()->pluck('masterbatch_item_id') as $id) {
            $ids[] = (int) $id;
        }

        // WHAT THIS PRODUCT HAS ACTUALLY BEEN MADE WITH BEFORE, which ranks as
        // a plan for the same reason RunMaterialSuggestionService ranks it
        // first when resolving the resin: a completed consumption row is the
        // factory stating, with a weight, that this material goes into these
        // bottles. A carton the standard has no mapping for — and this
        // catalogue has many — is otherwise unplanned for ever, so every
        // ordinary run would be asking a supervisor to justify the box they
        // have packed in all year. The reason box has to mean something on the
        // day it is used.
        foreach (
            ShiftMaterialConsumption::query()
                ->whereHas('shiftProductionEntry', fn ($query) => $query
                    ->where('item_id', $product->id)
                    ->whereKeyNot($entry->id))
                ->distinct()
                ->pluck('item_id') as $id
        ) {
            $ids[] = (int) $id;
        }

        return $this->tidy($ids);
    }

    /**
     * Every material this run may book — the dropdown, and the refusal set.
     *
     * @return array<int, int> item ids, ascending
     */
    public function allowedItemIds(ShiftProductionEntry $entry): array
    {
        $ids = Item::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('category')
                ->orWhere('category', '!=', self::NOT_AN_INPUT->value))
            // The bottle this run is making. Refused even when its master is
            // uncategorised, because "a product is not its own input" is about
            // THIS run and does not wait on anybody's classification.
            ->whereKeyNot((int) $entry->item_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->tidy([...$ids, ...$this->expectedItemIds($entry)]);
    }

    /**
     * The dropdown itself — what a screen renders, each option carrying
     * whether it was expected, so the drawer can put the planned materials
     * first and mark the rest as needing a reason.
     *
     * @return array<int, array{item_id: int, name: string, sku: ?string, uom: ?string, category: ?string, is_expected: bool}>
     */
    public function options(ShiftProductionEntry $entry): array
    {
        $expected = array_flip($this->expectedItemIds($entry));

        return Item::query()
            ->whereIn('id', $this->allowedItemIds($entry))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'uom', 'category'])
            ->map(fn (Item $item) => [
                'item_id' => (int) $item->id,
                'name' => (string) $item->name,
                'sku' => $item->sku,
                'uom' => $item->uom,
                'category' => $item->category instanceof ItemCategory
                    ? $item->category->value
                    : ($item->category === null ? null : (string) $item->category),
                'is_expected' => isset($expected[(int) $item->id]),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function tidy(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
        sort($ids);

        return $ids;
    }

    /** @return Collection<int, Item> */
    public function itemsById(array $ids): Collection
    {
        return Item::query()->whereIn('id', $ids)->get()->keyBy('id');
    }
}
