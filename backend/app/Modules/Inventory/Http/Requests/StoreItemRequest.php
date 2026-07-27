<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }
}
