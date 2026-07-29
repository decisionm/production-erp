<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkCenterCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every capability is nullable, and null means "no limit known" — which
     * is the honest state for all ten machines today. A null limit never
     * blocks anything; only a stated limit does.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'capacity_class' => ['sometimes', 'nullable', 'string', 'max:32'],
            'min_cavities' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:512'],
            'max_cavities' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:512', 'gte:min_cavities'],
            'permitted_cavities' => ['sometimes', 'nullable', 'array'],
            'permitted_cavities.*' => ['integer', 'min:1', 'max:512'],
            'cycle_time_min' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'cycle_time_max' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'gte:cycle_time_min'],
            'default_shift_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],
            'confirmation_status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
