<?php

namespace App\Modules\Production\Data;

use App\Modules\Production\Models\ProductionStandard;

/**
 * Packing-material specs the workbook leaves blank, filled from the row's own
 * family — and marked, every one of them, as INFERRED.
 *
 * Distinct from {@see ProductMasterCorrections} on purpose, and the distinction
 * is the whole point: a correction is a figure the FACTORY stated, overriding
 * its own sheet. Everything here is a guess this software made by reading the
 * sheet sideways. Filing the two in one list would let an inference eventually
 * be read as a factory answer, which is precisely the mistake that puts the
 * wrong carton on a real dispatch.
 *
 * Authorised by the owner (31 Jul 2026): "check the product name and try to
 * fill the carton and tray and pouch film for the blank one" — with their own
 * worked example, 375ML KIDNEY taking the 500ML KIDNEY's carton.
 *
 * These three columns are REFERENCE TEXT today: nothing computes from them
 * (BatchPreviewController documents that), which is what makes inferring them
 * safe at all. The packing-materials build will consume them soon, so every
 * fill carries its source row into `production_standards.spec_provenance` and
 * the page marks that cell as inferred rather than stated.
 *
 * THE RULE FOR ADDING ENTRIES: fill only where a row of the SAME product
 * family states the value in the SAME column. Two patterns are deliberately
 * refused, and both are recorded under STILL OPEN below rather than guessed:
 *
 *  - copying a value ACROSS columns of one row (a row's own pouch string
 *    becoming its carton) — that is not evidence about a carton, it is the
 *    same string appearing twice;
 *  - filling from a carton-group sibling when the column being filled plainly
 *    does not follow the carton group.
 *
 * Keyed by the sheet's SL.NO., which survives re-conversion in a way product
 * names do not, and checked against the product name so a reordered sheet
 * cannot apply one bottle's answer to another.
 */
class PackingSpecInferences
{
    /** Who authorised inferring these, and when. Carried into every provenance record. */
    public const INFERRED_BY = 'Owner-authorised family inference (31 Jul 2026)';

    public const INFERRED_ON = '2026-07-31';

    /** The three columns this class may ever touch. */
    public const SPEC_COLUMNS = ['carton_spec', 'tray_spec', 'pouch_spec'];

    /**
     * @var array<int, array{
     *     product: string,
     *     fills: array<string, array{value: string, from_source_reference: string, from_product: string, reason: string}>,
     * }>
     */
    public const INFERENCES = [
        // The only row of the 30ML ROUND family carrying specs is SL 3, and it
        // agrees with SL 2 where SL 2 says anything at all: both pouch films
        // read "750*610". Weight differs (5 g vs 8 g) and that is stated here
        // rather than hidden — it is why this is the weakest of the six, and
        // the page shows the reason next to the value.
        2 => [
            'product' => '30ML ROUND LONG sangam',
            'fills' => [
                'carton_spec' => [
                    'value' => '30ML',
                    'from_source_reference' => '3',
                    'from_product' => '30ML ROUND',
                    'reason' => 'Only row of the 30ML ROUND family that states a carton. Both rows already share the pouch film "750*610", which corroborates the family. Weights differ (5 g here, 8 g on SL 3) — same bottle family, different variant.',
                ],
                'tray_spec' => [
                    'value' => '100ML',
                    'from_source_reference' => '3',
                    'from_product' => '30ML ROUND',
                    'reason' => 'Same source row and same reasoning as the carton above.',
                ],
            ],
        ],

        // The owner's own worked example. Every KIDNEY row that states a
        // carton at all states this one (SL 58, 59, 100).
        60 => [
            'product' => '375ML KIDNEY',
            'fills' => [
                'carton_spec' => [
                    'value' => 'HM 30.5*49',
                    'from_source_reference' => '58',
                    'from_product' => '500ML KIDNEY',
                    'reason' => 'Every KIDNEY row that states a carton states this one (SL 58, 59, 100). The owner named this row and this fill as the example of what to do.',
                ],
            ],
        ],

        // SL 62 and SL 66 are the only two rows in the "500ML ROUND" carton
        // group whose tray reads "500ML", and they agree on all four figures
        // that matter (2 cavities, 26 g, 16.5 s).
        66 => [
            'product' => '200ML KOREAN',
            'fills' => [
                'pouch_spec' => [
                    'value' => '835*610',
                    'from_source_reference' => '62',
                    'from_product' => '300ML BRUTE',
                    'reason' => 'Same carton ("500ML ROUND"), same tray ("500ML"), same 2 cavities / 26 g / 16.5 s. The only other row sharing that tray, and it states this film.',
                ],
            ],
        ],

        // Same base product name, one row apart in the family.
        77 => [
            'product' => '300ML EMCURE - MARKING',
            'fills' => [
                'tray_spec' => [
                    'value' => '835 X 610',
                    'from_source_reference' => '61',
                    'from_product' => '300ML EMCURE',
                    'reason' => 'Same base product name and same carton ("300ML EMCURE"). SL 61 states this value in the tray column specifically — this is not the row\'s own pouch string copied sideways.',
                ],
            ],
        ],

        // Within the "200ML ROUND" carton group the pouch film follows the
        // TRAY, not the carton: the rows whose tray reads "60ML" carry
        // 780*610 or 750*610, and the four whose tray reads "60ML ROUND"
        // (79, 88, 89, 90) are the sub-family this fill comes from.
        79 => [
            'product' => '60ML LIQUOR',
            'fills' => [
                'pouch_spec' => [
                    'value' => '750X 610',
                    'from_source_reference' => '88',
                    'from_product' => '100ML BOSTON',
                    'reason' => 'Same carton ("200ML ROUND") AND same tray ("60ML ROUND"). All three other rows of that tray sub-family (SL 88, 89, 90) state this film and no other.',
                ],
            ],
        ],

        // Same base name, identical weight and cycle time.
        99 => [
            'product' => '450ML RIBBED',
            'fills' => [
                'carton_spec' => [
                    'value' => 'LD 30 X 49',
                    'from_source_reference' => '73',
                    'from_product' => '450ML RIBBED CLEAR',
                    'reason' => 'Same base product name, identical 34 g and 16.5 s. Every row of the "LD 30 X 49" carton group carries that string in the carton column.',
                ],
            ],
        ],

        // ------------------------------------------------------------------
        // STILL OPEN — deliberately NOT inferred. Each of these has a
        // candidate value and no evidence that would survive being wrong.
        //
        // SL 80 (750ML KIDNEY) and SL 81 (500ML KIDNEY LONG NECK) have a blank
        // carton and a pouch reading "HM 30 X 49". The tempting fill is that
        // same string moved into the carton column; the other tempting fill is
        // the KIDNEY family's "HM 30.5*49", which belongs to 28-30 g rows while
        // these two are 39 g. Neither is family evidence about a CARTON.
        //
        // SL 72 (500ML ROUND), 74 (500ML JLI), 75 (450ML TAPPER) have a blank
        // pouch and a tray reading "LD 28.5 X 38". The pouch column does not
        // follow the "500ML ROUND" carton group at all — its members read
        // 835*610, LD28.5 X 39, LD 28.5 X 38 and blank — so the only support
        // for a fill is the row's own tray string, which would make one row
        // claim the same material as both tray and pouch.
        //
        // SL 83, 85, 86, 87: only weight-mismatched same-carton candidates.
        //
        // The three carton groups blank in BOTH tray and pouch for every
        // member ("HM 30.5*49", "LD 30 X 49", "LD 28.5 X 38") are not gaps at
        // all — they are pouch-direct-to-carton packing with no tray step.
        // Filling those would invent a packing operation the floor does not run.
        // ------------------------------------------------------------------
    ];

    /**
     * The inference for a source row, or null.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array{value: string, from_source_reference: string, from_product: string, reason: string}>|null
     */
    public static function forRow(array $row): ?array
    {
        $slNo = isset($row['sl_no']) && is_numeric($row['sl_no']) ? (int) $row['sl_no'] : null;

        if ($slNo === null || ! isset(self::INFERENCES[$slNo])) {
            return null;
        }

        return self::matches(self::INFERENCES[$slNo], (string) ($row['product'] ?? ''))
            ? self::INFERENCES[$slNo]['fills']
            : null;
    }

    /**
     * One provenance record, exactly as it is stored on the standard.
     *
     * @param  array{value: string, from_source_reference: string, from_product: string, reason: string}  $fill
     * @return array<string, mixed>
     */
    public static function provenance(array $fill): array
    {
        return [
            'inferred' => true,
            'value' => $fill['value'],
            'from_source_reference' => $fill['from_source_reference'],
            'from_product' => $fill['from_product'],
            'reason' => $fill['reason'],
            'inferred_by' => self::INFERRED_BY,
            'inferred_on' => self::INFERRED_ON,
        ];
    }

    /**
     * Fill the blanks on standards already in the database.
     *
     * The seam the data migration calls, kept here rather than inside an
     * anonymous migration class so it can be run — and tested — twice.
     *
     * Only ever writes a column that is BLANK. A spec somebody has since typed
     * in by hand outranks anything this class inferred, and a second run must
     * find nothing left to do rather than restate its own guess over a human's
     * answer.
     *
     * @return int cells filled
     */
    public static function backfill(): int
    {
        $filled = 0;

        foreach (self::INFERENCES as $slNo => $inference) {
            // withTrashed: a soft-deleted standard still shows its specs the
            // moment it is restored, and leaving it behind would make the
            // backfill's result depend on what happened to be deleted.
            $standards = ProductionStandard::withTrashed()
                ->where('source_reference', (string) $slNo)
                ->get()
                ->filter(fn (ProductionStandard $s) => self::matches($inference, (string) $s->source_product_name));

            foreach ($standards as $standard) {
                $provenance = $standard->spec_provenance ?? [];
                $changed = false;

                foreach ($inference['fills'] as $column => $fill) {
                    if (! in_array($column, self::SPEC_COLUMNS, true)) {
                        continue;
                    }
                    if (trim((string) ($standard->{$column} ?? '')) !== '') {
                        continue;
                    }

                    $standard->{$column} = $fill['value'];
                    $provenance[$column] = self::provenance($fill);
                    $changed = true;
                    $filled++;
                }

                if ($changed) {
                    $standard->spec_provenance = $provenance;
                    $standard->saveQuietly();
                }
            }
        }

        return $filled;
    }

    /** @param array{product: string, fills: array<string, mixed>} $inference */
    private static function matches(array $inference, string $product): bool
    {
        return self::normalise($inference['product']) === self::normalise($product);
    }

    private static function normalise(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '';
    }
}
