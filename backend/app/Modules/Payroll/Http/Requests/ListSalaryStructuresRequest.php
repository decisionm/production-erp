<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Services\PayrollListQuery;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /payroll/salary-structures — the employee (the filter this index has
 * always taken), sort and page size. Nothing is required; the bare request
 * is the list every earlier caller got, latest effective date first.
 *
 * `sort` is `effective_from` (ListSort spelling): the structure's own dated
 * column. The employee column shows a NAME through the relation, and the
 * component and gross columns are composed from the lines — none is offered.
 */
class ListSalaryStructuresRequest extends FormRequest
{
    public const SORTABLE = ['effective_from'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.PayrollListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
