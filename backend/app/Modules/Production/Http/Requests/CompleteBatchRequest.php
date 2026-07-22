<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_number' => ['nullable', 'string', 'max:64'],
            'quantity_produced' => ['required', 'numeric', 'gt:0'],
            'quantity_scrap' => ['nullable', 'numeric', 'gte:0'],
            'scrap_reason_id' => ['nullable', 'integer', 'exists:scrap_reasons,id'],
            'nos_per_tray' => ['nullable', 'integer', 'min:0'],
            'no_of_trays' => ['nullable', 'integer', 'min:0'],
            'nos_per_box' => ['nullable', 'integer', 'min:0'],
            'no_of_box' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],

            'material_consumptions' => ['nullable', 'array'],
            'material_consumptions.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'material_consumptions.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'material_consumptions.*.quantity_issued_kg' => ['required', 'numeric', 'gt:0'],

            'scraps' => ['nullable', 'array'],
            'scraps.*.type' => ['required', Rule::in(['rejected_finished_good', 'lumps'])],
            'scraps.*.quantity_nos' => ['nullable', 'numeric', 'gte:0'],
            'scraps.*.quantity_kg' => ['nullable', 'numeric', 'gte:0'],
            'scraps.*.scrap_reason_id' => ['nullable', 'integer', 'exists:scrap_reasons,id'],
        ];
    }
}
