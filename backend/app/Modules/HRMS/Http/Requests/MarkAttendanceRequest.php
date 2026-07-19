<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'status' => ['required', Rule::in(['present', 'absent', 'half_day', 'on_leave'])],
            'check_in' => ['nullable', 'date'],
            'check_out' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
