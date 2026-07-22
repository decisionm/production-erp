<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'production_date' => ['nullable', 'date'],
            'location_label' => ['required', 'string', 'max:64'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity_kg' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
