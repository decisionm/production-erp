<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
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
     */
    public const LOCAL_FIXTURE_SKU_PREFIX = 'LOCAL-';

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
        ];

        foreach ($variants as $variant) {
            $summary[$variant['item_id'] === null ? 'unmatched' : 'matched']++;
            if ($variant['status'] === 'unresolved') {
                $summary['unresolved']++;
            }
            $summary['packaging_options'] += count($variant['packagings']);
            $summary[($variant['skip_reason'] ?? null) === null ? 'importable' : 'skipped']++;
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
                        if (count($cycleTimes) > 1) {
                            $unresolved[] = "Cycle time cell held several values ({$rawCycleTime}); each is a separate variant pending confirmation of which applies where.";
                        }
                        if (count($weights) > 1) {
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
                            'packagings' => [],
                        ];
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
     * Resolve every variant's item from a prebuilt index.
     *
     * @param  list<array<string, mixed>>  $variants
     * @param  array<string, Item>  $index
     * @return list<array<string, mixed>>
     */
    private function attachItems(array $variants, array $index): array
    {
        foreach ($variants as &$variant) {
            $item = $index[$this->normaliseName($variant['source_product_name'])] ?? null;
            $variant['item_id'] = $item?->id;
            $variant['matched_item_name'] = $item?->name;
        }
        unset($variant);

        return $variants;
    }

    /**
     * exactOnly is the production safety setting: write ONLY variants that
     * resolved to exactly one item AND carry no unresolved ambiguity.
     * Everything else is reported as skipped with its reason — a mapping
     * report for the factory, not a silent omission.
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

            // Idempotent on the variant key: re-running the import updates
            // rather than duplicating, so a corrected sheet can be
            // re-imported without cleanup.
            $standard = ProductionStandard::updateOrCreate(
                [
                    'source_product_name' => $variant['source_product_name'],
                    'cavities' => $variant['cavities'],
                    'unit_weight_grams' => $variant['unit_weight_grams'],
                    'cycle_time' => $variant['cycle_time'],
                ],
                [
                    'item_id' => $variant['item_id'],
                    'cycle_time_raw' => $variant['cycle_time_raw'],
                    'status' => $variant['status'],
                    'unresolved_reason' => $variant['unresolved_reason'],
                    'source' => 'ERPPRO29072026',
                    'source_reference' => $variant['source_reference'],
                    'confirmation_status' => 'Factory master 29-Jul',
                    'created_by' => $createdBy,
                ],
            );

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

            $variant['created_id'] = $standard->id;
        }
        unset($variant);

        return $variants;
    }

    /**
     * Packaging options present on one source row.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function packagings(array $row): array
    {
        $out = [];

        $nosPerPouch = $this->intOrNull($row['nos_per_pouch'] ?? null);
        $pouchBox = $this->intOrNull($row['pouch_nos_per_box'] ?? null);
        if ($nosPerPouch !== null && $nosPerPouch > 0) {
            $out[] = [
                'mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => $nosPerPouch,
                // Derived, not assumed: the sheet gives pieces per pouch and
                // pieces per box, so pouches per box follows.
                'pouches_per_box' => ($pouchBox !== null && $nosPerPouch > 0) ? intdiv($pouchBox, $nosPerPouch) : null,
                'nos_per_box' => $pouchBox,
            ];
        }

        $nosPerTray = $this->intOrNull($row['nos_per_tray'] ?? null);
        $trayBox = $this->intOrNull($row['tray_nos_per_box'] ?? null);
        if ($nosPerTray !== null && $nosPerTray > 0) {
            $out[] = [
                'mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => $nosPerTray,
                'trays_per_box' => ($trayBox !== null && $nosPerTray > 0) ? intdiv($trayBox, $nosPerTray) : null,
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
}
