<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Models\Enums\AttendanceImportIssue;
use App\Modules\HRMS\Models\Enums\AttendanceImportResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /hrms/attendance-imports/{id}/lines/bulk-resolve — ONE answer for
 * ONE KIND of problem, across every day of the run still carrying it.
 *
 * Deliberately narrow: the caller names the issue kind and the answer, not
 * a free-form set of ids or a filter expression. A month has 589 days to
 * answer and 366 of them are the same question asked 366 times; that is
 * what this is for, and nothing wider. Days already answered are left
 * alone, so this can never overwrite a person's decision.
 */
class BulkResolveAttendanceImportLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'issue' => ['required', Rule::enum(AttendanceImportIssue::class)],
            'resolution' => ['required', Rule::enum(AttendanceImportResolution::class)],
            'check_in' => ['sometimes', 'nullable', 'date_format:H:i'],
            'check_out' => ['sometimes', 'nullable', 'date_format:H:i'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
