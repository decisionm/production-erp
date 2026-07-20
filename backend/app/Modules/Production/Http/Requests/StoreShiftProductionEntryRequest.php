<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftProductionEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'production_date' => ['nullable', 'date'],
            'quantity_produced' => ['required', 'numeric', 'gt:0'],
            'quantity_scrap' => ['nullable', 'numeric', 'gte:0'],
            'scrap_reason_id' => ['nullable', 'integer', 'exists:scrap_reasons,id'],
            'operator_id' => ['nullable', 'integer', 'exists:employees,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
