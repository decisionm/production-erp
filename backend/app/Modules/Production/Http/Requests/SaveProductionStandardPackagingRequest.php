<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add or edit one packaging variant of a product standard, Tally identity
 * included (DEC-20260810-003).
 *
 * The counts follow the standards page's own rule: a packing mode is stated
 * with BOTH of its counts or not at all — a half-stated mode is someone who
 * has not decided yet, and recording it would put a box count nobody stated
 * in front of the packing line (ProductionStandardPackaging::isComplete()).
 *
 * `item_id` is the variant's own Tally identity. Nullable on purpose, in
 * both directions: absent/null means "no identity of its own — use the
 * product's", which is the honest state for an identity nobody has answered
 * yet (the owner's words: "if you don't know the tray's correct Tally entry,
 * make it an option to edit"). Never inferred from the counts or the name.
 */
class SaveProductionStandardPackagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-group middleware ('module:production') already requires
        // production.manage for a non-GET, same as every write neighbour.
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in([
                ProductionStandardPackaging::MODE_POUCH,
                ProductionStandardPackaging::MODE_TRAY,
                ProductionStandardPackaging::MODE_DIRECT_BOX,
            ])],
            'nos_per_pouch' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pouches_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'nos_per_tray' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'trays_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'nos_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_default' => ['sometimes', 'boolean'],
            // Soft-deleted rows are excluded in the rule itself — a plain
            // `exists` counts them, and a retired bottle must not become a
            // variant's identity. Active-ness is checked below where the
            // refusal can name the item.
            'item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mode = (string) $this->input('mode');

            $inner = match ($mode) {
                ProductionStandardPackaging::MODE_POUCH => ['nos_per_pouch', 'pouches_per_box'],
                ProductionStandardPackaging::MODE_TRAY => ['nos_per_tray', 'trays_per_box'],
                default => [],
            };

            if ($inner !== []) {
                $filled = array_filter($inner, fn (string $field) => $this->filled($field));

                if (count($filled) === 1) {
                    $missing = array_values(array_diff($inner, $filled))[0];
                    $validator->errors()->add(
                        $missing,
                        'Fill both counts of the mode, or neither — one on its own is not a packing option.',
                    );
                }
            }

            if ($mode === ProductionStandardPackaging::MODE_DIRECT_BOX && ! $this->filled('nos_per_box')) {
                $validator->errors()->add('nos_per_box', 'A direct-box packing is its box count — state it.');
            }

            $item = $this->input('item_id') === null ? null : Item::query()->find($this->input('item_id'));

            if ($item !== null && ! $item->is_active) {
                $validator->errors()->add(
                    'item_id',
                    "\"{$item->name}\" is not an active item — production cannot post finished goods under it.",
                );
            }
        });
    }
}
