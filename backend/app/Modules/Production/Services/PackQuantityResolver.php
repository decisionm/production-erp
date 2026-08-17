<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ShiftProductionEntry;

/**
 * THE ONE READER of pack quantities (Phase 5, P5-04).
 *
 * Start Batch froze packaging ?? item counts into the run's snapshot, and
 * completion writes the counts the supervisor typed onto the entry's own
 * columns — but until this class the metric reader consulted only
 * `entry->nos_per_box ?? item->nos_per_box`, never the packaging row the
 * run was started against, and never a tray or pouch count at all
 * (§4.14). A 490/box tray run of a product whose master says 520 was
 * therefore measured against 520.
 *
 * Precedence, per figure:
 *
 *   1. the entry's frozen columns  — what the supervisor recorded at
 *      completion (nos_per_box, nos_per_tray, nos_per_pouch; the entry has
 *      no trays_per_box / pouches_per_box column, so those two skip this
 *      rung). A run packed at a non-standard count is measured against
 *      the count it was packed at, and history never rewrites itself when
 *      a master changes later.
 *   2. the run's packaging row     — production_standard_packaging_id, the
 *      option Start Batch selected (or the shift page row froze).
 *   3. the item master             — the last fallback, unchanged.
 *
 * Nothing is invented: a figure no rung holds is null, labelled 'none'.
 * Pure read — no writes, no side effects, safe on an unsaved model.
 */
class PackQuantityResolver
{
    /** The entry columns that carry a pack count, keyed by figure. */
    private const ENTRY_COLUMNS = [
        'nos_per_box' => 'nos_per_box',
        'nos_per_tray' => 'nos_per_tray',
        'nos_per_pouch' => 'nos_per_pouch',
    ];

    public function forEntry(ShiftProductionEntry $entry): PackQuantities
    {
        // The packaging the run froze, when it froze one. loadMissing keeps
        // a relation a caller already set (setRelation on a bare model);
        // an unsaved entry with no packaging id never queries.
        $packaging = null;
        if ($entry->production_standard_packaging_id !== null) {
            $entry->loadMissing('standardPackaging');
            $packaging = $entry->standardPackaging;
        }

        $entry->loadMissing('item');

        return $this->resolve(
            fn (string $field) => isset(self::ENTRY_COLUMNS[$field])
                ? $this->int($entry->getAttribute(self::ENTRY_COLUMNS[$field]))
                : null,
            $packaging,
            $entry->item,
        );
    }

    /**
     * The same precedence for a run that does not exist yet — the Start
     * Batch estimate — where the only rungs are the chosen packaging and
     * the item master. Typed loosely on purpose: the estimation service
     * takes ?object for the packaging so previews can pass a plain row.
     */
    public function forSelection(?object $packaging, ?Item $item): PackQuantities
    {
        return $this->resolve(fn (string $field) => null, $packaging, $item);
    }

    /**
     * @param  callable(string): ?int  $entryValue  the frozen entry column for a figure, or null
     */
    private function resolve(callable $entryValue, ?object $packaging, ?Item $item): PackQuantities
    {
        $values = [];
        $sources = [];

        foreach (PackQuantities::FIELDS as $field) {
            $fromEntry = $entryValue($field);
            if ($fromEntry !== null) {
                $values[$field] = $fromEntry;
                $sources[$field] = PackQuantities::SOURCE_ENTRY;

                continue;
            }

            $fromPackaging = $packaging !== null ? $this->int($packaging->{$field} ?? null) : null;
            if ($fromPackaging !== null) {
                $values[$field] = $fromPackaging;
                $sources[$field] = PackQuantities::SOURCE_PACKAGING;

                continue;
            }

            $fromItem = $item !== null ? $this->int($item->{$field} ?? null) : null;
            if ($fromItem !== null) {
                $values[$field] = $fromItem;
                $sources[$field] = PackQuantities::SOURCE_ITEM;

                continue;
            }

            $values[$field] = null;
            $sources[$field] = PackQuantities::SOURCE_NONE;
        }

        return new PackQuantities(
            nos_per_box: $values['nos_per_box'],
            nos_per_tray: $values['nos_per_tray'],
            trays_per_box: $values['trays_per_box'],
            nos_per_pouch: $values['nos_per_pouch'],
            pouches_per_box: $values['pouches_per_box'],
            source: $this->summarySource($sources),
            sources: $sources,
        );
    }

    /**
     * The one-word source: nos_per_box's rung when it has one, else the
     * highest rung that answered any figure, else 'none'.
     *
     * @param  array<string, string>  $sources
     */
    private function summarySource(array $sources): string
    {
        if ($sources['nos_per_box'] !== PackQuantities::SOURCE_NONE) {
            return $sources['nos_per_box'];
        }

        foreach ([PackQuantities::SOURCE_ENTRY, PackQuantities::SOURCE_PACKAGING, PackQuantities::SOURCE_ITEM] as $rung) {
            if (in_array($rung, $sources, true)) {
                return $rung;
            }
        }

        return PackQuantities::SOURCE_NONE;
    }

    /**
     * A stored count as an int, or null. ONLY null is "no count": a stated
     * zero stays a zero on the rung that stated it, exactly as the `??`
     * chains this replaces left it (every consumer guards `> 0` before
     * dividing), so the results are byte-identical to the old readers.
     */
    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
