<?php

namespace App\Modules\Production\Data;

/**
 * Factory answers to the questions the product master could not settle itself.
 *
 * These are corrections applied ON TOP of the workbook, never edits to it. The
 * converted rows stay a verbatim copy of the sheet, so when the factory sends a
 * new master the conversion can be re-run without silently reverting an answer
 * somebody had already given — which is exactly what editing the JSON in place
 * would do, and it would do it invisibly.
 *
 * Every entry records WHO confirmed it and WHEN, because each one overrides a
 * figure the factory's own sheet states. A correction with no provenance is
 * indistinguishable from a typo introduced by whoever last touched this file.
 *
 * Two kinds:
 *
 *  - `set` replaces a cell. Use when the sheet is simply wrong.
 *  - `confirm_split` says a multi-valued cell is DELIBERATE. The importer
 *    already splits those into separate variants; this only stops them being
 *    flagged as an open question. It never changes a number.
 *
 * Keyed by the sheet's SL.NO., which is stable across re-conversions in a way
 * product names are not.
 */
class ProductMasterCorrections
{
    /**
     * @var array<int, array{
     *     product: string,
     *     set?: array<string, string>,
     *     confirm_split?: list<string>,
     *     reason: string,
     *     confirmed_by: string,
     *     confirmed_on: string,
     * }>
     */
    public const array ANSWERS = [
        // The pouch figures could not all be true at once: 105 per pouch x 5
        // pouches = 525, against the sheet's own 520 per box. The factory says
        // the pouch holds 104, which reconciles exactly (104 x 5 = 520) and
        // makes the sheet's per-box figure the correct one.
        //
        // Only the rows that actually carry pouch figures are corrected. SL 30,
        // 39 and 49 are the tray siblings of the same standards and state no
        // pouch at all, so there is nothing on them to fix.
        29 => [
            'product' => '200ML ROUND',
            'set' => ['nos_per_pouch' => '104'],
            'reason' => '104 bottles per pouch, not 105 — reconciles with the sheet\'s 520 per box.',
            'confirmed_by' => 'factory',
            'confirmed_on' => '2026-07-30',
        ],
        38 => [
            'product' => '200ML ROUND',
            'set' => ['nos_per_pouch' => '104'],
            'reason' => 'Same pouch as SL 29.',
            'confirmed_by' => 'factory',
            'confirmed_on' => '2026-07-30',
        ],
        48 => [
            'product' => '200ML ROUND',
            'set' => ['nos_per_pouch' => '104'],
            'confirm_split' => ['unit_weight_grams'],
            'reason' => 'Same pouch as SL 29. Weight cell "18/20" is deliberate — both are run, and both variants are wanted.',
            'confirmed_by' => 'factory',
            'confirmed_on' => '2026-07-30',
        ],
        49 => [
            'product' => '200ML ROUND',
            'confirm_split' => ['unit_weight_grams'],
            'reason' => 'Tray sibling of SL 48; the same "18/20" weight split is deliberate.',
            'confirmed_by' => 'factory',
            'confirmed_on' => '2026-07-30',
        ],

        // Both weights are genuinely run; the two variants are wanted.
        51 => [
            'product' => '250ML SQUARE',
            'confirm_split' => ['unit_weight_grams'],
            'reason' => 'Both unit weights (20 and 18) are real and are wanted as separate variants.',
            'confirmed_by' => 'factory',
            'confirmed_on' => '2026-07-30',
        ],
        52 => [
            'product' => '250ML SQUARE',
            'confirm_split' => ['unit_weight_grams'],
            'reason' => 'Tray sibling of SL 51; the same "20/18" weight split is deliberate.',
            'confirmed_by' => 'factory',
            'confirmed_on' => '2026-07-30',
        ],

        // Cycle time was blank; the factory supplied it.
        97 => [
            'product' => '200CC  (43MM NECK)',
            'set' => ['cycle_time' => '12.5'],
            'reason' => 'Cycle time 12.5 s (2 cavities, 19.5 g) supplied by the factory.',
            'confirmed_by' => 'factory',
            'confirmed_on' => '2026-07-30',
        ],

        // STILL OPEN — deliberately not answered here.
        //
        // SL 72, 500ML ROUND, carries TWO multi-valued cells: cycle time
        // "21.5 / 17.8" and unit weight "36 / 31.5". That yields four generated
        // combinations and only the factory can say which pairings are real.
        //
        // There is a HINT next door but it is not an answer: SL 98,
        // "500ML ROUND / IFF", states 36 g at 17.8 s as single values. That
        // pairing would make 31.5 g belong with 21.5 s on SL 72 — but IFF is a
        // different product name and inferring across products is exactly the
        // kind of guess that puts a wrong cycle time on a real run. It stays
        // flagged until someone says so out loud.
        //
        // Do NOT add a confirm_split here to silence it: the split is not the
        // question, the pairing is.
    ];

    /**
     * The correction for a source row, or null.
     *
     * Matched on SL.NO. and then checked against the product name: a sheet
     * whose rows have been reordered would otherwise apply an answer about one
     * bottle to a different one.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function forRow(array $row): ?array
    {
        $slNo = isset($row['sl_no']) && is_numeric($row['sl_no']) ? (int) $row['sl_no'] : null;

        if ($slNo === null || ! isset(self::ANSWERS[$slNo])) {
            return null;
        }

        $answer = self::ANSWERS[$slNo];

        $expected = preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $answer['product'])));
        $actual = preg_replace('/\s+/', ' ', mb_strtolower(trim((string) ($row['product'] ?? ''))));

        return $expected === $actual ? $answer : null;
    }
}
