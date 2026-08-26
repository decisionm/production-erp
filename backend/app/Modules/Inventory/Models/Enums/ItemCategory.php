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

    /*
     * ---- ADDED 26-Aug-2026, ADDITIVELY -----------------------------------
     *
     * Three cases, and NOT ONE LINE of the four above changed with them —
     * `purchasable()`, `sellable()` and `requestableFromStore()` are written
     * as comparisons against named cases, so a new case falls out of each as
     * purchasable, not sellable, and not requestable from the store. That is
     * the existing behaviour preserved exactly, which is what Q59 being OPEN
     * requires: which categories each document may use is the OWNER'S
     * answer, and nothing here may pre-empt it by widening or narrowing an
     * eligibility rule on the way past.
     *
     * They exist because `Other` had been carrying three unrelated meanings
     * at once ("consumables, spares, tooling, stationery" — its own
     * docblock) and a half-made bottle had no case at all. Naming them
     * separately costs nothing while enforcement is off, and is the
     * difference between a real answer and a shrug once it is on.
     *
     * A category being AVAILABLE is not a category being ASSIGNED. Nothing
     * classifies an item into one of these; a person does.
     */

    /** A half-made thing: moulded and not yet packed, or held between operations. */
    case WorkInProgress = 'work_in_progress';

    /** Bought, and used up in running the plant — oil, gloves, cleaning agents, stationery. */
    case Consumable = 'consumable';

    /** A spare part, a mould insert, a tool — bought, kept, and fitted rather than consumed. */
    case SpareTooling = 'spare_tooling';

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

    /**
     * The word a refusal uses, so a message reads like a sentence.
     *
     * EVERY CASE GETS AN ARM. This is a `match` with no default, so a case
     * added above and forgotten here is an UnhandledMatchError at the moment
     * a refusal tries to name it — i.e. inside the very message that was
     * supposed to explain something.
     */
    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'a raw material',
            self::PackingMaterial => 'a packing material',
            self::FinishedGood => 'a finished good',
            self::Other => 'a consumable or spare',
            self::WorkInProgress => 'work in progress',
            self::Consumable => 'a consumable',
            self::SpareTooling => 'a spare or tooling item',
        };
    }
}
