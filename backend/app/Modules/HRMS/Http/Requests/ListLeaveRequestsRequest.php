<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Models\Enums\LeaveRequestStatus;
use App\Modules\HRMS\Services\HrmsListQuery;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /hrms/leave-requests — the query string the leave list takes.
 *
 * Nothing is required. `q` searches THROUGH the employee — code, name,
 * department, designation (HrmsListQuery::whereEmployeeMatches) — because a
 * leave request has no number of its own that anyone types; `status` is one
 * of pending / approved / rejected; `employee_id` narrows to one person.
 * A malformed value is a 422, never a silently-full list. `sort` is one of
 * the request's own dated and counted columns (ListSort spelling); absent is
 * newest first. Employee and leave type show a NAME through a relation and
 * are not offered.
 */
class ListLeaveRequestsRequest extends FormRequest
{
    public const SORTABLE = ['start_date', 'end_date', 'days', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(LeaveRequestStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.HrmsListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
