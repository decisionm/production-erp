<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /inventory/warehouses — `sort` on code, name or status (is_active),
 * in the ListSort spelling; absent is name order, as the list always read.
 *
 * `search` and `per_page` are deliberately NOT validated here: they keep
 * their readers on the controller (Controller::searchTerm refuses an array
 * with 422, pinned by EveryListFilterRefusesAnArrayTest; Controller::perPage
 * lets the pickers read the whole list at 1000).
 */
class ListWarehousesRequest extends FormRequest
{
    public const SORTABLE = ['code', 'name', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
        ];
    }

    public function sort(): ?string
    {
        $value = $this->validated('sort');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
