<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Support\Facades\DB;

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
 *    decision.
 */
class ProductionStandardImportService
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     dry_run: bool,
     *     summary: array{variants: int, matched: int, unmatched: int, unresolved: int, packaging_options: int, source_rows: int},
     *     variants: list<array<string, mixed>>,
     * }
     */
    public function import(array $rows, bool $dryRun, ?int $createdBy, bool $exactOnly = false): array
    {
        $variants = $this->normalise($rows);

        // exactOnly is the production safety setting: write ONLY variants
        // that resolved to exactly one item AND carry no unresolved
        // ambiguity. Everything else is reported as skipped with its reason
        // — a mapping report for the factory, not a silent omission.
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

        $summary = [
            'source_rows' => count($rows),
            'variants' => count($variants),
            'matched' => 0,
            'unmatched' => 0,
            'unresolved' => 0,
            'packaging_options' => 0,
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

        if (! $dryRun) {
            DB::transaction(function () use (&$variants, $createdBy) {
                foreach ($variants as &$variant) {
                    if (($variant['skip_reason'] ?? null) !== null) {
                        continue;
                    }

                    // Idempotent on the variant key: re-running the import
                    // updates rather than duplicating, so a corrected sheet
                    // can be re-imported without cleanup.
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

                    // Exactly one option = the default, so the supervisor is
                    // never asked a question with one answer.
                    $options = $standard->packagings()->get();
                    if ($options->count() === 1) {
                        $options->first()->update(['is_default' => true]);
                    }

                    $variant['created_id'] = $standard->id;
                }
            });
        }

        return ['dry_run' => $dryRun, 'summary' => $summary, 'variants' => array_values($variants)];
    }

    /**
     * Collapse source rows into variants, merging packaging options.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalise(array $rows): array
    {
        $variants = [];

        foreach ($rows as $row) {
            $product = trim((string) ($row['product'] ?? ''));
            if ($product === '') {
                continue;
            }

            $cavities = $this->intOrNull($row['cavities'] ?? null);
            $weight = $this->decimalOrNull($row['unit_weight_grams'] ?? null);

            // One source row can yield several variants when a cell holds
            // several values.
            [$cycleTimes, $rawCycleTime] = $this->cycleTimes($row['cycle_time'] ?? null);

            foreach ($cycleTimes as $cycleTime) {
                $key = implode('|', [mb_strtolower($product), $cavities ?? '', $weight ?? '', $cycleTime ?? '']);

                if (! isset($variants[$key])) {
                    $unresolved = [];
                    if (count($cycleTimes) > 1) {
                        $unresolved[] = "Cycle time cell held several values ({$rawCycleTime}); each is a separate variant pending confirmation of which applies where.";
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
                        'item_id' => $this->matchItem($product)?->id,
                        'matched_item_name' => $this->matchItem($product)?->name,
                        'cavities' => $cavities,
                        'unit_weight_grams' => $weight,
                        'cycle_time' => $cycleTime,
                        'cycle_time_raw' => count($cycleTimes) > 1 || $cycleTime === null ? $rawCycleTime : null,
                        'status' => $unresolved === [] ? 'draft' : 'unresolved',
                        'unresolved_reason' => $unresolved === [] ? null : implode(' ', $unresolved),
                        'source_reference' => (string) ($row['sl_no'] ?? ''),
                        'packagings' => [],
                    ];
                }

                foreach ($this->packagings($row) as $packaging) {
                    // Merge, keyed by mode: this is where a pouch row and a
                    // tray row for the same standard become one standard
                    // with two options.
                    $variants[$key]['packagings'][$packaging['mode']] = $packaging;
                }
            }
        }

        // Re-index packaging maps to lists.
        foreach ($variants as &$variant) {
            $variant['packagings'] = array_values($variant['packagings']);
        }

        return array_values($variants);
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

    /**
     * Split a cycle-time cell into one or more values.
     *
     * "12.2" -> [12.2];  "21.5 / 17.8" -> [21.5, 17.8];  "18/20" -> [18, 20];
     * blank -> [null] (one variant, flagged).
     *
     * @return array{0: list<?string>, 1: ?string}
     */
    private function cycleTimes(mixed $value): array
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
     * Match a factory product name to a Tally item.
     *
     * Exact (case/space-insensitive) only. A fuzzy match across a catalogue
     * where "200Ml Round" exists at both 18 g and 20 g would confidently
     * attach the wrong weight to every shift that product runs — an
     * unmatched row that someone resolves by hand is far cheaper.
     */
    private function matchItem(string $product): ?Item
    {
        $normalised = $this->normaliseName($product);

        return Item::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'sku'])
            ->first(fn (Item $item) => $this->normaliseName($item->name) === $normalised
                || $this->normaliseName((string) $item->sku) === $normalised);
    }

    private function normaliseName(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        return is_numeric($value) ? (string) (float) $value : null;
    }
}
