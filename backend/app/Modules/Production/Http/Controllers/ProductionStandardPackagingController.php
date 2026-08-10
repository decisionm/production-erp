<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\SaveProductionStandardPackagingRequest;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The packaging variants of one product standard — add the way a product is
 * really packed (the 490/box tray the workbook never carried), and set or
 * correct each variant's own Tally identity (DEC-20260810-003).
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
class ProductionStandardPackagingController extends Controller
{
    public function store(SaveProductionStandardPackagingRequest $request, ProductionStandard $standard): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $standard, $request) {
            $this->refuseExactDuplicate($standard, $data, null);

            $packaging = $standard->packagings()->create(
                $this->attributes($data, null, (int) $request->user()?->id),
            );

            return response()->json(['data' => $this->describe($packaging)], 201);
        });
    }

    public function update(
        SaveProductionStandardPackagingRequest $request,
        ProductionStandard $standard,
        ProductionStandardPackaging $packaging,
    ): JsonResponse {
        if ((int) $packaging->production_standard_id !== (int) $standard->id) {
            throw ValidationException::withMessages([
                'packaging' => 'This packing option belongs to a different product standard.',
            ]);
        }

        $data = $request->validated();

        return DB::transaction(function () use ($data, $standard, $packaging, $request) {
            $this->refuseExactDuplicate($standard, $data, $packaging->id);

            $packaging->fill($this->attributes($data, $packaging, (int) $request->user()?->id))->save();

            return response()->json(['data' => $this->describe($packaging->fresh())]);
        });
    }

    /**
     * The row as every consumer sees it — counts, completeness, and the
     * identity with its provenance.
     *
     * @return array<string, mixed>
     */
    private function describe(ProductionStandardPackaging $packaging): array
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
     * @param  array<string, mixed>  $data
     */
    private function refuseExactDuplicate(ProductionStandard $standard, array $data, ?int $ignoreId): void
    {
        $mode = (string) $data['mode'];

        $duplicate = $standard->packagings()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('mode', $mode)
            ->where('nos_per_pouch', $data['nos_per_pouch'] ?? null)
            ->where('pouches_per_box', $data['pouches_per_box'] ?? null)
            ->where('nos_per_tray', $data['nos_per_tray'] ?? null)
            ->where('trays_per_box', $data['trays_per_box'] ?? null)
            ->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                'mode' => "An identical {$mode} packing option already exists on this product — edit that one instead of adding a twin.",
            ]);
        }
    }
}
