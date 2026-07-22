<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'production_date' => ['nullable', 'date'],
            'nature_of_problem' => ['required', 'string', 'max:255'],
        ];
    }
}
