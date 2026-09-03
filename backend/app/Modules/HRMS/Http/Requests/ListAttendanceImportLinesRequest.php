<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Models\Enums\AttendanceImportIssue;
use App\Modules\HRMS\Services\HrmsListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /hrms/attendance-imports/{id}/lines — the review list's query
 * string. `q` is the employee code or name as the report printed them;
 * `issue` is one of the review chips: `open` (every unanswered issue),
 * one issue kind (unanswered lines of that kind), `resolved`, `clean`.
 */
class ListAttendanceImportLinesRequest extends FormRequest
{
    public const ISSUE_FILTERS = ['open', 'resolved', 'clean', 'report_changed'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kinds = array_map(fn (AttendanceImportIssue $issue) => $issue->value, AttendanceImportIssue::cases());

        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            // The person panel asks for ONE employee's days. `q` is a LIKE
            // and would also match a longer code that starts the same way;
            // this is the exact match that panel needs.
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'issue' => ['sometimes', 'nullable', Rule::in([...self::ISSUE_FILTERS, ...$kinds])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.HrmsListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
