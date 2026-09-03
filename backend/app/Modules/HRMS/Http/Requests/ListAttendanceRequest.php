<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Models\Enums\AttendanceStatus;
use App\Modules\HRMS\Services\HrmsListQuery;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /hrms/attendance — the query string the attendance list takes.
 *
 * Nothing is required. `q` searches THROUGH the employee — code, name,
 * department, designation (HrmsListQuery::whereEmployeeMatches); `status`
 * is one of the four marks; `employee_id` narrows to one person; `from` /
 * `to` is an inclusive range on the attendance DATE (a plain date, not the
 * check-in clock). A reversed range or a non-date is a 422, never a
 * silently-full list. `sort` is `date` or `status` (ListSort spelling);
 * absent is newest date first. The employee column shows a NAME through the
 * relation, so it is not offered here.
 */
class ListAttendanceRequest extends FormRequest
{
    public const SORTABLE = ['date', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(AttendanceStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.HrmsListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
