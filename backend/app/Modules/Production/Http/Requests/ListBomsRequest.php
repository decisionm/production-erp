<?php

namespace App\Modules\Production\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /production/boms — the BOM register's query string (03-Sep-2026).
 *
 * `item_id` is the filter the controller always read inline; `sort`,
 * `per_page` and `page` are the shared list contract (ListSort). An unknown
 * sort column is refused with 422 rather than silently ignored.
 */
class ListBomsRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    /** Besides id: the columns of `boms` the register can order on. */
    public const SORTABLE = ['name', 'version', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function itemId(): ?int
    {
        $value = $this->validated('item_id');

        return $value === null ? null : (int) $value;
    }

    public function sort(): ?string
    {
        return $this->validated('sort');
    }

    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? self::PER_PAGE_DEFAULT : (int) $perPage;
    }
}
