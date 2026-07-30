<?php

namespace App\Modules\Production\Data;

/**
 * Which Tally stock items each mould's standard applies to.
 *
 * ## Why this is a table and not a matcher
 *
 * Three naming schemes describe the same bottle. The product master names the
 * MOULD ("100ML ROUND"). Tally names the SELLABLE SKU, carrying colour and unit
 * weight ("B.100 Ml Round Pet Bottle Clear-12.9gms"). The daily production
 * sheet names the product and keeps colour in its own column.
 *
 * A rule that infers the join is possible — size plus shape plus unit weight,
 * with colour ignored — but inference is the wrong thing to SHIP. A near-miss
 * here silently puts the wrong cycle time, cavity count and unit weight onto a
 * real run for a whole shift, and every downstream figure (expected output,
 * efficiency, material reconciliation, the Tally voucher) is derived from
 * those. A missed match, by contrast, is loud and harmless: the product shows
 * as unconfigured and the supervisor types the figures by hand.
 *
 * So the inference was done once, adversarially reviewed, and the surviving
 * conclusions are recorded here as data a person can read, check against the
 * catalogue and correct. Nothing is inferred at run time.
 *
 * ## The factory's rule, which this encodes
 *
 * ONE mould standard covers ALL colour variants of that bottle. That is why a
 * single entry lists several items — "15ML ROUND" is one standard applying to
 * both the Amber and the Clear SKU. Each entry therefore becomes one
 * production_standards ROW PER ITEM, all carrying identical figures.
 *
 * ## What is deliberately absent
 *
 * 48 of the 76 mould names are NOT here. They fall into three groups, and none
 * of them may be guessed at:
 *
 *  - the item exists but the sheet and Tally disagree on unit weight
 *    (60ML EMCURE, 180ML FLAT, 300ML EMCURE - MARKING, 750ML OVAL and others);
 *  - no item of that shape exists at that size (90ML FLAT, 200ML DOME,
 *    500ML DETTOL, 1000ML BOSTON and others) — either Tally has it under a name
 *    nobody recognised, or the product is not made any more;
 *  - the mould name cannot be told apart from a near-duplicate
 *    (450ML RIBBED vs 450ML RIBBED CLEAR both claim the same 34 g item at
 *    450 ml, with 7 and 3 cavities respectively — so neither may claim it).
 *
 * 500ML ROUND (sl72) is absent for a sharper reason: its sheet cell pairs
 * weight "36 / 31.5" with cycle time "21.5 / 17.8", and the factory's own daily
 * production reports contradict the 21.5 — every 36 g run of that bottle is
 * logged between 18.16 and 19.0 seconds, while 21.5 is what 500 ml JLI runs at
 * the same weight and cavity count. Encoding 21.5 would compute expected output
 * roughly 14% low and report efficiency near 116% on every run.
 *
 * Every entry is keyed on the full variant identity, not just the product name,
 * because one mould name legitimately has several variants differing in
 * cavities, weight or cycle time.
 */
class MouldItemMap
{
    /**
     * @var list<array{
     *     product: string, cavities: int, unit_weight_grams: string,
     *     cycle_time: string, source_rows: list<int>, items: list<string>,
     * }>
     */
    public const array ENTRIES = [
        [
            'product' => '15ML ROUND',
            'cavities' => 5,
            'unit_weight_grams' => '5',
            'cycle_time' => '10.8',
            'source_rows' => [1],
            'items' => [
                'A.15ml Round Pet Bottle Amber-5gms',
                'A.15ml Round Pet Bottle Clear -5gms',
            ],
        ],
        [
            'product' => '30ML ROUND',
            'cavities' => 5,
            'unit_weight_grams' => '8',
            'cycle_time' => '12.2',
            'source_rows' => [3],
            'items' => [
                'A.30ml Round Pet Bottle Amber- 8gms',
                'A.30ml Round Pet Bottle Clear-8gms',
            ],
        ],
        [
            'product' => '60ML ROUND',
            'cavities' => 5,
            'unit_weight_grams' => '10',
            'cycle_time' => '10.8',
            'source_rows' => [4, 5],
            'items' => [
                'A.60 Ml Round Amber Pet Bottle 10gms',
                'A.60 Ml Round Pet Bottle Clear 10 Gms',
            ],
        ],
        [
            'product' => '100ML EMCURE',
            'cavities' => 5,
            'unit_weight_grams' => '12.9',
            'cycle_time' => '12.3',
            'source_rows' => [10],
            'items' => [
                'B.100 ML Emcure Amber Pet Bottle-12.9gms-W/o Ring',
            ],
        ],
        [
            'product' => '100ML BRUTE',
            'cavities' => 5,
            'unit_weight_grams' => '12.9',
            'cycle_time' => '12.2',
            'source_rows' => [13],
            'items' => [
                'B.100 Ml Brute Amber Pet Bottle-12.9gms',
            ],
        ],
        [
            'product' => '100ML EMCURE - RING',
            'cavities' => 5,
            'unit_weight_grams' => '12.9',
            'cycle_time' => '12.2',
            'source_rows' => [14, 15],
            'items' => [
                'B.100 ML Emcure Amber Pet Bottle-12.9gms WR',
            ],
        ],
        [
            'product' => '90ML HYBRID',
            'cavities' => 5,
            'unit_weight_grams' => '12.9',
            'cycle_time' => '11.5',
            'source_rows' => [18],
            'items' => [
                'L.90ML Hybrid Pet Bottle Clear - 12.9 Gms',
            ],
        ],
        [
            'product' => '170ml round',
            'cavities' => 4,
            'unit_weight_grams' => '16.5',
            'cycle_time' => '13.2',
            'source_rows' => [19, 20],
            'items' => [
                'B.170 Ml Round Pet Bottle Amber-16.5gms',
                'B.170 Ml Round Pet Bottle Clear-16.5gms',
                'B.170ml Round Milk White-16.5gms',
            ],
        ],
        [
            'product' => '120ML ROUND',
            'cavities' => 4,
            'unit_weight_grams' => '16.5',
            'cycle_time' => '13.2',
            'source_rows' => [21, 22],
            'items' => [
                'B.120 Ml Round Pet Bottle Amber-16.5gms',
                'B.120ml Round Pet Bottle Dark Amber-16.5gms',
            ],
        ],
        [
            'product' => '100ML HAIR OIL',
            'cavities' => 4,
            'unit_weight_grams' => '16.5',
            'cycle_time' => '13.2',
            'source_rows' => [28],
            'items' => [
                'O.100ml Hair Oil -16.5g',
            ],
        ],
        [
            'product' => '375ML RIBBED',
            'cavities' => 5,
            'unit_weight_grams' => '15',
            'cycle_time' => '12.5',
            'source_rows' => [36],
            'items' => [
                'L.375ml Rib Pet Bottle Clear- 15 Gram',
            ],
        ],
        [
            'product' => '250ML SQUARE',
            'cavities' => 4,
            'unit_weight_grams' => '18',
            'cycle_time' => '18',
            'source_rows' => [51, 52],
            'items' => [
                'B.250ml Square Pet Bottle Clear-18gms',
            ],
        ],
        [
            'product' => '300ML EMCURE',
            'cavities' => 2,
            'unit_weight_grams' => '26',
            'cycle_time' => '16.5',
            'source_rows' => [61],
            'items' => [
                'B.300ml Emcure Pet Bottle Amber 26gms',
            ],
        ],
        [
            'product' => '300ML BRUTE',
            'cavities' => 2,
            'unit_weight_grams' => '26',
            'cycle_time' => '16.5',
            'source_rows' => [62],
            'items' => [
                'B.300ml Brute Pet Bottle Amber-26gms',
            ],
        ],
        [
            'product' => '375ML HYBRID',
            'cavities' => 2,
            'unit_weight_grams' => '24',
            'cycle_time' => '13.85',
            'source_rows' => [63],
            'items' => [
                'L.375ml Hybrid Pet Bottle Clear - Cover 24g',
            ],
        ],
        [
            'product' => '400ML ROUND',
            'cavities' => 2,
            'unit_weight_grams' => '26',
            'cycle_time' => '16.5',
            'source_rows' => [64, 65],
            'items' => [
                'B.400ml Round Pet Bottle Clear-26g',
            ],
        ],
        [
            'product' => '240ML BRUTE',
            'cavities' => 2,
            'unit_weight_grams' => '26',
            'cycle_time' => '16.5',
            'source_rows' => [70],
            'items' => [
                'B.240 Ml Brute Pet Bottle Amber-26gms',
            ],
        ],
        [
            'product' => '500ML JLI',
            'cavities' => 3,
            'unit_weight_grams' => '36',
            'cycle_time' => '21.5',
            'source_rows' => [74],
            'items' => [
                'B.500ML JLI Pet Bottle Clear-36gms',
            ],
        ],
        [
            'product' => '450ML TAPPER',
            'cavities' => 3,
            'unit_weight_grams' => '36',
            'cycle_time' => '20',
            'source_rows' => [75],
            'items' => [
                'B. 450 Ml Taper Amber 36 Gms',
            ],
        ],
        [
            'product' => '750ML KIDNEY',
            'cavities' => 2,
            'unit_weight_grams' => '39',
            'cycle_time' => '15.5',
            'source_rows' => [80],
            'items' => [
                'L.750ml Kidney Clear Cover-39gms (110nos)',
            ],
        ],
        [
            'product' => '500ML KIDNEY LONG NECK',
            'cavities' => 2,
            'unit_weight_grams' => '39',
            'cycle_time' => '15.5',
            'source_rows' => [81],
            'items' => [
                'L.500ml Kidney Pet Bottles Green (Long Neck)-39 Gms',
            ],
        ],
        [
            'product' => '1000ML ROUND',
            'cavities' => 2,
            'unit_weight_grams' => '41.5',
            'cycle_time' => '18.25',
            'source_rows' => [82],
            'items' => [
                'C.1000ml Round Milk White Pet Bottle-41.5gms',
                'C.1000ml Round Pet Bottle Amber-41.5gms',
                'C.1000ml Round Pet Bottle Clear-41.5gm',
            ],
        ],
        [
            'product' => '1000ML OVAL',
            'cavities' => 2,
            'unit_weight_grams' => '41.5',
            'cycle_time' => '18.5',
            'source_rows' => [83],
            'items' => [
                'C.1000ml Oval Clear Pet Bottle-41.5gms',
            ],
        ],
        [
            'product' => '500ML KOREAN',
            'cavities' => 2,
            'unit_weight_grams' => '41.5',
            'cycle_time' => '18',
            'source_rows' => [84],
            'items' => [
                'B.500ml Korean Pet Bottle Clear-41.5gms',
            ],
        ],
        [
            'product' => '150CC TABLET CONTAINER',
            'cavities' => 3,
            'unit_weight_grams' => '17',
            'cycle_time' => '14.5',
            'source_rows' => [91, 92],
            'items' => [
                'T.150ml Tablet Container Amber-17gms',
                'T.150ml Tablet Container Milk White-17gms',
            ],
        ],
        [
            'product' => '120CC TABLET CONTAINER',
            'cavities' => 3,
            'unit_weight_grams' => '17',
            'cycle_time' => '14.5',
            'source_rows' => [93, 94],
            'items' => [
                'T.120ml Tablet Container Green-17gms',
            ],
        ],
        [
            'product' => '1000ML ROUND / IFF',
            'cavities' => 2,
            'unit_weight_grams' => '60',
            'cycle_time' => '30.5',
            'source_rows' => [96],
            'items' => [
                'C.1000ML Round Pet Bottle Amber 60gms-IFF',
            ],
        ],
        [
            'product' => '500ML ROUND / IFF',
            'cavities' => 7,
            'unit_weight_grams' => '36',
            'cycle_time' => '17.8',
            'source_rows' => [98],
            'items' => [
                'B.500ml Round Pet Bottles Amber-36g IFF',
            ],
        ],
    ];

    /**
     * The Tally item names a variant's standard applies to.
     *
     * Matched on the whole variant identity. Numbers are compared as numbers so
     * "5" and "5.0000" agree, since the workbook and the database render them
     * differently; the product name is compared case- and whitespace-
     * insensitively for the same reason.
     *
     * @return list<string>
     */
    public static function itemsFor(string $product, ?int $cavities, ?string $weight, ?string $cycleTime): array
    {
        foreach (self::ENTRIES as $entry) {
            if (self::sameName($entry['product'], $product)
                && $entry['cavities'] === $cavities
                && self::sameNumber($entry['unit_weight_grams'], $weight)
                && self::sameNumber($entry['cycle_time'], $cycleTime)) {
                return $entry['items'];
            }
        }

        return [];
    }

    private static function sameName(string $a, string $b): bool
    {
        $norm = fn (string $v) => preg_replace('/\s+/', ' ', mb_strtolower(trim($v)));

        return $norm($a) === $norm($b);
    }

    private static function sameNumber(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return is_numeric($a) && is_numeric($b) && bccomp($a, $b, 4) === 0;
    }
}
