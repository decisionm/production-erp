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

            // Configurable-production fields. mold/colour narrow which
            // approved configuration governs the run; the *_override pair
            // is the bounded, reasoned deviation from it.
            'mold_id' => ['sometimes', 'nullable', 'integer', 'exists:molds,id'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cycle_time_override' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:9999.99'],
            'cavities_override' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'scheduled_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],

            // Planned downtime known before the run — lowers the adjusted
            // target at Start rather than explaining the gap afterwards.
            'planned_downtime' => ['sometimes', 'array'],
            'planned_downtime.*.downtime_reason_id' => ['required', 'integer', 'exists:downtime_reasons,id'],
            'planned_downtime.*.minutes' => ['required', 'numeric', 'gt:0', 'max:1440'],
            'planned_downtime.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
