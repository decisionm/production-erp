<?php

namespace App\Modules\Quality\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /quality/spc-characteristics — the register, sorted and paged on the
 * server. `item_id` is the filter the controller used to read inline and
 * keeps its meaning: one product's characteristics. Bare, the list answers
 * as it always has (by name, twenty to a page).
 */
class ListSpcCharacteristicsRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    /** The columns the register sorts on besides id. */
    public const SORTABLE = ['name', 'unit_of_measure', 'target_value'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** The product to narrow to, or null for every product. */
    public function itemId(): ?int
    {
        $itemId = $this->validated('item_id');

        return $itemId ? (int) $itemId : null;
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
