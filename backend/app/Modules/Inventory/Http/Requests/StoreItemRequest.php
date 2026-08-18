<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64', 'unique:items,sku'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'uom' => ['required', 'string', 'max:16'],
            'hsn_sac_code' => ['nullable', 'string', 'max:20'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'nominal_weight_grams' => ['nullable', 'numeric', 'gt:0'],
            'nos_per_tray' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'trays_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'nos_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'nos_per_pouch' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pouches_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:32'],
            'standard_cycle_time' => ['sometimes', 'nullable', 'numeric', 'min:0.1'],
            'standard_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tracking_type' => ['nullable', Rule::in(['none', 'batch', 'serial'])],
            'is_active' => ['boolean'],
            // The owner's switch for "the floor may request this". Nothing
            // infers it from a name, an SKU or a unit of measure.
            'is_production_input' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Item::isLocalFixture() treats a "LOCAL-" SKU as a fixture on the
            // prefix alone, so a real item created with one would never post
            // to Tally. This endpoint cannot flag a fixture (is_local_fixture
            // is not accepted here — the product-master importer fabricates
            // those), so any item it creates is a real one: refuse the prefix.
            if (str_starts_with((string) $this->input('sku'), Item::LOCAL_FIXTURE_SKU_PREFIX)) {
                $validator->errors()->add(
                    'sku',
                    'A SKU beginning "'.Item::LOCAL_FIXTURE_SKU_PREFIX.'" marks a local fixture that never posts to Tally — a real item cannot carry that prefix.',
                );
            }
        });
    }
}
