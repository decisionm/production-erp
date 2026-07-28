<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnDayBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity_kg' => ['required', 'numeric', 'gt:0'],
            // Optional: named bag = kg flows back into it; absent = ledger
            // row only (regrind/return destination is Vincent Q4, open).
            'material_bag_id' => ['nullable', 'integer', 'exists:material_bags,id'],
            'shift_production_entry_id' => ['nullable', 'integer', 'exists:shift_production_entries,id'],
        ];
    }
}
