<?php

namespace App\Modules\Production\Data;

/**
 * Metres of packing tape consumed sealing ONE carton, by carton type.
 *
 * Given by the owner on 31 Jul 2026, verbatim, thirteen rows. Keyed by the
 * CARTON — not the product — because tape wraps the box, and every product
 * packed into a "100 ROUND" carton seals with the same 2.296 m regardless of
 * what is inside. This matches how the workbook's CARTON column already names
 * a box type per product, so tape per batch is:
 *
 *     metres = cartons packed × metres_per_box[carton type]
 *
 * The figures themselves carry a shape worth preserving against typos later:
 * the eight small cartons cluster tightly at 2.226–2.306 m, the 500-class
 * boxes step up (2.666 / 3.660 / 3.700 / 4.516), and 450 RIB stands at
 * 5.160 m — roughly double the cluster, consistent with a double-wrapped box.
 * If a future edit makes a small box claim five metres, that is the row to
 * re-check with the floor, not silently accept.
 *
 * Stored as a Data class (the MouldItemMap precedent) rather than seeded
 * straight into a table: the join from these box names ("15 ROUND",
 * "500 JLI/R (FULL BOX)") to the workbook's CARTON specs ("15ML",
 * "500ML ROUND") and to the Tally tape items ("Packing Tape - Transparent",
 * "Packing Tape Green") is a mapping decision the packing-materials build
 * resolves under test — a person can read this file against the owner's
 * message and check every number without running anything.
 *
 * Open with the factory before this reaches a voucher:
 *  - Tally's tape items are counted in "Nos" on the daily Stock Journals
 *    (e.g. "Packing Tape - Transparent 720 Nos"). Is a "No" a metre, or a
 *    piece/strip? The conversion from metres depends on the answer.
 *  - Which cartons seal with Green tape rather than Transparent.
 */
class TapeMetresPerBox
{
    /** @var array<string, string> box type => metres per box, 3dp, as given */
    public const METRES = [
        '15 ROUND' => '2.276',
        '30 ROUND' => '2.306',
        '60 ROUND' => '2.296',
        '100 ROUND' => '2.296',
        '170 ROUND' => '2.290',
        '200 ROUND' => '2.282',
        '200 BRUTE' => '2.226',
        '300 EMCURE' => '2.236',
        '500 ROUND' => '2.666',
        '450 RIB' => '5.160',
        '500 KIDNEY' => '3.660',
        '750 KIDNEY' => '3.700',
        '500 JLI/R (FULL BOX)' => '4.516',
    ];
}
