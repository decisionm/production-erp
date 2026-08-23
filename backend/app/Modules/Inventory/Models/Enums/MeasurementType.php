<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * WHAT KIND OF THING AN ITEM'S UNIT MEASURES.
 *
 * The factory does not work in kilograms. The 12-Aug Tally evidence carries
 * exactly three unit strings across 43 stock items — `Kgs.`, `Nos.`, `Pcs.` —
 * and **26 of those items are counted, not weighed**. Trays, master boxes,
 * caps, cups and tape are all `Nos.`; resin, masterbatch and packing FILM are
 * `Kgs.`. So "material" and "kilogram" are not the same idea, and any code that
 * treats them as one is wrong for most of the catalogue.
 *
 * THIS CLASSIFIES THE UNIT. It does not classify the MATERIAL, and the
 * difference matters: packing film is measured in kilograms and is not resin.
 * Whether a given kg material is a "common input" that may not name a machine
 * is Q54(a) and belongs to the factory — this enum must never be used to answer
 * it.
 *
 * Written because the same question was being asked in four places with three
 * different answers: Item::KG_UOM_VARIANTS enumerated the dotted spellings,
 * GoodsReceiptService::isMassUom stripped dots and added "kilogram(s)", and the
 * frontend had a fourth copy. Against Tally's actual `Kgs.` all of them agreed,
 * so this is drift caught before it bit, not a live defect.
 *
 * That prediction held. On 23-Aug-2026 the kilogram half was consolidated:
 * the four private isMassUom() copies named above are gone and Item::isKgUom()
 * is their one definition. This enum was deliberately NOT made that
 * definition — it answers Weight for GRAMS, and every caller of isKgUom() sums
 * the quantity AS KILOGRAMS, so routing them here would read 0.32 g of
 * masterbatch as 0.32 kg. Two questions, two answers, on purpose.
 */
enum MeasurementType: string
{
    case Weight = 'weight';
    case Count = 'count';

    /**
     * Unknown is NOT a failure — it is an honest answer for a unit nobody has
     * classified, and it must never quietly become Weight. Defaulting an
     * unrecognised unit to kilograms is exactly the assumption this enum
     * exists to remove.
     */
    case Unknown = 'unknown';

    /**
     * Every spelling of a weight unit this factory's data has ever shown.
     *
     * `kilogram.` and `kilograms.` joined 23-Aug-2026: the list already
     * carried the dotted `kg.`/`kgs.` but not these, so `Kilograms.`
     * classified Unknown while `Kgs.` classified Weight. Added rather than
     * normalised away — see forUom() for why a dot-strip is the wrong fix.
     */
    private const WEIGHT_UNITS = [
        'kg', 'kg.', 'kgs', 'kgs.',
        'kilogram', 'kilogram.', 'kilograms', 'kilograms.',
        'gm', 'gms', 'g', 'gram', 'grams',
    ];

    /** Counted units. Tally writes `Nos.`; the seeders have used `pcs` and `nos`. */
    private const COUNT_UNITS = ['nos', 'nos.', 'no', 'no.', 'pcs', 'pcs.', 'pc', 'pc.', 'piece', 'pieces', 'each', 'ea'];

    /**
     * Classify a raw unit string as Tally spells it — case and trailing dot
     * included, because Tally's `BASEUNITS` reaches `items.uom` verbatim.
     *
     * ENUMERATED, NOT NORMALISED — and a trailing-dot strip here was tried
     * and REVERTED on 23-Aug-2026. Read this before reaching for rtrim():
     *
     * The gap that tempted it is real: WEIGHT_UNITS spelled out `kg.` and
     * `kgs.` but not `kilogram.`/`kilograms.`, so `Kilograms.` classified
     * Unknown while `Kgs.` classified Weight. That is fixed above by adding
     * the two missing spellings — the instance, not the class.
     *
     * Stripping the dot instead looks like the better fix and is not, because
     * rtrim() runs before BOTH lookups and so widens COUNT_UNITS too:
     * `piece.`, `pieces.`, `each.`, `ea.` move Unknown -> Count. Unknown
     * PERMITS fractions and Count REFUSES them, and permitsFractions() is a
     * live write gate on three request paths (StoreMaterialRequestRequest,
     * StoreStoreIssueRequest, StoreStoreIssueReturnRequest). So the strip
     * turns a quantity the factory could enter yesterday into a 422 today —
     * a NEW refusal, which is the one direction a cleanup may never move.
     *
     * The browser reached the same conclusion first and wrote it down:
     * frontend/src/features/material-flow/words.ts mirrors COUNT_UNITS
     * verbatim, "dotted spellings and all", recording that its own first
     * attempt normalised instead and disagreed with the server on exactly
     * those four strings. This list is one half of a two-sided contract; it
     * does not get to change unilaterally.
     */
    public static function forUom(?string $uom): self
    {
        $normalised = mb_strtolower(trim((string) $uom));

        if ($normalised === '') {
            return self::Unknown;
        }

        if (in_array($normalised, self::WEIGHT_UNITS, true)) {
            return self::Weight;
        }

        if (in_array($normalised, self::COUNT_UNITS, true)) {
            return self::Count;
        }

        return self::Unknown;
    }

    /**
     * May a quantity in this unit have a fractional part?
     *
     * PROVEN from the evidence rather than assumed: across 1,045 observed
     * quantities there is not one fractional `Nos.` or `Pcs.`, while every
     * fractional value in the whole set — all 15 of them — is packing film in
     * `Kgs.`. Half a carton is not a thing the factory receives, issues or
     * consumes.
     *
     * Unknown permits fractions, deliberately. Refusing a decimal on a unit
     * nobody has classified would block real work on a guess; the wrong
     * direction to fail when the classification itself is the gap.
     */
    public function permitsFractions(): bool
    {
        return $this !== self::Count;
    }

    /** The word a refusal uses, so a message reads like a sentence. */
    public function label(): string
    {
        return match ($this) {
            self::Weight => 'a weight',
            self::Count => 'a whole number of items',
            self::Unknown => 'an unclassified unit',
        };
    }
}
