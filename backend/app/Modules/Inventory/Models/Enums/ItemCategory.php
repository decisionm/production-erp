<?php

namespace App\Modules\Inventory\Models\Enums;

/**
 * WHAT KIND OF THING AN ITEM IS — the factory's own answer, stored.
 *
 * This exists because three document rules need it and nothing in the
 * database could answer them:
 *
 *   * a purchase order is raised for RAW and PACKING material only
 *   * a sales order is raised for FINISHED GOODS only
 *   * a material request from the store is for production input only
 *
 * Before this column the catalogue had three partial signals and no
 * taxonomy. `item_group_id` mirrors Tally's stock groups and is write-only —
 * its own migration says "nothing in the application reads it".
 * `is_production_input` answers one narrower question (may the floor ask the
 * store for this) and stays as it is; it is evidence-backfilled and owner
 * editable, and this enum must not silently replace it. `MeasurementType`
 * classifies the UNIT, and its docblock warns that packing film is measured
 * in kilograms and is not resin.
 *
 * THE COLUMN DECIDES, NOT THE SKU. When SKU prefixes arrive they are DERIVED
 * from this value and never parsed back out of the string. That rule is not a
 * preference: `Item::isLocalFixture()` used to be `str_starts_with($sku,
 * 'LOCAL-')` — a posting gate riding on free text — and it took a dedicated
 * migration and a real column to get it off (2026_08_13_090000).
 *
 * NULL IS A REAL STATE and it is the common one. 458 of 624 active items
 * cannot be classified from any evidence the database holds, and
 * `AGENTS.md` forbids guessing a factory value. Unclassified means
 * "nobody has said yet", never "none of the above" — that is what `Other`
 * is for, and the difference is what lets enforcement refuse a KNOWN wrong
 * item while letting an unclassified one through.
 */
enum ItemCategory: string
{
    /** Resin, masterbatch, additives — what production consumes to make a bottle. */
    case RawMaterial = 'raw_material';

    /** Trays, boxes, pouches, covers, tape — what finished goods are packed in or with. */
    case PackingMaterial = 'packing_material';

    /** What the factory produces and sells. */
    case FinishedGood = 'finished_good';

    /**
     * Legitimately purchased, and neither raw nor packing — consumables,
     * spares, tooling, stationery.
     *
     * A deliberate case rather than an omission: without it, the first
     * purchase of a spare part forces someone to mis-file it as raw
     * material, and a wrong classification is worse than an honest one
     * because the document rules then act on it.
     */
    case Other = 'other';

    /** May this item be bought on a purchase order? */
    public function purchasable(): bool
    {
        return $this !== self::FinishedGood;
    }

    /** May this item be sold on a sales order? */
    public function sellable(): bool
    {
        return $this === self::FinishedGood;
    }

    /** May the floor request this from the store? */
    public function requestableFromStore(): bool
    {
        return $this === self::RawMaterial || $this === self::PackingMaterial;
    }

    /** The word a refusal uses, so a message reads like a sentence. */
    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'a raw material',
            self::PackingMaterial => 'a packing material',
            self::FinishedGood => 'a finished good',
            self::Other => 'a consumable or spare',
        };
    }
}
