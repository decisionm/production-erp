<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Models\Enums\LeaveRequestStatus;
use App\Modules\HRMS\Services\HrmsListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /hrms/leave-requests — the query string the leave list takes.
 *
 * Nothing is required. `q` searches THROUGH the employee — code, name,
 * department, designation (HrmsListQuery::whereEmployeeMatches) — because a
 * leave request has no number of its own that anyone types; `status` is one
 * of pending / approved / rejected; `employee_id` narrows to one person.
 * A malformed value is a 422, never a silently-full list.
 */
class ListLeaveRequestsRequest extends FormRequest
{
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
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.HrmsListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
