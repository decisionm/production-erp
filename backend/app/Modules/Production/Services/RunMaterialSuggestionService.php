<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\FactorySetting;
use App\Modules\Production\Models\MasterbatchDosing;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WHICH resin and WHICH masterbatch this run consumes, and how many grams of
 * each go into one bottle — so the completion screen can arrive with both
 * materials already chosen instead of two dropdowns the supervisor has to
 * guess at.
 *
 * The owner has asked three times for the same thing: the material box
 * pre-filled, a grams-per-bottle box pre-filled and editable, and a total kg
 * box. This service answers the first two halves of that for both rows. It
 * answers nothing about what was ACTUALLY consumed — see "advisory only"
 * below, which is absolute.
 *
 * ## Why the resin item is resolved from HISTORY, not from its name
 *
 * This catalogue's resin is "PET Polyster Chips" and "Relpet". Neither
 * contains the word resin, polymer, or granule. A name rule has already
 * failed here once — the empty Resin picker on go-live morning, which is why
 * the frontend's own matcher carries the comment "a generic pattern already
 * missed them once". A name is a spelling somebody chose in Tally; a
 * consumption row is the factory stating, with a weight, that this material
 * went into bottles. The record of what was issued cannot be out of date with
 * the catalogue, so it ranks first and the name pattern is only the answer
 * when there is no history at all (a fresh instance, day one).
 *
 * ## Why the masterbatch is resolved on items.colour and on DATA, never on
 * ## masterbatch names
 *
 * "Masterbatch -Red(Brown)" and "ARIHANT PET WHITE 1020 Master Batch" both
 * defeat a colour-word scan: the first names two colours, the second buries
 * its colour among a brand and a grade number. Scanning them is what put a
 * WHITE masterbatch on a non-white run in the owner's screenshot (the SPA's
 * suggestMasterbatchId falls through to `name.includes(colour)` and takes the
 * first hit, with no tier that can say "I don't know").
 *
 * So the colour is matched on `items.colour` on BOTH sides, and an explicit
 * factory-editable map wins over even that. `items.colour` is a usable but
 * INCOMPLETE signal — see colourMap() for why the map exists and what the
 * DeriveItemColours command does and does not cover.
 *
 * ## Two candidates is not an answer
 *
 * Every resolution here returns null with a stated reason rather than pick
 * between two candidates. A blank box the supervisor fills in costs ten
 * seconds; the wrong masterbatch pre-filled and accepted costs a shift of
 * bottles and a Tally voucher that moved the wrong stock. Null is the
 * expensive-looking answer that is always cheaper.
 *
 * ## Advisory only
 *
 * Nothing here is written to an entry, and nothing in the completion path
 * calls this service. completeBatch stores exactly the material_consumptions
 * rows it was sent and the Tally voucher carries those — see
 * MasterbatchDosingService's docblock for why that separation is absolute.
 * A suggestion that could reach a stored figure would be a recipe pretending
 * to be a measurement.
 */
class RunMaterialSuggestionService
{
    /**
     * The factory-editable colour → masterbatch map, held as a `json`
     * factory_settings row so a person can change it through the existing
     * `POST /api/v1/production/factory-settings` endpoint without a deploy.
     *
     * Shape — colour exactly as `items.colour` spells it, to the masterbatch
     * item id:
     *
     *   {"Amber": 121, "Milk White": 120}
     *
     * Deliberately NOT seeded with guesses. The factory has stated one thing
     * about masterbatch (amber, 0.25 g per bottle, 31-Jul) and that statement
     * already lives in the dosing master; which item each of the other
     * colours uses is a question only the factory can answer.
     */
    public const COLOUR_MAP_KEY = 'masterbatch_colour_map';

    /**
     * What owns the resin TOTAL kg box, stated in the response so the screen
     * cannot quietly replace it with grams × bottles.
     *
     * The owner's own screenshot proves the distinction: 5.0 g/bottle over
     * 13,333 bottles is 66.665 kg, but the resin box reads 71.61 kg — the
     * extra ~4.95 kg is the rejection and lumps mass. The resin figure is the
     * factory's paper arithmetic (production kg + rejection kg + lumps),
     * verified against 11 rows of 11, and this service does not touch it.
     * That is why the resin block carries grams_per_bottle and NO
     * suggested_kg: a total computed from grams alone would be light by the
     * scrap mass on every shift, in the same direction, which reads as a
     * permanent phantom shortage in the material variance.
     */
    public const RESIN_TOTAL_KG_BASIS = 'production_kg + rejection_kg + lumps_kg';

    /**
     * Resin spellings, used ONLY when no consumption history exists. Taken
     * verbatim from the frontend matcher that this factory's live catalogue
     * forced into existence ("PET Polyster Chips", "Relpet").
     */
    private const RESIN_NAME_PATTERN = '/resin|granul|polym|poly\s*e?ster|relpet|pet\s*(chip|raw)|\bchips\b/i';

    /**
     * A name rule used ONLY TO EXCLUDE a colourant from the resin candidates,
     * never to choose one.
     *
     * The asymmetry is the whole justification: a false positive here costs a
     * blank Resin box (an item wrongly held back), while choosing BY name is
     * what offers the wrong material and gets it accepted. Resin and
     * masterbatch are issued on the same shifts, so an entry-count ranking
     * ties between them constantly — without this the most-issued "resin"
     * could be a masterbatch.
     */
    private const COLOURANT_NAME_PATTERN = '/master\s*batch/i';

    // Deliberately NOT memoised. Both suggestions read the same two lists and
    // caching them per instance is the obvious saving — but the container can
    // hand the same instance back after a person has changed the factory map,
    // and then the screen shows a stale answer to a question the factory has
    // just answered. Proved by test: a cached map made a new mapping
    // invisible until the next request. These are two indexed reads of tiny
    // tables; a stale suggestion costs far more than they do.

    public function __construct(
        private readonly MasterbatchDosingService $dosings,
        private readonly ProductionCalculationEngine $engine,
    ) {}

    /**
     * Both suggestions for one intended or in-flight run.
     *
     * `$bottles` is quoted back inside each block so the screen can state the
     * basis of a kg instead of showing an unexplained figure — and both
     * blocks MUST be given the same count the masterbatch_dosing block uses,
     * or one response would disagree with itself.
     *
     * @return array{resin: array<string, mixed>, masterbatch: array<string, mixed>}
     */
    public function forRun(
        Item $product,
        ?ProductionConfiguration $configuration = null,
        ?ProductionStandard $standard = null,
        ?int $bottles = null,
        ?string $statedColour = null,
    ): array {
        return [
            'resin' => $this->resinFor(
                $product,
                $this->unitWeightGramsFor($product, $configuration, $standard),
                $bottles,
            ),
            'masterbatch' => $this->masterbatchFor(
                $product,
                $this->colourFor($product, $configuration, $statedColour),
                $bottles,
                // The same bottle weight the resin block is quoting. A
                // percentage dose is a percentage OF THIS BOTTLE, so the two
                // blocks must not be able to disagree about what it weighs.
                $this->unitWeightGramsFor($product, $configuration, $standard),
            ),
        ];
    }

    /**
     * The bottle's own unit weight, which IS its resin grams per bottle: a
     * bottle weighing 5 g is 5 g of polymer.
     *
     * Same precedence as the batch's own snapshot — configuration → standard
     * → item master — so the box the supervisor sees quotes the figure the
     * run is actually being measured against. Normalised to a 4dp string
     * because SQLite hands a decimal column back as '5' where MySQL gives
     * '5.0000', and the field must not change shape with the driver.
     */
    public function unitWeightGramsFor(
        Item $product,
        ?ProductionConfiguration $configuration = null,
        ?ProductionStandard $standard = null,
    ): ?string {
        return $this->positiveGrams(
            $configuration?->unit_weight_grams
                ?? $standard?->unit_weight_grams
                ?? $product->nominal_weight_grams,
        );
    }

    /**
     * Which colour is running: what the supervisor said, else the approved
     * configuration's, else the item master's.
     *
     * EXACTLY startBatch's own precedence, which is the point — that method
     * freezes the same three-tier answer into the entry's config_snapshot, so
     * a preview resolving the masterbatch from a different tier would offer a
     * material for a colour the run is not recorded as having made. The
     * supervisor wins because they are looking at the machine, and most bottle
     * items carry no colour at all for the masters to answer with.
     */
    public function colourFor(
        Item $product,
        ?ProductionConfiguration $configuration = null,
        ?string $statedColour = null,
    ): ?string {
        foreach ([$statedColour, $configuration?->colour, $product->colour] as $candidate) {
            if (trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * The resin block: which material, and its grams per bottle.
     *
     * No suggested_kg, deliberately — see RESIN_TOTAL_KG_BASIS.
     *
     * @return array{
     *     item: ?array{id: int, name: string}, grams_per_bottle: ?string,
     *     bottles: ?int, source: string, reason: string, total_kg_basis: string,
     * }
     */
    public function resinFor(Item $product, ?string $unitWeightGrams, ?int $bottles = null): array
    {
        $resolved = $this->resolveResinItem($product);
        $item = $resolved['item'];

        return [
            'item' => $item === null ? null : ['id' => (int) $item->id, 'name' => (string) $item->name],
            'grams_per_bottle' => $unitWeightGrams,
            'bottles' => $bottles,
            'source' => $resolved['source'],
            'reason' => $this->resinReason($resolved, $unitWeightGrams),
            'total_kg_basis' => self::RESIN_TOTAL_KG_BASIS,
        ];
    }

    /**
     * The masterbatch block: which colourant this bottle's colour takes, its
     * grams per bottle from the dosing master, and the kg that implies.
     *
     * @return array{
     *     item: ?array{id: int, name: string}, grams_per_bottle: ?string,
     *     bottles: ?int, suggested_kg: ?string, source: string, reason: string,
     *     percent: ?string, bottle_grams: ?string, grams_source: ?string,
     * }
     */
    public function masterbatchFor(
        Item $product,
        ?string $colour,
        ?int $bottles = null,
        ?string $bottleGrams = null,
    ): array {
        $resolved = $this->resolveMasterbatchItem($product, $colour);
        $item = $resolved['item'];

        // A STATED FIGURE FIRST. masterbatch_dosings holds grams a person has
        // set for one colour on one product, and that outranks any percentage:
        // the percentage is the standard, the dosing row is somebody's answer
        // about this exact bottle.
        $stated = $item === null
            ? null
            : $this->positiveGrams($this->dosings->resolve((int) $item->id, (int) $product->id)?->grams_per_bottle);

        // THE FACTORY'S STANDARD, AS A PERCENTAGE OF THE BOTTLE — which is how
        // the floor states it ("the standard percentage per bottle, so they can
        // change the percentage if they want to use more"). 2.5% is their own
        // July/August books: amber at 0.32 g on a 12.9 g bottle. See
        // config/production.php for the arithmetic and for why it is not 2.25%.
        //
        // This is why the row used to arrive EMPTY. Grams came from the dosing
        // master and nowhere else, and almost no product has a dosing row — so
        // the masterbatch line showed a material, no grams and no kg, and
        // nothing was consumed. "master batch not added" (owner, 06-Aug).
        // THE PERCENTAGE IS A PROPERTY OF THE BOTTLE, NOT OF THE COLOURANT, so it
        // is sent whenever this run takes a masterbatch at all — even when the
        // masters could not settle WHICH one.
        //
        // Gating it on $item was wrong and the owner found it immediately
        // (06-Aug): "still masterbatch auto fill not happening, i know for clear
        // there is not master batch but i am testing for amber". Two of the six
        // resolution outcomes hand back a null item on a bottle that certainly
        // takes colour — 'ambiguous_colour' when two amber materials exist, and
        // 'no_colour' when the item master is blank — and in both the supervisor
        // picks the material themselves. The dose has to follow their pick.
        //
        // Clear is the one case that must NOT carry a percentage: no masterbatch
        // goes into a colourless bottle, and a dose offered there is a wrong
        // Tally line waiting for one careless tab.
        $takesMasterbatch = $resolved['source'] !== 'clear';
        $percent = $takesMasterbatch ? $this->masterbatchPercent() : null;

        // grams_per_bottle STILL REQUIRES A MATERIAL. A dose quoted with no
        // colourant named is a block disagreeing with itself, and this one is
        // read by Sales as well as by the floor. What travels instead is the
        // percentage and the bottle weight — enough for the screen to compute
        // the dose the moment a supervisor names the material, and nothing at
        // all until they do.
        $derived = $stated !== null || $item === null
            ? null
            : $this->engine->gramsFromPercent($bottleGrams, $percent);

        $grams = $stated ?? $derived;

        return [
            'item' => $item === null ? null : ['id' => (int) $item->id, 'name' => (string) $item->name],
            'grams_per_bottle' => $grams,
            'bottles' => $bottles,
            'suggested_kg' => $this->engine->masterbatchKg($bottles, $grams),
            'source' => $resolved['source'],
            // The percentage in force and the bottle it applies to, so the
            // screen can offer the percentage as the editable field and
            // recompute grams from it without inventing either number.
            'percent' => $percent,
            // The weight the percentage applies to, sent on the same terms as the
            // percentage itself — the screen recomputes grams when a supervisor
            // changes the percentage, and it must not have to guess at a bottle.
            'bottle_grams' => $takesMasterbatch ? $bottleGrams : null,
            // Which of the two produced the grams — so a supervisor can see
            // whether they are looking at a figure someone set for this bottle
            // or the factory's standard percentage applied to its weight.
            'grams_source' => $grams === null ? null : ($stated !== null ? 'dosing' : 'percent'),
            'reason' => $this->masterbatchReason($resolved, $colour, $grams, $stated === null ? $percent : null),
        ];
    }

    /**
     * The standard masterbatch percentage, as a positive decimal string.
     *
     * Read through positiveGrams for the same reason every other figure here
     * is: a misconfigured '0' or 'abc' must degrade to "no percentage" — a
     * blank box the floor can fill — rather than to a confident 0.0000 g dose
     * on a live consumption line.
     */
    private function masterbatchPercent(): ?string
    {
        return $this->positiveGrams(config('production.masterbatch_percent'));
    }

    // ---------------------------------------------------------------- resin --

    /**
     * @return array{item: ?Item, source: string, tied: list<string>}
     */
    private function resolveResinItem(Item $product): array
    {
        $excluded = $this->colourantItemIds()->push((int) $product->id)->unique()->values();

        $pool = Item::query()
            ->kgUom()
            ->where('is_active', true)
            ->whereNotIn('id', $excluded)
            ->orderBy('id')
            ->get();

        // A kg-family unit is this database's only raw-material signal, so an
        // EMPTY pool is a unit-master gap, not an empty catalogue — this
        // factory's Tally masters spell the same unit four ways ('Kgs',
        // 'Kgs.', 'KGS', 'kg') and one unmapped spelling empties it. That must
        // degrade to a best answer, never to the empty Resin dropdown the
        // owner has now been shown three times.
        //
        // The retry is NARROWED to resin-looking names before any history is
        // considered, which the kg path never needs to do. Without that
        // narrowing the unit-agnostic pool would include the Nos consumables
        // (caps, cartons, preforms) that the "Other materials" repeater posts
        // into the same kg-named column — 13,333 caps would outweigh 172 kg of
        // resin and the screen would offer caps as the resin.
        if ($pool->isEmpty()) {
            return $this->resolveResinByName(
                $this->nameMatches(
                    Item::query()->where('is_active', true)->whereNotIn('id', $excluded)->orderBy('id')->get(),
                ),
                'unit_gap',
            );
        }

        $ranked = $this->rankByConsumption($pool);

        if ($ranked !== []) {
            return $this->firstOrAmbiguous($ranked, 'consumption_history');
        }

        // No history at all — a fresh instance, day one. Now, and only now,
        // the spelling is the best available answer.
        return $this->resolveResinByName($this->nameMatches($pool), null);
    }

    /**
     * The spelling-matched candidates, with the issue history breaking a tie
     * between them.
     *
     * @param  Collection<int, Item>  $named
     * @return array{item: ?Item, source: string, tied: list<string>}
     */
    private function resolveResinByName(Collection $named, ?string $qualifier): array
    {
        $suffix = $qualifier === null ? '' : '_'.$qualifier;

        if ($named->count() === 1) {
            return ['item' => $named->first(), 'source' => 'name_pattern'.$suffix, 'tied' => []];
        }

        if ($named->count() > 1) {
            // This catalogue holds TWO resin spellings ("PET Polyster Chips"
            // and "Relpet"), so the pattern alone cannot finish the job here.
            $ranked = $this->rankByConsumption($named);

            if ($ranked !== []) {
                return $this->firstOrAmbiguous($ranked, 'consumption_history'.$suffix);
            }

            return [
                'item' => null,
                'source' => 'ambiguous_name',
                'tied' => $named->map(fn (Item $item) => (string) $item->name)->all(),
            ];
        }

        return ['item' => null, 'source' => 'none', 'tied' => []];
    }

    /**
     * The top-ranked material — unless the top two are a dead heat on every
     * ranking key (same number of issues, same recency, same total kg), which
     * is two materials the history cannot separate. Breaking that on item id
     * would be a coin toss.
     *
     * @param  list<array{item: Item, entries: int, kg: string, last_at: string}>  $ranked
     * @return array{item: ?Item, source: string, tied: list<string>}
     */
    private function firstOrAmbiguous(array $ranked, string $source): array
    {
        if (count($ranked) > 1 && $this->rankingKeysMatch($ranked[0], $ranked[1])) {
            return [
                'item' => null,
                'source' => 'ambiguous_history',
                'tied' => [(string) $ranked[0]['item']->name, (string) $ranked[1]['item']->name],
            ];
        }

        return ['item' => $ranked[0]['item'], 'source' => $source, 'tied' => []];
    }

    /** @return Collection<int, Item> */
    private function nameMatches(Collection $pool): Collection
    {
        return $pool
            ->filter(fn (Item $item) => preg_match(self::RESIN_NAME_PATTERN, $item->sku.' '.$item->name) === 1)
            ->values();
    }

    /**
     * The pool ordered by what the factory has actually issued.
     *
     * Frequency first (a material issued on 40 shifts is the one this line
     * runs on), then recency (a material issued last week beats one retired
     * in March), then total kg — which is the figure that keeps resin and
     * masterbatch apart when the counts tie, because they are issued on the
     * SAME shifts and 172 kg of resin against 4 kg of colour is not a close
     * call. Item id last, so the order never depends on row order.
     *
     * @param  Collection<int, Item>  $pool
     * @return list<array{item: Item, entries: int, kg: string, last_at: string}>
     */
    private function rankByConsumption(Collection $pool): array
    {
        if ($pool->isEmpty()) {
            return [];
        }

        $stats = ShiftMaterialConsumption::query()
            ->whereIn('item_id', $pool->pluck('id'))
            ->groupBy('item_id')
            ->select([
                'item_id',
                DB::raw('COUNT(*) as entry_count'),
                DB::raw('SUM(quantity_issued_kg) as total_kg'),
                DB::raw('MAX(created_at) as last_issued_at'),
            ])
            ->get()
            ->keyBy('item_id');

        return $pool
            ->filter(fn (Item $item) => $stats->has($item->id))
            ->map(fn (Item $item) => [
                'item' => $item,
                'entries' => (int) $stats[$item->id]->entry_count,
                'kg' => bcadd((string) $stats[$item->id]->total_kg, '0', 4),
                'last_at' => (string) $stats[$item->id]->last_issued_at,
            ])
            ->sort(function (array $a, array $b) {
                return [$b['entries'], $b['last_at']] <=> [$a['entries'], $a['last_at']]
                    ?: bccomp($b['kg'], $a['kg'], 4)
                    ?: ((int) $a['item']->id <=> (int) $b['item']->id);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{entries: int, kg: string, last_at: string}  $a
     * @param  array{entries: int, kg: string, last_at: string}  $b
     */
    private function rankingKeysMatch(array $a, array $b): bool
    {
        return $a['entries'] === $b['entries']
            && $a['last_at'] === $b['last_at']
            && bccomp($a['kg'], $b['kg'], 4) === 0;
    }

    // ---------------------------------------------------------- masterbatch --

    /**
     * @return array{item: ?Item, source: string, tied: list<string>}
     */
    private function resolveMasterbatchItem(Item $product, ?string $colour): array
    {
        if ($colour === null || trim($colour) === '') {
            return ['item' => null, 'source' => 'no_colour', 'tied' => []];
        }

        // Clear takes no masterbatch. Returned as its own source so the
        // screen can SAY so, rather than rendering a blank box the supervisor
        // reads as a question they still have to answer.
        if (preg_match('/^clear$/i', trim($colour)) === 1) {
            return ['item' => null, 'source' => 'clear', 'tied' => []];
        }

        $mapped = $this->mappedMasterbatch($colour);

        if ($mapped !== null) {
            return ['item' => $mapped, 'source' => 'factory_map', 'tied' => []];
        }

        $candidates = $this->colourantPool()
            ->reject(fn (Item $item) => (int) $item->id === (int) $product->id)
            ->filter(fn (Item $item) => $this->sameColour($item->colour, $colour))
            ->values();

        if ($candidates->count() === 1) {
            return ['item' => $candidates->first(), 'source' => 'item_colour', 'tied' => []];
        }

        if ($candidates->count() > 1) {
            return [
                'item' => null,
                'source' => 'ambiguous_colour',
                'tied' => $candidates->map(fn (Item $item) => (string) $item->name)->all(),
            ];
        }

        return ['item' => null, 'source' => 'unmapped', 'tied' => []];
    }

    /**
     * The factory's own answer for a colour, if it has given one.
     *
     * Validated on READ — the item must still exist, be active and be a
     * kg-family unit — because the map is a settings row and nothing
     * validates it on the way in. An entry pointing at a deleted or
     * Nos-unit item is ignored rather than trusted: the colour then falls
     * through to items.colour, and to a null with a reason if that cannot
     * answer either.
     */
    private function mappedMasterbatch(string $colour): ?Item
    {
        $itemId = null;

        foreach ($this->colourMap() as $mappedColour => $mappedItemId) {
            if ($this->sameColour((string) $mappedColour, $colour)) {
                $itemId = (int) $mappedItemId;
                break;
            }
        }

        if ($itemId === null || $itemId <= 0) {
            return null;
        }

        return Item::query()->kgUom()->where('is_active', true)->find($itemId);
    }

    /**
     * The colour → masterbatch item id map from factory settings, or an empty
     * map. Malformed JSON is an empty map, never an exception: a mistyped
     * setting must cost a blank suggestion box, not a 500 on the completion
     * screen the whole floor works through.
     *
     * ## Why this map exists at all
     *
     * `items.colour` is filled by the DeriveItemColours command from the
     * colour already stated in the Tally item name, and it covers masterbatch
     * items only PARTLY:
     *
     *  - the command's packaging/raw-material skip list (which names
     *    "master ?batch" explicitly) applies only under --only-products, so a
     *    default run DOES colour "Master Batch Amber" → Amber and "ARIHANT
     *    PET WHITE 1020 Master Batch" → White;
     *  - but its vocabulary has no Red, Brown or Orange, so
     *    "Masterbatch -Red(Brown)" can never receive one — and a name with
     *    two colour words would be left alone anyway;
     *  - and whether the command was run with that flag is an operator
     *    decision, not something this code can read.
     *
     * So the colour column is a usable but incomplete signal, and the factory
     * needs a place to state the answer directly. That place is data, not a
     * regex over masterbatch names.
     *
     * @return array<string, mixed>
     */
    private function colourMap(): array
    {
        $setting = FactorySetting::query()
            ->where('key', self::COLOUR_MAP_KEY)
            ->where('is_active', true)
            ->first();

        if ($setting === null) {
            return [];
        }

        $decoded = json_decode((string) $setting->value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Candidate colourants: active kg-family items that CARRY a colour.
     *
     * Both halves are master data, not a name scan. The unit is this
     * database's only raw-material signal (the same gate the day-bin picker
     * uses), and a colour on a kg item is what separates a colourant from
     * resin — "PET Polyster Chips" and "Relpet" carry no colour, because a
     * resin has none.
     *
     * A coloured kg item that is NOT a masterbatch (an amber scrap, say)
     * lands in this pool too, and that is deliberate: it makes the colour
     * ambiguous, which returns null and a sentence. One row in the factory
     * map settles it permanently.
     *
     * @return Collection<int, Item>
     */
    private function colourantPool(): Collection
    {
        return Item::query()
            ->kgUom()
            ->where('is_active', true)
            ->whereNotNull('colour')
            ->where('colour', '!=', '')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every item id this service will not offer as resin: anything the
     * factory has attached a dosing to, anything the colour map names,
     * anything holding a colour, and anything a name declares to be a
     * masterbatch (exclusion only — see COLOURANT_NAME_PATTERN).
     *
     * @return Collection<int, int>
     */
    private function colourantItemIds(): Collection
    {
        // withTrashed: withdrawing a dosing figure returns the floor to "no
        // prefill", it does not turn the masterbatch back into a resin.
        $fromDosings = MasterbatchDosing::withTrashed()
            ->distinct()
            ->pluck('masterbatch_item_id');

        $fromMap = collect($this->colourMap())->values()->map(fn ($id) => (int) $id);

        $fromColour = $this->colourantPool()->pluck('id');

        $fromName = Item::query()
            ->where('is_active', true)
            ->get(['id', 'sku', 'name'])
            ->filter(fn (Item $item) => preg_match(self::COLOURANT_NAME_PATTERN, $item->sku.' '.$item->name) === 1)
            ->pluck('id');

        return $fromDosings
            ->concat($fromMap)
            ->concat($fromColour)
            ->concat($fromName)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
    }

    // --------------------------------------------------------------- reasons --

    /**
     * The sentence the screen shows beside a pre-filled material, so a
     * supervisor accepting it knows where it came from — and so a REFUSAL
     * says what is missing instead of leaving an empty box that looks broken.
     *
     * @param  array{item: ?Item, source: string, tied: list<string>}  $resolved
     */
    private function resinReason(array $resolved, ?string $grams): string
    {
        $weight = $grams === null
            ? ' No unit weight is resolved for this bottle, so the grams per bottle stay blank.'
            : ' Grams per bottle is the bottle’s own unit weight ('.$grams.' g).';

        return match ($resolved['source']) {
            'consumption_history' => 'Most issued resin on past shifts: '.$resolved['item']->name.'.'.$weight,
            'name_pattern' => 'No resin has been issued yet, so this was matched on the item name: '.$resolved['item']->name.'.'.$weight,
            'name_pattern_unit_gap' => 'No kg-unit raw material exists in the masters, so this was matched on the item name: '.$resolved['item']->name.'. Check that item’s unit of measure.'.$weight,
            'ambiguous_history' => 'Two materials are issued alike ('.implode(', ', $resolved['tied']).') — pick the resin yourself.'.$weight,
            'ambiguous_name' => 'Several items could be the resin ('.implode(', ', $resolved['tied']).') and none has been issued yet — pick one.'.$weight,
            default => 'No resin could be resolved — none has been issued and no item name matches. Pick the resin, and it will be suggested next time.'.$weight,
        };
    }

    /**
     * The sentence beside the masterbatch row.
     *
     * SHORT, for the reason the whole screen is being shortened — the owner,
     * reading it on the floor (05-Aug): "why so many English notes, will they
     * really read them". The old version of this method wrote up to 40 words
     * telling a supervisor mid-shift to go and administer factory settings they
     * have no access to. What a person at the machine needs is the material's
     * name and the dose, because those are the two things the boxes beside it
     * do not spell out.
     *
     * @param  array{item: ?Item, source: string, tied: list<string>}  $resolved
     * @param  ?string  $percent  The standard percentage, when the grams came FROM it
     */
    private function masterbatchReason(array $resolved, ?string $colour, ?string $grams, ?string $percent = null): string
    {
        $shade = trim((string) $colour);

        // The percentage is named when it is what produced the grams: a dose the
        // floor may change starts by saying where it came from.
        $dose = match (true) {
            $grams === null => ' — dose not set',
            $percent !== null => ' · '.$this->trimZeros($percent).'% = '.$this->trimZeros($grams).' g/bottle',
            default => ' · '.$this->trimZeros($grams).' g/bottle',
        };

        return match ($resolved['source']) {
            'clear' => 'Clear takes no masterbatch',
            'no_colour' => 'Colour not set on this bottle — choose the masterbatch',
            'factory_map', 'item_colour' => $resolved['item']->name.$dose,
            'ambiguous_colour' => count($resolved['tied']).' '.$shade.' materials — choose one',
            default => 'No masterbatch mapped for '.($shade === '' ? 'this colour' : $shade).' — choose one',
        };
    }

    /** 2.5000 reads as 2.5, and 0.3225 stays 0.3225. */
    private function trimZeros(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /**
     * A grams figure as a 4dp string, or null. Zero and negative are not
     * figures: they would turn a missing master value into a confident
     * 0.0000 on the floor's screen.
     */
    private function positiveGrams(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $grams = bcadd((string) $value, '0', 4);

        return bccomp($grams, '0', 4) === 1 ? $grams : null;
    }

    private function sameColour(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b))
            && trim((string) $a) !== '';
    }
}
