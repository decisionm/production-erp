<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Models\Enums\AttendanceImportResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /hrms/attendance-imports/{id}/lines/{line} — the reviewer's answer
 * for one employee-day. Times are wall-clock `HH:MM`; they matter only for
 * present / half_day and default to the punches the report carried.
 */
class ResolveAttendanceImportLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(AttendanceImportResolution::class)],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
