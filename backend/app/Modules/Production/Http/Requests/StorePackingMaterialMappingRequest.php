<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\PackingMaterialMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Answering one of the packing questions the seed left open — which item a
 * spec means, which cartons take Green tape, what a film piece weighs.
 *
 * Every message names the fix, not just the fault: the person typing this is
 * setting a figure that multiplies into a Tally consumption quantity, and a
 * bare "invalid" tells them nothing.
 */
class StorePackingMaterialMappingRequest extends FormRequest
{
    /**
     * Typo guards, not factory rules. A film piece heavier than a kilogram or
     * a box taking a hundred metres of tape is not a figure anyone meant to
     * type; both limits are deliberately generous, because refusing a real
     * figure would be worse than accepting an odd one.
     */
    private const MAX_GRAMS_PER_PIECE = 1000;

    private const MAX_METRES_PER_BOX = 100;

    public function authorize(): bool
    {
        // Route-group middleware ('module:production') already requires
        // production.manage for a non-GET, same as every write neighbour.
        return true;
    }

    public function rules(): array
    {
        return [
            'spec_kind' => ['required', 'string', Rule::in(PackingMaterialMapping::KINDS)],
            // The workbook string, as the sheet spells it. Not normalised
            // here: the lookup folds case and spacing, and rewriting what
            // somebody typed would leave the table unreadable against the
            // sheet it came from.
            'spec_value' => ['required', 'string', 'max:120'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            // decimal:0,4 refuses precision the decimal(12,4) column would
            // silently round away — 120.00001 must be questioned, not stored
            // as 120.0000 behind the typist's back.
            'grams_per_piece' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:'.self::MAX_GRAMS_PER_PIECE, 'decimal:0,4'],
            'metres_per_box' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:'.self::MAX_METRES_PER_BOX, 'decimal:0,4'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'grams_per_piece.gt' => 'Grams per piece must be more than zero. To stop prefilling this film, remove the mapping instead — a zero would tell the floor a carton takes no film.',
            'grams_per_piece.max' => 'Grams per piece over '.self::MAX_GRAMS_PER_PIECE.' looks like a units slip — this figure is the weight of ONE film piece in grams, not the roll in kg.',
            'metres_per_box.gt' => 'Metres per box must be more than zero. To stop prefilling tape for this carton, remove the mapping instead.',
            'metres_per_box.max' => 'Metres per box over '.self::MAX_METRES_PER_BOX.' looks like a units slip — the owner\'s figures run from 2.226 m to 5.160 m per box.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $kind = (string) $this->input('spec_kind');

            // The dose columns belong to ONE kind each. A carton row carrying
            // a metres figure would suggest tape as if it were cardboard the
            // moment somebody widened a query, so the wrong pairing is
            // refused at the door rather than nulled silently.
            if ($kind !== PackingMaterialMapping::KIND_POUCH_FILM && $this->filled('grams_per_piece')) {
                $validator->errors()->add(
                    'grams_per_piece',
                    'Grams per piece belongs to a pouch_film mapping only — it is the weight of one film piece. '
                    ."Leave it out for a {$kind} mapping.",
                );
            }

            if ($kind !== PackingMaterialMapping::KIND_TAPE && $this->filled('metres_per_box')) {
                $validator->errors()->add(
                    'metres_per_box',
                    'Metres per box belongs to a tape mapping only — it is how much tape seals one box. '
                    ."Leave it out for a {$kind} mapping.",
                );
            }

            $item = $this->input('item_id') === null ? null : Item::query()->find($this->input('item_id'));

            if ($item === null) {
                return;
            }

            // Deliberately NO uom check, unlike the masterbatch dosing
            // request. That one refuses a non-kg item because masterbatch is
            // weighed; packing items are counted in Nos, film is weighed in
            // Kgs, and this factory's item master reads "NOS" even for items
            // Tally moves in Kgs. A uom rule here would refuse the film
            // mappings outright on the live database.
            if (! $item->is_active) {
                $validator->errors()->add(
                    'item_id',
                    "\"{$item->name}\" is inactive. Reactivate the item before mapping a spec the floor will be "
                    .'prefilled from.',
                );
            }
        });
    }
}
