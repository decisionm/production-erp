<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only item_id is required: the preview is called as the supervisor
     * builds the form, so it must answer usefully from a half-filled one
     * (product chosen, machine not yet). Each optional field simply adds
     * the checks and figures it makes possible.
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'work_center_id' => ['sometimes', 'nullable', 'integer', 'exists:work_centers,id'],
            'warehouse_id' => ['sometimes', 'nullable', 'integer', 'exists:warehouses,id'],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'exists:shifts,id'],
            'planned_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:24'],
            'active_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'production_standard_id' => ['sometimes', 'nullable', 'integer', 'exists:production_standards,id'],
            'production_standard_packaging_id' => ['sometimes', 'nullable', 'integer', 'exists:production_standard_packagings,id'],
        ];
    }
}
