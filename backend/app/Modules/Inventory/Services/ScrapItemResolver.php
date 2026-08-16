<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;

/**
 * THE one place that answers "which stock item does scrap book against?" —
 * used by the quality gate's scrap receipt (ShiftProductionEntryService)
 * AND the Tally voucher's produced scrap line (TallySyncService), so one
 * setting drives both and they cannot name different items. Until this
 * class existed each held its own copy of the lookup, and a copy is how
 * two surfaces drift apart without anyone changing either on purpose.
 *
 * One config string — production.scrap.rejected_item_sku — matched by
 * exact SKU first, then by exact name (a factory that mirrors Tally's
 * "Pet Scrap" as a plain item without a code can still name it). Never a
 * pattern match: "Pet Scrap", "PET Scrap - Amber", "PET Scrap - Lumps" and
 * "Pet Bottles Scrap" all exist in this factory's masters, and a near-miss
 * books real weight against the wrong one. Soft-deleted items are excluded
 * by the model's global scope: a retired master must not silently start
 * receiving stock again.
 *
 * There is deliberately no fallback and no guessing. This ERP has no
 * scrap-item master and no colour → scrap-item mapping (the only
 * colour-driven resolver it has picks MASTERBATCH). Null is the honest
 * answer — but null used to be ONE answer for two different situations,
 * and that is the trap missReason() exists to close: a typo in the config
 * read exactly like "the factory has not named one", so a misconfiguration
 * withheld every scrap line while looking like a decision.
 */
class ScrapItemResolver
{
    /** The setting is blank — nobody has named a scrap item. */
    public const NOT_NAMED = 'not_named';

    /** The setting names something, and no stock item matches it by SKU or exact name. */
    public const NAMED_BUT_NOT_FOUND = 'named_but_not_found';

    /**
     * The configured SKU-or-name, trimmed, or null when the setting is blank.
     * Public so a caller's message can quote exactly what was looked for.
     */
    public function configuredName(): ?string
    {
        $configured = trim((string) (config('production.scrap.rejected_item_sku') ?? ''));

        return $configured === '' ? null : $configured;
    }

    /**
     * The exact stock item scrap posts against, or null when there is none —
     * ask missReason() which of the two nulls it was.
     */
    public function resolve(): ?Item
    {
        $configured = $this->configuredName();

        if ($configured === null) {
            return null;
        }

        return Item::query()->where('sku', $configured)->first()
            ?? Item::query()->where('name', $configured)->first();
    }

    /**
     * WHY resolve() is null: NOT_NAMED or NAMED_BUT_NOT_FOUND. Null when
     * resolve() actually finds an item, so a caller cannot report a miss
     * that did not happen.
     */
    public function missReason(): ?string
    {
        if ($this->configuredName() === null) {
            return self::NOT_NAMED;
        }

        return $this->resolve() === null ? self::NAMED_BUT_NOT_FOUND : null;
    }
}
