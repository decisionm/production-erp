<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Services\PayrollListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /payroll/payslips — the run (the filter the page has always sent),
 * the employee, free text over the employee's name or code, page size.
 * Nothing is required; an empty query string is the list every earlier
 * caller got. A malformed value is a 422, never a silently-empty list.
 */
class ListPayslipsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payroll_run_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.PayrollListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
