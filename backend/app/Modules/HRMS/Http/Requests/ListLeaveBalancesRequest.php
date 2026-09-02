<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Services\HrmsListQuery;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /hrms/leave-balances — sort and page size. Nothing is required; the
 * bare request is the list every earlier caller got, newest year first.
 *
 * `sort` is one of the balance's own stored figures (ListSort spelling).
 * `remaining_days` is computed in the resource, not stored, and the employee
 * and leave type show a NAME through a relation — none of those is offered.
 */
class ListLeaveBalancesRequest extends FormRequest
{
    public const SORTABLE = ['year', 'allocated_days', 'used_days'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.HrmsListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
