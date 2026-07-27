<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartBatchRequest extends FormRequest
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
            'operator_id' => ['nullable', 'integer', 'exists:employees,id'],
            // Run actuals, optional at start (may also be set at completion).
            // standard_cycle_time / standard_cavities are deliberately NOT
            // accepted here (or anywhere): they are snapshotted from the item
            // master by the service, and validated() strips any attempt to
            // send them.
            'actual_cycle_time' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:9999.99'],
            'active_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
