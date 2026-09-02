<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Services\HrmsListQuery;
use Illuminate\Foundation\Http\FormRequest;

/** GET /hrms/attendance-imports — the runs, newest first, paged. */
class ListAttendanceImportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.HrmsListQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
