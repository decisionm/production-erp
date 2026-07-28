<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CountDayBinRequest extends FormRequest
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
            // Weighed on a scale or estimated — Vincent Q2 stays the
            // floor's choice; the app records whatever figure arrives.
            'quantity_kg' => ['required', 'numeric', 'gte:0'],
            'shift_production_entry_id' => ['nullable', 'integer', 'exists:shift_production_entries,id'],
        ];
    }
}
