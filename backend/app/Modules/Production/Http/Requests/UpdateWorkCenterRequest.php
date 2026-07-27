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
        ];
    }
}
