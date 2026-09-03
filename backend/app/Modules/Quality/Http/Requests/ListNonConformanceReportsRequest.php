<?php

namespace App\Modules\Quality\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /quality/ncrs — the register, sorted and paged on the server. Bare,
 * it answers as it always has (newest first, twenty to a page); a sort on
 * a column the list does not have, or a page size outside 1..100, is a
 * 422. The controller used to take no request at all, so the screen could
 * only ever draw the first twenty rows.
 */
class ListNonConformanceReportsRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    /** The columns the register sorts on besides id. */
    public const SORTABLE = ['severity', 'status', 'raised_date'];

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

    /** The validated sort, or null for the list's default order. */
    public function sort(): ?string
    {
        return $this->validated('sort');
    }

    /** 1..PER_PAGE_MAX, PER_PAGE_DEFAULT when not asked. */
    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? self::PER_PAGE_DEFAULT : (int) $perPage;
    }
}
