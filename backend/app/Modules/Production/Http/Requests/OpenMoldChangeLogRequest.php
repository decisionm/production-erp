<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenMoldChangeLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            // Active-only despite backdating support — see the identical
            // note in OpenDowntimeLogRequest: shift retirement is
            // merge-and-repoint, so the window's active row takes late
            // paperwork and a retired twin never takes a new record.
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->where('is_active', true)],
            'production_date' => ['nullable', 'date'],
            'changed_from_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'changed_from_mold_id' => ['nullable', 'integer', 'exists:molds,id'],
            'changed_to_item_id' => ['required', 'integer', 'exists:items,id'],
            'changed_to_mold_id' => ['required', 'integer', 'exists:molds,id'],
            'from_time' => ['nullable', 'date', 'required_with:to_time'],
            // Given alongside from_time, the change is logged as already
            // complete in one step — see MoldChangeLogService::open().
            'to_time' => ['nullable', 'date', 'after:from_time'],
        ];
    }
}
