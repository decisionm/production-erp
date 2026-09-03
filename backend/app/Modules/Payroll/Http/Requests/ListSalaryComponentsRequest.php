<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /payroll/salary-components — sort and page size. Nothing is required;
 * the bare request is the list every earlier caller got, by name.
 *
 * `per_page` runs to 1000, not the list pages' 100, because this index is
 * also the component PICKER's source (`listAllSalaryComponents`,
 * `?per_page=1000` since the 12-Aug picker fix); a 100 ceiling here would
 * 422 the salary structure screen on load. `sort` is one of the master's
 * own columns (ListSort spelling: bare ascending, `-` descending).
 */
class ListSalaryComponentsRequest extends FormRequest
{
    public const SORTABLE = ['code', 'name', 'type', 'is_active'];

    public const PER_PAGE_MAX = 1000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
