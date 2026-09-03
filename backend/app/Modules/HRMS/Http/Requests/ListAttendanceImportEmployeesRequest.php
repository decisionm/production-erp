<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Services\HrmsListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /hrms/attendance-imports/{id}/employees — the review's PERSON grain.
 * `q` is the employee code or the name as the report printed it. Paged on
 * the server like every other list, because a month can carry hundreds of
 * people and the screen must never quietly show the first twenty.
 */
class ListAttendanceImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.HrmsListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
