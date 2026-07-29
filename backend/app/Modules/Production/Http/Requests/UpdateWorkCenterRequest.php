<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workCenter = $this->route('work_center');

        return [
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('work_centers', 'code')->ignore($workCenter)],
            'name' => ['sometimes', 'string', 'max:255'],
            'display_sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'capacity_hours_per_day' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            // Machine capabilities. Every one is nullable and null means
            // "no limit known" — the honest state for all ten machines
            // today, since the factory's master workbook leaves every
            // cavity field empty. A null limit never blocks; only a stated
            // one does.
            'capacity_class' => ['sometimes', 'nullable', 'string', 'max:32'],
            'min_cavities' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:512'],
            'max_cavities' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:512', 'gte:min_cavities'],
            'permitted_cavities' => ['sometimes', 'nullable', 'array'],
            'permitted_cavities.*' => ['integer', 'min:1', 'max:512'],
            'cycle_time_min' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'cycle_time_max' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'gte:cycle_time_min'],
            'default_shift_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],
            'confirmation_status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
