<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Data\MouldItemMap;
use App\Modules\Production\Data\PackingSpecInferences;
use App\Modules\Production\Data\ProductMasterCorrections;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the factory's product master (ERPPRO29072026.xlsx) as product-level
 * production standards.
 *
 * The normalisation rules, which are the whole point of this class:
 *
 *  - **Variant key is product + cavities + weight + cycle time.** NOT product
 *    + packaging mode. The factory sheet lists the same product twice when it
 *    can be packed in a pouch or in a tray; those are one standard with two
 *    packaging options, and treating them as two standards would make a
 *    supervisor choose between identical-looking rows.
 *  - **A genuinely different cavity, weight or cycle time is a different
 *    variant** and stays separately selectable.
 *  - **Multi-valued cells are split, never averaged.** "21.5 / 17.8" becomes
 *    two variants, each flagged unresolved. The mean of two real cycle times
 *    is a rate no machine runs at, and it would silently corrupt every
 *    expected-output figure derived from it.
 *  - **Only genuinely blank values are flagged.** A row missing its cycle
 *    time needs a person; a row missing its pouch columns simply is not
 *    pouch-packed.
 *  - **Nothing is imported as approved.** Matching a factory name to a Tally
 *    item is an inference, and an unreviewed inference must not look like a
 *    decision. Rows land as `draft`, or as `unresolved` when they carry an
 *    ambiguity a person must settle; `approved`, `approved_by` and
 *    `approved_at` are never written by this class.
 *
 * Two counts the summary reports separately, because conflating them would
 * describe the design as a failure:
 *
 *  - `rows_merged` — a later source row folded into an existing variant and
 *    contributed a NEW packaging mode. This is the pouch/tray merge working
 *    as intended, not a rejection.
 *  - `duplicates_refused` — a later source row claimed a packaging mode the
 *    variant already had. The first wins and the second is refused; when the
 *    two disagree on the numbers the variant is additionally flagged
 *    unresolved, because silently keeping either one would hide a real
 *    contradiction in the master sheet.
 *
 * Item resolution runs ONCE, after normalisation, against a single index of
 * the catalogue — never per-variant inside the normalisation loop, where a
 * newly created fixture item would shift the results of later rows in the
 * same pass and make the import unreproducible.
 */
class ProductionStandardImportService
{
    /**
     * Every locally fabricated item carries this SKU prefix. It is the
     * unmistakable marker: nothing that came from Tally can start with it,
     * so one glance at a SKU says whether a row is real master data.
     *
     * Defined on the Item model — the readers (readiness gate, Tally voucher
     * queue) must not depend on this importer to learn the convention.
     */
    public const LOCAL_FIXTURE_SKU_PREFIX = Item::LOCAL_FIXTURE_SKU_PREFIX;

    /** Appended to the item name so the marker survives into every picker. */
    public const LOCAL_FIXTURE_NAME_SUFFIX = '(LOCAL FIXTURE)';

    public const LOCAL_FIXTURE_NOTE = 'LOCAL TEST FIXTURE — fabricated from the factory product master so the canonical flow can be rehearsed before Tally items exist. Not master data, never synced, safe to delete.';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  bool  $exactOnly  Write only variants that resolved to exactly one item and carry no ambiguity.
     * @param  bool  $createLocalFixtureItems  LOCAL ONLY. Fabricate a "LOCAL-" item for every product the catalogue does not already carry. Default (false) is MAP-ONLY: match existing items or leave the standard unmatched, never invent one.
     * @return array{
     *     dry_run: bool,
     *     summary: array<string, int>,
     *     variants: list<array<string, mixed>>,
     * }
     */
    public function import(
        array $rows,
        bool $dryRun,
        ?int $createdBy,
        bool $exactOnly = false,
        bool $createLocalFixtureItems = false,
    ): array {
        $normalised = $this->normalise($rows);
        /** @var list<array<string, mixed>> $variants */
        $variants = $normalised['variants'];

        // One pass over the catalogue, reused for every variant.
        $index = $this->itemIndex();

        // Families the catalogue cannot answer for. In map-only mode these
        // stay unmatched — an unmatched standard is visible work, an
        // invented item is a lie that looks like master data.
        $missing = $createLocalFixtureItems
            ? array_values(array_filter($normalised['families'], fn (string $name) => ! isset($index[$this->normaliseName($name)])))
            : [];

        $fixturesCreated = 0;

        if ($dryRun) {
            $variants = $this->attachItems($variants, $index);
            $variants = $this->applySkipReasons($variants, $exactOnly);
        } else {
            DB::transaction(function () use (&$variants, &$index, &$fixturesCreated, $missing, $exactOnly, $createdBy) {
                foreach ($missing as $name) {
                    $item = $this->localFixtureItem($name);
                    $index[$this->normaliseName($name)] = $item;
                    if ($item->wasRecentlyCreated) {
                        $fixturesCreated++;
                    }
                }

                $variants = $this->attachItems($variants, $index);
                $variants = $this->applySkipReasons($variants, $exactOnly);
                $variants = $this->write($variants, $createdBy);
            });
        }

        $summary = [
            'source_rows' => count($rows),
            // Distinct product names, i.e. how many products the sheet
            // actually describes, as opposed to how many rows it spends
            // describing them.
            'product_families' => count($normalised['families']),
            'variants' => count($variants),
            'matched' => 0,
            'unmatched' => 0,
            'unresolved' => 0,
            'packaging_options' => 0,
            'rows_merged' => $normalised['rows_merged'],
            'duplicates_refused' => $normalised['duplicates_refused'],
            'packaging_conflicts' => $normalised['packaging_conflicts'],
            // On a dry run this is what WOULD be fabricated; nothing is
            // written, so nothing is created.
            'local_fixture_items' => $dryRun ? count($missing) : $fixturesCreated,
            'importable' => 0,
            'skipped' => 0,
            // Row-scoped, and deliberately reported alongside rather than
            // instead of the counts above. One mould standard covers every
            // colour variant of its bottle, so a variant writes one row PER
            // matched item — "90 variants" and "N standard rows" are both true
            // and answer different questions.
            'standard_rows' => 0,
        ];

        foreach ($variants as $variant) {
            $summary[$variant['item_id'] === null ? 'unmatched' : 'matched']++;
            if ($variant['status'] === 'unresolved') {
                $summary['unresolved']++;
            }
            $summary['packaging_options'] += count($variant['packagings']);
            $summary[($variant['skip_reason'] ?? null) === null ? 'importable' : 'skipped']++;
            if (($variant['skip_reason'] ?? null) === null) {
                $summary['standard_rows'] += count($variant['matched_items'] ?? []);
            }
        }

        return ['dry_run' => $dryRun, 'summary' => $summary, 'variants' => array_values($variants)];
    }

    /**
     * Collapse source rows into variants, merging packaging options.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     variants: list<array<string, mixed>>,
     *     families: list<string>,
     *     rows_merged: int,
     *     duplicates_refused: int,
     *     packaging_conflicts: int,
     * }
     */
    private function normalise(array $rows): array
    {
        $variants = [];
        $families = [];
        $merged = 0;
        $refused = 0;
        $conflicts = 0;

        foreach ($rows as $row) {
            // Factory answers, applied on top of the verbatim sheet. See
            // ProductMasterCorrections for why these live apart from the rows
            // rather than being edited into them.
            $correction = ProductMasterCorrections::forRow($row);
            $confirmedSplits = [];

            if ($correction !== null) {
                foreach ($correction['set'] ?? [] as $field => $value) {
                    $row[$field] = $value;
                }
                $confirmedSplits = $correction['confirm_split'] ?? [];
            }

            $product = trim((string) ($row['product'] ?? ''));
            if ($product === '') {
                continue;
            }

            $families[$this->normaliseName($product)] ??= $product;

            $cavities = $this->intOrNull($row['cavities'] ?? null);

            // A single source cell can hold several values — "18/20" for the
            // long-standing ambiguous 200Ml Round weight, "21.5 / 17.8" for
            // a cycle time that differs by machine. Each becomes its own
            // unresolved variant. Averaging would invent a weight no bottle
            // has and a rate no machine runs at.
            [$weights, $rawWeight] = $this->splitValues($row['unit_weight_grams'] ?? null);
            [$cycleTimes, $rawCycleTime] = $this->splitValues($row['cycle_time'] ?? null);

            foreach ($weights as $weight) {
                foreach ($cycleTimes as $cycleTime) {
                    // Keyed on the stored name, lower-cased. Deliberately the
                    // same string `updateOrCreate` matches on below, so the
                    // in-memory grouping and the database's idea of identity
                    // can never disagree.
                    $key = implode('|', [mb_strtolower($product), $cavities ?? '', $weight ?? '', $cycleTime ?? '']);

                    $alreadySeen = isset($variants[$key]);

                    if (! $alreadySeen) {
                        $unresolved = [];
                        // A confirmed split is a deliberate multi-value: the
                        // variants are still produced, they just stop being an
                        // open question. Confirming one cell never silences the
                        // other, and never silences the BOTH-multi-valued note
                        // below — that one is about pairings nobody observed,
                        // which is a different question from "are both real".
                        $weightSplitConfirmed = in_array('unit_weight_grams', $confirmedSplits, true);
                        $cycleSplitConfirmed = in_array('cycle_time', $confirmedSplits, true);

                        if (count($cycleTimes) > 1 && ! $cycleSplitConfirmed) {
                            $unresolved[] = "Cycle time cell held several values ({$rawCycleTime}); each is a separate variant pending confirmation of which applies where.";
                        }
                        if (count($weights) > 1 && ! $weightSplitConfirmed) {
                            $unresolved[] = "Unit weight cell held several values ({$rawWeight}); each is a separate variant pending confirmation of which applies where.";
                        }
                        if (count($weights) > 1 && count($cycleTimes) > 1) {
                            $unresolved[] = 'Weight and cycle time are BOTH multi-valued, so these combinations are generated, not observed — confirm which pairings are real and discard the rest.';
                        }
                        if ($cycleTime === null) {
                            $unresolved[] = 'Cycle time is blank.';
                        }
                        if ($cavities === null) {
                            $unresolved[] = 'Cavities is blank.';
                        }
                        if ($weight === null) {
                            $unresolved[] = 'Unit weight is blank.';
                        }

                        $variants[$key] = [
                            'source_product_name' => $product,
                            'item_id' => null,
                            'matched_item_name' => null,
                            'cavities' => $cavities,
                            'unit_weight_grams' => $weight,
                            'cycle_time' => $cycleTime,
                            'cycle_time_raw' => count($cycleTimes) > 1 || $cycleTime === null ? $rawCycleTime : null,
                            'unit_weight_raw' => count($weights) > 1 || $weight === null ? $rawWeight : null,
                            'unresolved_notes' => $unresolved,
                            'source_reference' => (string) ($row['sl_no'] ?? ''),
                            // Packaging-material specs from the sheet's three
                            // right-hand columns, filled by the loop below.
                            'carton_spec' => null,
                            'tray_spec' => null,
                            'pouch_spec' => null,
                            // Which of those three, if any, this software
                            // inferred rather than read. See PackingSpecInferences.
                            'spec_provenance' => [],
                            'packagings' => [],
                        ];
                    }

                    // First non-blank wins, and this runs on the first sighting
                    // AND on every sibling row — a pouch row and its tray
                    // sibling describe the same product, so whichever of them
                    // carries the spec supplies it and the other must not blank
                    // it back out.
                    foreach ($this->specsFromRow($row) as $specKey => $spec) {
                        if ($variants[$key][$specKey] !== null || $spec['value'] === null) {
                            continue;
                        }

                        $variants[$key][$specKey] = $spec['value'];

                        if ($spec['provenance'] !== null) {
                            $variants[$key]['spec_provenance'][$specKey] = $spec['provenance'];
                        }
                    }

                    $modesAdded = 0;

                    foreach ($this->packagings($row) as $packaging) {
                        $mode = $packaging['mode'];
                        $held = $variants[$key]['packagings'][$mode] ?? null;

                        if ($held === null) {
                            // Keyed by mode: this is where a pouch row and a
                            // tray row for the same standard become one
                            // standard with two options.
                            $variants[$key]['packagings'][$mode] = $packaging;
                            $modesAdded++;

                            // Figures that contradict each other WITHIN one
                            // option — flagged on arrival, before any later
                            // row can be blamed for it.
                            $inconsistency = $this->packagingInconsistency($packaging);
                            if ($inconsistency !== null) {
                                $variants[$key]['unresolved_notes'][] = $inconsistency;
                            }

                            continue;
                        }

                        // The same variant claiming the same packaging mode a
                        // second time. The first arrival stands; the second is
                        // refused rather than overwriting it.
                        $refused++;

                        if ($held !== $packaging) {
                            $conflicts++;
                            $variants[$key]['unresolved_notes'][] = sprintf(
                                'Row %s repeats the %s packaging for this variant with different figures (%s vs %s); the first was kept and the second refused — confirm which is correct.',
                                (string) ($row['sl_no'] ?? '?'),
                                $mode,
                                $this->describePackaging($held),
                                $this->describePackaging($packaging),
                            );
                        }
                    }

                    // The pouch row meeting its tray sibling — the merge this
                    // importer exists to perform. Counted only when the row
                    // actually contributed a mode the variant did not have:
                    // a row that contributed nothing was refused, and
                    // counting it here as well would report a refusal as a
                    // successful merge.
                    if ($alreadySeen && $modesAdded > 0) {
                        $merged++;
                    }
                }
            }
        }

        // Finalise: packaging maps become lists, and the accumulated notes
        // become the status. Done here rather than at creation time because
        // a conflicting later row can flag a variant that looked clean.
        foreach ($variants as &$variant) {
            $variant['packagings'] = array_values($variant['packagings']);
            $notes = $variant['unresolved_notes'];
            unset($variant['unresolved_notes']);
            $variant['status'] = $notes === [] ? 'draft' : 'unresolved';
            $variant['unresolved_reason'] = $notes === [] ? null : implode(' ', $notes);
        }
        unset($variant);

        return [
            'variants' => array_values($variants),
            'families' => array_values($families),
            'rows_merged' => $merged,
            'duplicates_refused' => $refused,
            'packaging_conflicts' => $conflicts,
        ];
    }

    /**
     * Words that mean "this row is packaging or raw material, not the bottle".
     *
     * A readability filter, NOT a truth claim. Raw string similarity ranked
     * "15ml Round Master Box" as the best match for 15ML ROUND — a carton, not
     * a product — so every factory product pointed at packaging and the report
     * was unreadable. Excluding these makes the bottles visible.
     *
     * Kept deliberately narrow: it only ever removes candidates from a report a
     * human then confirms, and it never touches what the importer matches on.
     */
    private const NON_PRODUCT_WORDS = [
        'box', 'carton', 'tray', 'pouch', 'pad', 'cap', 'cup', 'label',
        'sticker', 'film', 'resin', 'masterbatch', 'master batch', 'preform',
        'sleeve', 'shrink', 'tape', 'ink', 'scrap', 'lump',
    ];

    /**
     * Why the names did not match, and what they nearly matched.
     *
     * Matching is exact after lower-casing and collapsing whitespace, so a
     * catalogue that spells the same bottle differently produces zero matches
     * and an import that silently writes nothing — which is what happened on
     * the live instance, and which the summary table alone cannot explain.
     *
     * Reports the catalogue size first, because "no active items at all" and
     * "items named differently" look identical in a matched=0 result and need
     * completely different fixes: pull the masters from Tally, versus reconcile
     * names.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     active_items: int,
     *     product_items: int,
     *     sample_item_names: list<string>,
     *     unmatched: list<array{product: string, candidates: list<array{name: string, sku: string, score: int, same_size: bool}>}>,
     * }
     */
    public function matchReport(array $rows): array
    {
        $normalised = $this->normalise($rows);
        $index = $this->itemIndex();

        $items = Item::query()->where('is_active', true)->get(['id', 'name', 'sku']);

        // Bottles only. Packaging and raw material outscored real products on
        // raw similarity, which made the whole report point at master boxes.
        $products = $items->reject(function (Item $item) {
            $name = mb_strtolower((string) $item->name);

            foreach (self::NON_PRODUCT_WORDS as $word) {
                if (str_contains($name, $word)) {
                    return true;
                }
            }

            return false;
        });

        $unmatched = [];

        foreach ($normalised['families'] as $family) {
            if (isset($index[$this->normaliseName((string) $family)])) {
                continue;
            }

            // The size is the strongest signal the two naming schemes share:
            // "15ML ROUND" and "A.15ml Round Clear - Sangam" agree on 15. Without
            // it, "200ml Round Pouch" scored 67% against 30ML ROUND — a
            // different bottle entirely. Candidates carrying the same size are
            // ranked first, and the rest only fill the remaining slots.
            $size = $this->sizeToken((string) $family);

            $candidates = $products
                ->map(function (Item $item) use ($family, $size) {
                    $name = $this->normaliseName((string) $item->name);
                    similar_text($this->normaliseName((string) $family), $name, $percent);

                    $sameSize = $size !== null && preg_match('/\b'.preg_quote($size, '/').'\s*(ml|cc|gm|gms)?\b/', $name) === 1;

                    return [
                        // The id rides along so the same ranking can be OFFERED
                        // (the standards page attaches one of these) and not
                        // only printed. Resolving the name back to an id at the
                        // far end would pick a coin-flip winner wherever the
                        // catalogue spells two items alike.
                        'id' => $item->id,
                        'name' => (string) $item->name,
                        'sku' => (string) $item->sku,
                        'score' => (int) round($percent),
                        'same_size' => $sameSize,
                    ];
                })
                ->sortByDesc(fn (array $c) => [$c['same_size'] ? 1 : 0, $c['score']])
                ->take(5)
                ->values()
                ->all();

            $unmatched[] = ['product' => (string) $family, 'candidates' => $candidates];
        }

        return [
            'active_items' => $items->count(),
            'product_items' => $products->count(),
            'sample_item_names' => $products->take(10)->pluck('name')->map(fn ($n) => (string) $n)->all(),
            'unmatched' => $unmatched,
        ];
    }

    /**
     * The catalogue items one factory product name most resembles.
     *
     * The standards page's "which Tally item is this?" list. Deliberately the
     * SAME ranking the import's --diagnose path prints, obtained by calling it
     * rather than by re-deriving it: a suggestion list that scores differently
     * from the diagnostic would make the two disagree about the same question
     * in front of the same person.
     *
     * The one thing it adds is the exact match. matchReport() reports what did
     * NOT match and skips a family the catalogue already carries verbatim —
     * correct for a diagnostic, useless for a picker, which must still offer
     * the obvious answer. That case is prepended at 100.
     *
     * @return list<array{id: int, name: string, sku: string, score: int, same_size: bool}>
     */
    public function itemCandidates(string $product): array
    {
        // No sl_no: a synthetic row must not pick up a factory correction or a
        // packing-spec inference filed against whatever row number it landed on.
        $report = $this->matchReport([['product' => $product]]);

        $candidates = $report['unmatched'][0]['candidates'] ?? [];

        $exact = Item::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'sku'])
            ->first(fn (Item $item) => $this->normaliseName((string) $item->name) === $this->normaliseName($product));

        if ($exact !== null) {
            array_unshift($candidates, [
                'id' => $exact->id,
                'name' => (string) $exact->name,
                'sku' => (string) $exact->sku,
                'score' => 100,
                'same_size' => true,
            ]);
        }

        $seen = [];

        return array_values(array_filter($candidates, function (array $candidate) use (&$seen) {
            if (isset($seen[$candidate['id']])) {
                return false;
            }
            $seen[$candidate['id']] = true;

            return true;
        }));
    }

    /**
     * The leading size figure in a product name — "15" from "15ML ROUND".
     *
     * Both naming schemes lead with it, which makes it the one token worth
     * trusting when nothing else about the two strings lines up.
     */
    private function sizeToken(string $name): ?string
    {
        return preg_match('/(\d+(?:\.\d+)?)\s*(?:ml|cc)/i', $name, $m) === 1 ? $m[1] : null;
    }

    /**
     * Resolve each variant to the Tally items its standard applies to.
     *
     * A variant can now match SEVERAL items, because the factory's rule is that
     * one mould standard covers every colour variant of that bottle — Tally
     * sells "15ml Round ... Amber" and "15ml Round ... Clear" as separate SKUs
     * and both run to the same standard. Each matched item becomes its own
     * production_standards row carrying identical figures.
     *
     * Two sources, in order:
     *
     *  1. The reviewed map in {@see MouldItemMap}, keyed on the whole variant
     *     identity. This is where the mould-name-to-SKU join actually lives —
     *     see that class for why it is recorded data rather than inference.
     *  2. The exact-name index, unchanged. It still resolves a catalogue that
     *     happens to name items exactly as the sheet does, which is what the
     *     LOCAL- fixture path relies on.
     *
     * `item_id` and `matched_item_name` keep pointing at the FIRST match so
     * every existing reader (the summary counters, the skip reasons, the
     * mapping report) keeps working and keeps counting VARIANTS. The row fan-out
     * happens in write(), against `matched_items`.
     *
     * @param  list<array<string, mixed>>  $variants
     * @param  array<string, Item>  $index
     * @return list<array<string, mixed>>
     */
    private function attachItems(array $variants, array $index): array
    {
        // One pass, so a catalogue of 649 items is not re-scanned per variant.
        $byName = [];
        foreach ($index as $item) {
            $byName[$this->normaliseName((string) $item->name)] ??= $item;
        }

        foreach ($variants as &$variant) {
            $matched = [];

            foreach (MouldItemMap::itemsFor(
                $variant['source_product_name'],
                $variant['cavities'],
                $variant['unit_weight_grams'],
                $variant['cycle_time'],
            ) as $name) {
                $item = $byName[$this->normaliseName($name)] ?? null;
                // A mapped name the catalogue does not carry is skipped in
                // silence here and reported by the summary as an unmatched
                // variant — the map is reviewed against one catalogue and must
                // not assume every deployment has the same items.
                if ($item !== null) {
                    $matched[$item->id] ??= $item;
                }
            }

            if ($matched === []) {
                $item = $index[$this->normaliseName($variant['source_product_name'])] ?? null;
                if ($item !== null) {
                    $matched[$item->id] = $item;
                }
            }

            $matched = array_values($matched);

            $variant['matched_items'] = $matched;
            $variant['item_id'] = $matched[0]->id ?? null;
            $variant['matched_item_name'] = $matched[0]->name ?? null;
        }
        unset($variant);

        return $variants;
    }

    /**
     * exactOnly is the production safety setting: write ONLY variants that
     * resolved to AT LEAST ONE item and carry no unresolved ambiguity.
     * Everything else is reported as skipped with its reason — a mapping
     * report for the factory, not a silent omission.
     *
     * It used to read "exactly one item", which was right while a standard
     * could only belong to one product. It is not right now: the factory's rule
     * is that one mould standard covers every colour variant of its bottle, so
     * several matches is the CORRECT outcome and must not be treated as
     * ambiguity. Genuine ambiguity — two different mould names claiming the same
     * item — is refused earlier, when the mapping is reviewed, not here.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function applySkipReasons(array $variants, bool $exactOnly): array
    {
        foreach ($variants as &$variant) {
            $variant['skip_reason'] = null;
            if (! $exactOnly) {
                continue;
            }
            if ($variant['item_id'] === null) {
                $variant['skip_reason'] = 'No exact item match for "'.$variant['source_product_name'].'" — needs mapping to a Tally item name.';
            } elseif ($variant['status'] === 'unresolved') {
                $variant['skip_reason'] = $variant['unresolved_reason'];
            }
        }
        unset($variant);

        return $variants;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function write(array $variants, ?int $createdBy): array
    {
        foreach ($variants as &$variant) {
            if (($variant['skip_reason'] ?? null) !== null) {
                continue;
            }

            $variant['created_ids'] = [];

            // An unmatched variant still gets a row, with a null item. That is
            // what production_standards.item_id is nullable FOR: an unmatched
            // standard is visible work someone can finish, whereas writing
            // nothing would report the import as clean while quietly dropping
            // the variant. Fanning out over an empty match list would have done
            // exactly that.
            $targets = $variant['matched_items'] !== [] ? $variant['matched_items'] : [null];

            foreach ($targets as $item) {
                // Row identity is the variant key PLUS the item. Without
                // item_id in the key, every item of one mould matched the same
                // row: the first created it and the rest updated it in place,
                // so a mould covering three colours silently produced one
                // standard against whichever colour happened to be last.
                //
                // withTrashed()/firstOrNew rather than updateOrCreate because
                // ProductionStandard soft-deletes: the default scope hides a
                // trashed row from the lookup, so a re-import would try to
                // insert a duplicate of a row that still exists.
                $standard = ProductionStandard::withTrashed()->firstOrNew([
                    'item_id' => $item?->id,
                    'source_product_name' => $variant['source_product_name'],
                    'cavities' => $variant['cavities'],
                    'unit_weight_grams' => $variant['unit_weight_grams'],
                    'cycle_time' => $variant['cycle_time'],
                ]);

                // A variant imported UNLINKED (item_id null) and linked later —
                // a MouldItemMap entry added after the factory confirms a name —
                // must ADOPT its existing row, not gain a sibling. Without this,
                // the second import found nothing at the new item-keyed identity
                // (the old row sits at item_id NULL), inserted a fresh row, and
                // the standards page showed the same mould twice: once linked,
                // once orphaned "not attached", forever. Adoption is only for
                // the FIRST item of a fan-out; any further colour variants of
                // the same mould are genuinely new rows.
                if (! $standard->exists && $item !== null && $item === $targets[0]) {
                    $orphan = ProductionStandard::withTrashed()
                        ->whereNull('item_id')
                        ->where([
                            'source_product_name' => $variant['source_product_name'],
                            'cavities' => $variant['cavities'],
                            'unit_weight_grams' => $variant['unit_weight_grams'],
                            'cycle_time' => $variant['cycle_time'],
                        ])
                        ->first();

                    if ($orphan !== null) {
                        $standard = $orphan;
                        $standard->item_id = $item->id;
                    }
                }

                // The mirror image, and it appeared the moment the standards
                // page grew an "attach a Tally item" button: a row the importer
                // cannot match (targets = [null]) whose item was attached BY A
                // PERSON in the app. The lookup above asks for item_id null,
                // does not find it any more, and inserts a fresh unlinked
                // sibling — so the page shows the mould twice, once attached
                // and once "not attached", one re-import after somebody did the
                // work. Reuse the row instead: a person's decision outranks the
                // importer's inability to guess.
                //
                // Narrow on purpose. Only when the importer matched NOTHING,
                // and only against a row stamped with item_attached_at, which
                // nothing but the attach endpoint writes.
                if (! $standard->exists && $item === null) {
                    $attached = ProductionStandard::withTrashed()
                        ->whereNotNull('item_attached_at')
                        ->where([
                            'source_product_name' => $variant['source_product_name'],
                            'cavities' => $variant['cavities'],
                            'unit_weight_grams' => $variant['unit_weight_grams'],
                            'cycle_time' => $variant['cycle_time'],
                        ])
                        ->first();

                    if ($attached !== null) {
                        $standard = $attached;
                    }
                }

                $standard->fill([
                    'cycle_time_raw' => $variant['cycle_time_raw'],
                    'carton_spec' => $variant['carton_spec'],
                    'tray_spec' => $variant['tray_spec'],
                    'pouch_spec' => $variant['pouch_spec'],
                    'spec_provenance' => $variant['spec_provenance'] === [] ? null : $variant['spec_provenance'],
                    'status' => $variant['status'],
                    'unresolved_reason' => $variant['unresolved_reason'],
                    'source' => 'ERPPRO29072026',
                    'source_reference' => $variant['source_reference'],
                    'confirmation_status' => 'Factory master 29-Jul',
                    'created_by' => $createdBy,
                ]);
                $standard->save();

                if ($standard->trashed()) {
                    $standard->restore();
                }

                foreach ($variant['packagings'] as $packaging) {
                    ProductionStandardPackaging::updateOrCreate(
                        ['production_standard_id' => $standard->id, 'mode' => $packaging['mode']],
                        $packaging,
                    );
                }

                // Exactly one option = the default, so the supervisor is never
                // asked a question with one answer.
                $options = $standard->packagings()->get();
                if ($options->count() === 1) {
                    $options->first()->update(['is_default' => true]);
                }

                $variant['created_ids'][] = $standard->id;
            }

            // Kept for readers that expect a single id; the first row written.
            $variant['created_id'] = $variant['created_ids'][0] ?? null;
        }
        unset($variant);

        return $variants;
    }

    /**
     * The three packing-material specs a source row supplies, each with its
     * provenance when it did not come from the sheet.
     *
     * The sheet's own value always wins. An inference is consulted ONLY where
     * the cell is blank, and when one is used it is carried alongside the value
     * rather than baked into it — the string has to stay exactly what a
     * supervisor would read off a carton.
     *
     * Applying inferences here rather than only in a data migration is what
     * makes a re-import reproduce them. Without it the importer's `fill()`
     * would write the workbook's blank straight back over a filled cell, and
     * the specs would quietly disappear the next time the factory sends a
     * master.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array{value: ?string, provenance: ?array<string, mixed>}>
     */
    private function specsFromRow(array $row): array
    {
        $inferred = PackingSpecInferences::forRow($row) ?? [];
        $out = [];

        foreach (PackingSpecInferences::SPEC_COLUMNS as $column) {
            $stated = $this->textOrNull($row[$column] ?? null);

            if ($stated !== null || ! isset($inferred[$column])) {
                $out[$column] = ['value' => $stated, 'provenance' => null];

                continue;
            }

            $out[$column] = [
                'value' => $this->textOrNull($inferred[$column]['value']),
                'provenance' => PackingSpecInferences::provenance($inferred[$column]),
            ];
        }

        return $out;
    }

    /**
     * Packaging options present on one source row.
     *
     * Public because the hand-add path on the standards page derives its
     * packaging rows from exactly the same counts, and re-deriving
     * containers-per-box in a second place is how the two answers start to
     * disagree — see innersPerBox() for the case where they already did.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    public function packagings(array $row): array
    {
        $out = [];

        $nosPerPouch = $this->intOrNull($row['nos_per_pouch'] ?? null);
        $pouchBox = $this->intOrNull($row['pouch_nos_per_box'] ?? null);
        if ($nosPerPouch !== null && $nosPerPouch > 0) {
            $out[] = [
                'mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => $nosPerPouch,
                'pouches_per_box' => $this->innersPerBox(
                    $row['pouches_per_box'] ?? null,
                    $pouchBox,
                    $nosPerPouch,
                ),
                'nos_per_box' => $pouchBox,
            ];
        }

        $nosPerTray = $this->intOrNull($row['nos_per_tray'] ?? null);
        $trayBox = $this->intOrNull($row['tray_nos_per_box'] ?? null);
        if ($nosPerTray !== null && $nosPerTray > 0) {
            $out[] = [
                'mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => $nosPerTray,
                'trays_per_box' => $this->innersPerBox(
                    $row['trays_per_box'] ?? null,
                    $trayBox,
                    $nosPerTray,
                ),
                'nos_per_box' => $trayBox,
            ];
        }

        // A row with a box count but neither pouch nor tray is packed
        // straight into the box.
        if ($out === []) {
            $box = $pouchBox ?? $trayBox;
            if ($box !== null && $box > 0) {
                $out[] = ['mode' => ProductionStandardPackaging::MODE_DIRECT_BOX, 'nos_per_box' => $box];
            }
        }

        return $out;
    }

    /**
     * Containers per box: the SHEET'S OWN figure when it gives one, else
     * derived from pieces-per-box ÷ pieces-per-container.
     *
     * The sheet is preferred because it is the record. Deriving unconditionally
     * is what produced 4 pouches per box on the three 200ML ROUND rows, where
     * POUCH/BOX DETAILS plainly says 5 — an integer division quietly discarding
     * the remainder and contradicting the master it was read from.
     *
     * The derivation stays as the fallback for the 20 pouch rows that carry a
     * bottles-per-pouch count and no containers-per-box figure at all.
     */
    private function innersPerBox(mixed $sheetValue, ?int $nosPerBox, int $nosPerInner): ?int
    {
        $stated = $this->intOrNull($sheetValue);
        if ($stated !== null && $stated > 0) {
            return $stated;
        }

        return ($nosPerBox !== null && $nosPerInner > 0) ? intdiv($nosPerBox, $nosPerInner) : null;
    }

    /**
     * Where a packaging option's own three figures cannot all be true at once.
     *
     * containers × pieces-per-container should equal pieces-per-box. On the
     * 200ML ROUND rows it does not: 105 per pouch × 5 pouches = 525, while the
     * sheet's BOT/BOX says 520. Nothing here can say which of the three is the
     * typo, so all three are kept verbatim and the variant is flagged for a
     * person — picking the pair that makes the arithmetic close would invent a
     * packing figure and hide a real contradiction in the master.
     *
     * @param  array<string, mixed>  $packaging
     */
    private function packagingInconsistency(array $packaging): ?string
    {
        $inner = match ($packaging['mode']) {
            ProductionStandardPackaging::MODE_POUCH => ['pouches_per_box', 'nos_per_pouch', 'pouch'],
            ProductionStandardPackaging::MODE_TRAY => ['trays_per_box', 'nos_per_tray', 'tray'],
            default => null,
        };

        if ($inner === null) {
            return null;
        }

        [$perBoxKey, $perInnerKey, $noun] = $inner;
        $containers = $packaging[$perBoxKey] ?? null;
        $perInner = $packaging[$perInnerKey] ?? null;
        $nosPerBox = $packaging['nos_per_box'] ?? null;

        if ($containers === null || $perInner === null || $nosPerBox === null) {
            return null;
        }

        $implied = $containers * $perInner;
        if ($implied === $nosPerBox) {
            return null;
        }

        return sprintf(
            'The %s figures do not agree: %d per %s × %d %ses per box = %d, but the sheet says %d per box. All three are kept as written — confirm which is correct.',
            $noun,
            $perInner,
            $noun,
            $containers,
            $noun,
            $implied,
            $nosPerBox,
        );
    }

    /** @param  array<string, mixed>  $packaging */
    private function describePackaging(array $packaging): string
    {
        $parts = [];
        foreach ($packaging as $field => $value) {
            if ($field !== 'mode' && $value !== null) {
                $parts[] = "{$field}={$value}";
            }
        }

        return $parts === [] ? 'no figures' : implode(', ', $parts);
    }

    /**
     * Split a numeric cell into one or more values.
     *
     * "12.2" -> [12.2];  "21.5 / 17.8" -> [21.5, 17.8];  "18/20" -> [18, 20];
     * blank -> [null] (one variant, flagged).
     *
     * Used for BOTH cycle time and unit weight: the factory's sheet carries
     * multi-valued cells in each, and the ambiguous 200Ml Round weight
     * ("18/20") is a question that has been open since 24 July.
     *
     * @return array{0: list<?string>, 1: ?string}
     */
    private function splitValues(mixed $value): array
    {
        $raw = is_string($value) ? trim($value) : (is_numeric($value) ? (string) $value : null);

        if ($raw === null || $raw === '') {
            return [[null], null];
        }

        $parts = preg_split('/\s*[\/,;]\s*/', $raw) ?: [];
        $numbers = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (is_numeric($part)) {
                $numbers[] = (string) (float) $part;
            }
        }

        if ($numbers === []) {
            return [[null], $raw];
        }

        return [array_values(array_unique($numbers)), $raw];
    }

    /**
     * Every active item, indexed by normalised name and by normalised SKU.
     *
     * Built once per import. The previous per-variant lookup re-read the
     * whole catalogue twice for every variant, and — more importantly —
     * resolving items inside the normalisation loop meant a fixture item
     * created for row 4 changed what row 40 matched.
     *
     * Exact (case/space-insensitive) only. A fuzzy match across a catalogue
     * where "200Ml Round" exists at both 18 g and 20 g would confidently
     * attach the wrong weight to every shift that product runs — an
     * unmatched row that someone resolves by hand is far cheaper.
     *
     * @return array<string, Item>
     */
    private function itemIndex(): array
    {
        $byName = [];
        $bySku = [];

        foreach (Item::query()->where('is_active', true)->get(['id', 'name', 'sku']) as $item) {
            $byName[$this->normaliseName((string) $item->name)] ??= $item;
            $bySku[$this->normaliseName((string) $item->sku)] ??= $item;
        }

        // Name wins over SKU: the factory sheet is written in product names,
        // and `+` keeps the left operand's keys on collision.
        return $byName + $bySku;
    }

    /**
     * Find or fabricate the LOCAL fixture item for one factory product name.
     *
     * Matched on the deterministic SKU, never on the name — the name carries
     * the "(LOCAL FIXTURE)" marker, so a name lookup would miss it and every
     * re-run would fabricate a second copy.
     */
    private function localFixtureItem(string $product): Item
    {
        $item = Item::withTrashed()->firstOrNew(['sku' => $this->localFixtureSku($product)]);

        $item->name = $this->localFixtureName($product);
        $item->description = self::LOCAL_FIXTURE_NOTE;
        $item->uom = 'Nos.';
        $item->is_active = true;
        // Deliberately left null: an item with no Tally GUID cannot be
        // mistaken for master data pulled from Tally.
        $item->tally_stock_item_guid = null;
        $item->save();

        if ($item->trashed()) {
            $item->restore();
        }

        return $item;
    }

    private function localFixtureName(string $product): string
    {
        return trim($product).' '.self::LOCAL_FIXTURE_NAME_SUFFIX;
    }

    /**
     * Deterministic and stable across runs, so re-importing reuses the item
     * rather than fabricating a second one.
     *
     * Two different factory names can slug to the same string ("A / B" and
     * "A-B"). Reusing one item for both would silently map every standard of
     * one product onto the other, so a colliding name gets a short
     * deterministic suffix instead.
     */
    private function localFixtureSku(string $product): string
    {
        $base = self::LOCAL_FIXTURE_SKU_PREFIX.Str::upper(Str::slug($product));
        $holder = Item::withTrashed()->where('sku', $base)->first();

        if ($holder === null || $holder->name === $this->localFixtureName($product)) {
            return $base;
        }

        return $base.'-'.mb_substr(md5($this->normaliseName($product)), 0, 6);
    }

    private function normaliseName(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A packaging spec cell as stored text, or null when it says nothing.
     *
     * Trimmed but NOT normalised. The sheet spells one pouch film six ways
     * ("750*610", "750 X 610", "750X 610"); collapsing them would be a guess
     * about which spellings name the same material, and this value's only job
     * is to be read by a supervisor who already knows which one they have.
     */
    private function textOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 64);
    }
}
