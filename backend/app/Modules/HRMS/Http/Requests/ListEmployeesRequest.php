<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Models\Enums\EmployeeStatus;
use App\Modules\HRMS\Services\HrmsListQuery;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /hrms/employees — the query string the employee list takes.
 *
 * Nothing is required: the bare request is exactly the list every earlier
 * caller got. `q` matches code, name, department or designation
 * (HrmsListQuery::whereEmployeeMatches); `status` is one of the three the
 * master knows. A value that could only be a mistake — an unknown status, a
 * page size outside the range — is a 422, never a silently-full list.
 *
 * `per_page` runs to 1000, not the list pages' 100, because this index is
 * also every employee PICKER's source (`listAllEmployees`) and has served
 * `?per_page=1000` since the picker fix; a 100 ceiling here would 422 seven
 * screens on load.
 *
 * `sort` is one of the columns the page shows (ListSort spelling: bare for
 * ascending, `-` for descending); absent is the list's order by name.
 */
class ListEmployeesRequest extends FormRequest
{
    /** The employees columns the list sorts on, besides id — each one a column the page shows. */
    public const SORTABLE = ['employee_code', 'name', 'designation', 'department', 'date_of_joining', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(EmployeeStatus::class)],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.HrmsListQuery::PER_PAGE_MAX_EMPLOYEES],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
