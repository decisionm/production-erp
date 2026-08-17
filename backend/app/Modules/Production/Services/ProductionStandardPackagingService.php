<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Exceptions\DuplicatePackagingVariantException;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The packaging variants of one product standard — add the way a product is
 * really packed (the 490/box tray the workbook never carried), and set or
 * correct each variant's own Tally identity (DEC-20260810-003).
 *
 * THE VARIANT KEY IS THE MODE PLUS ITS COUNTS (Phase 5, D1). One standard
 * may carry a 490/box tray AND a 520/box tray; what it may not carry is the
 * same mode with the same counts twice — that is refused here, named, as a
 * 422 (DuplicatePackagingVariantException). The database's
 * psp_standard_variant_unique is the backstop for fully-stated rows; NULL
 * counts never collide at the database, so this rule is the one a person
 * actually meets.
 *
 * ONE DEFAULT PER STANDARD. `is_default` is what resolvePackaging() adopts
 * when a standard has more than one complete option and nobody chose —
 * two defaults would put a question with one answer back on the picker,
 * so setting a default here clears the standard's previous one in the same
 * transaction.
 *
 * Identity provenance mirrors the packing-material master: item_set_by /
 * item_set_at stamp who answered, and only when the ANSWER changes — an
 * edit that only touches counts must not claim a person re-confirmed the
 * identity.
 *
 * Nothing here rewrites history: a completed batch froze the identity it
 * posted under (shift_production_entries.finished_item_id), so editing a
 * variant changes future batches only.
 */
class ProductionStandardPackagingService
{
    /**
     * @param  array<string, mixed>  $data  a SaveProductionStandardPackagingRequest's validated payload
     */
    public function store(ProductionStandard $standard, array $data, ?int $userId): ProductionStandardPackaging
    {
        return DB::transaction(function () use ($data, $standard, $userId) {
            $this->refuseExactDuplicate($standard, $data, null);

            $packaging = $standard->packagings()->create(
                $this->attributes($data, null, (int) $userId),
            );

            $this->keepOneDefault($standard, $packaging);

            return $packaging->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  a SaveProductionStandardPackagingRequest's validated payload
     */
    public function update(
        ProductionStandard $standard,
        ProductionStandardPackaging $packaging,
        array $data,
        ?int $userId,
    ): ProductionStandardPackaging {
        if ((int) $packaging->production_standard_id !== (int) $standard->id) {
            throw ValidationException::withMessages([
                'packaging' => 'This packing option belongs to a different product standard.',
            ]);
        }

        return DB::transaction(function () use ($data, $standard, $packaging, $userId) {
            $this->refuseExactDuplicate($standard, $data, $packaging);

            $packaging->fill($this->attributes($data, $packaging, (int) $userId))->save();

            $this->keepOneDefault($standard, $packaging);

            return $packaging->fresh();
        });
    }

    /**
     * The row as every consumer sees it — counts, completeness, and the
     * identity with its provenance.
     *
     * @return array<string, mixed>
     */
    public function describe(ProductionStandardPackaging $packaging): array
    {
        $packaging->loadMissing(['tallyItem', 'standard.item']);

        return [
            'id' => (int) $packaging->id,
            'mode' => (string) $packaging->mode,
            'nos_per_pouch' => $packaging->nos_per_pouch,
            'pouches_per_box' => $packaging->pouches_per_box,
            'nos_per_tray' => $packaging->nos_per_tray,
            'trays_per_box' => $packaging->trays_per_box,
            'nos_per_box' => $packaging->nos_per_box,
            'is_default' => (bool) $packaging->is_default,
            'is_complete' => $packaging->isComplete(),
            'tally_item' => $packaging->item_id === null ? null : [
                'id' => (int) $packaging->item_id,
                'name' => (string) $packaging->tallyItem?->name,
            ],
            // What the run WILL post as, resolved — so the screen can print
            // "using product identity" without re-deriving the fallback rule.
            'resolved_item_name' => (string) ($packaging->tallyItem?->name
                ?? $packaging->standard?->item?->name
                ?? ''),
            'uses_product_identity' => $packaging->item_id === null,
        ];
    }

    /**
     * The writable columns from one validated payload. nos_per_box is
     * DERIVED for pouch/tray (inner × count) exactly as the standards page
     * derives it — the third figure can never disagree with the two it came
     * from. Identity provenance stamps only when the answer changes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?ProductionStandardPackaging $existing, int $userId): array
    {
        $mode = (string) $data['mode'];

        $pouch = $mode === ProductionStandardPackaging::MODE_POUCH;
        $tray = $mode === ProductionStandardPackaging::MODE_TRAY;

        $nosPerPouch = $pouch ? ($data['nos_per_pouch'] ?? $existing?->nos_per_pouch) : null;
        $pouchesPerBox = $pouch ? ($data['pouches_per_box'] ?? $existing?->pouches_per_box) : null;
        $nosPerTray = $tray ? ($data['nos_per_tray'] ?? $existing?->nos_per_tray) : null;
        $traysPerBox = $tray ? ($data['trays_per_box'] ?? $existing?->trays_per_box) : null;

        $nosPerBox = match (true) {
            $pouch => $nosPerPouch !== null && $pouchesPerBox !== null ? $nosPerPouch * $pouchesPerBox : null,
            $tray => $nosPerTray !== null && $traysPerBox !== null ? $nosPerTray * $traysPerBox : null,
            default => $data['nos_per_box'] ?? $existing?->nos_per_box,
        };

        $attributes = [
            'mode' => $mode,
            'nos_per_pouch' => $nosPerPouch,
            'pouches_per_box' => $pouchesPerBox,
            'nos_per_tray' => $nosPerTray,
            'trays_per_box' => $traysPerBox,
            'nos_per_box' => $nosPerBox,
            'is_default' => (bool) ($data['is_default'] ?? $existing?->is_default ?? false),
        ];

        if (array_key_exists('item_id', $data)) {
            $newItemId = $data['item_id'] === null ? null : (int) $data['item_id'];

            $attributes['item_id'] = $newItemId;

            if ($newItemId !== ($existing?->item_id === null ? null : (int) $existing->item_id)) {
                $attributes['item_set_by'] = $userId ?: null;
                $attributes['item_set_at'] = now();
            }
        }

        return $attributes;
    }

    /**
     * Two rows stating the SAME mode and the SAME counts would leave the
     * Start Batch picker offering one choice twice — refused with the row
     * named, so the person corrects the existing one instead.
     *
     * Compared on the four INNER counts as they would be stored (a count
     * that does not belong to the mode is null whatever the payload said),
     * so a tray payload carrying a stray pouch figure cannot slip past a
     * genuine twin — the same normalisation attributes() applies. nos_per_box
     * is derived from those for pouch/tray and stated for direct_box, where
     * it is the whole spec and is compared as such.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseExactDuplicate(ProductionStandard $standard, array $data, ?ProductionStandardPackaging $existing): void
    {
        $mode = (string) $data['mode'];
        $stored = $this->attributes($data, $existing, 0);

        $duplicate = $standard->packagings()
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
            ->where('mode', $mode)
            ->where('nos_per_pouch', $stored['nos_per_pouch'])
            ->where('pouches_per_box', $stored['pouches_per_box'])
            ->where('nos_per_tray', $stored['nos_per_tray'])
            ->where('trays_per_box', $stored['trays_per_box'])
            ->when(
                $mode === ProductionStandardPackaging::MODE_DIRECT_BOX,
                fn ($query) => $query->where('nos_per_box', $stored['nos_per_box']),
            )
            ->first();

        if ($duplicate !== null) {
            throw DuplicatePackagingVariantException::make($standard, $mode);
        }
    }

    /**
     * ONE default per standard: when the row just written is the default,
     * every sibling stops being one. Rows on other standards are untouched
     * — the rule is per standard, not per product.
     */
    private function keepOneDefault(ProductionStandard $standard, ProductionStandardPackaging $packaging): void
    {
        if (! $packaging->is_default) {
            return;
        }

        $standard->packagings()
            ->whereKeyNot($packaging->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
