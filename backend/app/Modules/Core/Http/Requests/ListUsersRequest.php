<?php

namespace App\Modules\Core\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /users — sort, page and page size. An empty query string is the
 * name-ordered first page every earlier caller still gets. Roles are a
 * relation and are not sortable here.
 */
class ListUsersRequest extends FormRequest
{
    public const SORTABLE = ['name', 'email', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
