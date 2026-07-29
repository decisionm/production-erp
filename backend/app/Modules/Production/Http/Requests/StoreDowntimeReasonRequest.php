<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDowntimeReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('downtime_reason')?->id;

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('downtime_reasons', 'code')->ignore($id)],
            'category' => ['nullable', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:255'],
            'planning_type' => ['required', Rule::in(['planned', 'unplanned'])],
            'reduces_runtime' => ['boolean'],
            'requires_note' => ['boolean'],
            'selectable_at_start' => ['boolean'],
            'is_active' => ['boolean'],
            'confirmation_status' => ['nullable', 'string', 'max:32'],
        ];
    }
}
