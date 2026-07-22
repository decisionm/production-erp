<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftSummaryRequest extends FormRequest
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
            'supervisor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'target_production_kg' => ['nullable', 'numeric', 'gte:0'],
            'power_consumption_units' => ['nullable', 'numeric', 'gte:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
