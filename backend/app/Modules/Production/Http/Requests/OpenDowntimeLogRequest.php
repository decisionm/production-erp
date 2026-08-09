<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenDowntimeLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->where('is_active', true)],
            'production_date' => ['nullable', 'date'],
            'nature_of_problem' => ['required', 'string', 'max:255'],
            // Optional — omit to stamp "now" (logging it live); provide to
            // backdate a breakdown someone's only now getting around to
            // reporting.
            'from_time' => ['nullable', 'date'],
        ];
    }
}
