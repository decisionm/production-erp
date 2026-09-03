<?php

namespace App\Modules\Finance\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /finance/gl-accounts — sort, page and page size. Nothing is required:
 * an empty query string is the code-ordered first page every earlier caller
 * still gets. A sort column the account has no such column for is refused
 * with 422 rather than silently ignored.
 *
 * `per_page` stays bounded at 1..1000, not 1..100: the journal-entry line
 * picker reads the whole master at the ceiling (listAllGLAccounts,
 * PickerListsAreCompleteTest) and a tighter bound would truncate it.
 */
class ListGlAccountsRequest extends FormRequest
{
    public const SORTABLE = ['code', 'name', 'type', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
