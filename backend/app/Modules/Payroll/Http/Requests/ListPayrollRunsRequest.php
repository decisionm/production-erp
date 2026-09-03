<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Models\Enums\PayrollRunStatus;
use App\Modules\Payroll\Services\PayrollListQuery;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /payroll/runs — free text, status, page size. Nothing is required: an
 * empty query string is the unfiltered, newest-period-first list every
 * earlier caller got. A value that could only be a mistake — a status that
 * does not exist, a page size outside 1..100 — is refused with a 422 rather
 * than silently matching everything or nothing.
 *
 * `q` names a PERIOD (a run has no name of its own): "aug", "August 2026",
 * "2026-08", "08/2026", "2026" — or a status word. PayrollRunService reads it.
 *
 * `sort` (ListSort spelling) is `period` — the run's identity, year and
 * month together, the two real columns the Period column prints — or the
 * status, or one of the two nullable stamps, whose empties sort last.
 * Absent is newest period first, as it always was.
 */
class ListPayrollRunsRequest extends FormRequest
{
    public const SORTABLE = ['period', 'status', 'processed_at', 'paid_at'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'nullable' throughout: an empty `?q=` (null after the
            // empty-string middleware) is "no filter", not a malformed one.
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(PayrollRunStatus::class)],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.PayrollListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
