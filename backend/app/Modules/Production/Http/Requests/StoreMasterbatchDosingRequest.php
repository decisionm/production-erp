<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Entering the factory's dosing figure. Every message names the fix, not just
 * the fault — the person typing this is setting a number that multiplies into
 * a Tally consumption quantity, and a bare "invalid" tells them nothing.
 */
class StoreMasterbatchDosingRequest extends FormRequest
{
    /**
     * A typo guard, not a factory rule. A bottle taking more than a kilogram
     * of colour is not a dosing anyone meant to type; the exact value is
     * arbitrary and deliberately generous, because refusing a real figure
     * would be worse than accepting an odd one.
     */
    private const MAX_GRAMS_PER_BOTTLE = 1000;

    public function authorize(): bool
    {
        // Route-group middleware ('module:production') already requires
        // production.manage for a non-GET, same as every write neighbour.
        return true;
    }

    public function rules(): array
    {
        return [
            'masterbatch_item_id' => ['required', 'integer', 'exists:items,id'],
            // Null / absent = the figure applies to every product using this
            // masterbatch. That is the only shape the factory has stated.
            'product_item_id' => ['sometimes', 'nullable', 'integer', 'exists:items,id'],
            // GRAMS per bottle. gt:0 because "no dosing" is expressed by
            // having no row (or withdrawing it), never by a zero — a stored
            // zero would tell the floor this colour needs no masterbatch.
            // decimal:0,4 refuses precision the decimal(12,4) column would
            // silently round away: 0.25001 must be questioned, not stored as
            // 0.2500 behind the typist's back.
            'grams_per_bottle' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_GRAMS_PER_BOTTLE, 'decimal:0,4'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'grams_per_bottle.gt' => 'Grams per bottle must be more than zero. To stop prefilling this masterbatch, remove the dosing instead — a zero would tell the floor this colour needs no masterbatch.',
            'grams_per_bottle.decimal' => 'Grams per bottle carries at most 4 decimal places (0.25 for amber). Round it yourself rather than letting the database do it silently.',
            'grams_per_bottle.max' => 'Grams per bottle over '.self::MAX_GRAMS_PER_BOTTLE.' looks like a units slip — this figure is GRAMS per bottle, not kg.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $masterbatchId = $this->input('masterbatch_item_id');
            $productId = $this->input('product_item_id');

            if ($masterbatchId !== null && $productId !== null && (int) $masterbatchId === (int) $productId) {
                $validator->errors()->add(
                    'product_item_id',
                    'The masterbatch and the product cannot be the same item. Leave the product blank to apply this dosing to every bottle that uses this masterbatch.',
                );
            }

            $item = $masterbatchId === null ? null : Item::query()->find($masterbatchId);

            if ($item === null) {
                return;
            }

            // A kg-family unit is this database's only signal for "raw
            // material" (Item::scopeKgUom) — the same signal the day-bin
            // picker uses. Checked rather than assumed because a dosing set
            // against a bottle (Nos) would compute kg of bottles per bottle.
            if (! $item->hasKgUom()) {
                $validator->errors()->add(
                    'masterbatch_item_id',
                    "\"{$item->name}\" is measured in {$item->uom}, not kg — masterbatch is weighed in kg. Pick the masterbatch item, or fix the unit on the item master.",
                );
            }

            if (! $item->is_active) {
                $validator->errors()->add(
                    'masterbatch_item_id',
                    "\"{$item->name}\" is inactive. Reactivate the item before setting a dosing the floor will be prefilled from.",
                );
            }
        });
    }
}
