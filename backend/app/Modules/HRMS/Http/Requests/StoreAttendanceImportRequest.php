<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /hrms/attendance-imports — the punch report as the browser parsed
 * it (punchReport.ts). Plain rows only: no file reaches the server. Times
 * are wall-clock `HH:MM` (24-hour) or null; durations are whole minutes.
 */
class StoreAttendanceImportRequest extends FormRequest
{
    public const MAX_EMPLOYEES = 500;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'source' => ['required', Rule::in(['pooja'])],
            'file_name' => ['nullable', 'string', 'max:255'],
            'employees' => ['required', 'array', 'min:1', 'max:'.self::MAX_EMPLOYEES],
            'employees.*.employee_code' => ['required', 'string', 'max:32'],
            'employees.*.name' => ['required', 'string', 'max:255'],
            'employees.*.department' => ['nullable', 'string', 'max:255'],
            'employees.*.designation' => ['nullable', 'string', 'max:255'],
            'employees.*.days' => ['required', 'array', 'min:1', 'max:62'],
            'employees.*.days.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:period_to'],
            'employees.*.days.*.status' => ['required', 'string', 'max:32'],
            'employees.*.days.*.first_in' => ['nullable', 'date_format:H:i'],
            'employees.*.days.*.last_out' => ['nullable', 'date_format:H:i'],
            'employees.*.days.*.ot_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'employees.*.days.*.late_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'employees.*.days.*.early_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'employees.*.days.*.worked_minutes' => ['nullable', 'integer', 'between:0,1440'],
        ];
    }
}
