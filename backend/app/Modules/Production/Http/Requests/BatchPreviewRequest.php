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
            // Bottles made so far. The completion drawer calls this same
            // preview (it is where the standard's packing modes come from),
            // so passing the live count is what lets the masterbatch dosing
            // come back as kg for THIS run rather than for the plan.
            // Absent on Start Batch, where nothing has been made yet.
            //
            // `numeric`, not `integer`, deliberately: this endpoint supplies
            // the completion drawer's packing modes, so a 422 here would
            // blank the whole drawer over a cosmetic figure. quantity_produced
            // is `numeric` on CompleteBatchRequest too, so "13333.0" is a
            // shape the floor can legitimately send. Truncated to a whole
            // count where it is read — a fractional bottle does not exist.
            'quantity_produced' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
