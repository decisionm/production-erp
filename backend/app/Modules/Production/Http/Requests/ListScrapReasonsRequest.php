<?php

namespace App\Modules\Production\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /production/scrap-reasons — `active` (the inline filter the controller
 * always read) plus the shared sort / page contract (ListSort).
 *
 * `per_page` keeps the ceiling the base controller always allowed (1000):
 * the reason pickers (listAllScrapReasons) read the whole master in one page.
 */
class ListScrapReasonsRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 1000;

    /** Besides id: the columns of `scrap_reasons` the master can order on. */
    public const SORTABLE = ['code', 'name', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'active' => ['sometimes', 'nullable', Rule::in(['1', '0', 'true', 'false'])],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
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
