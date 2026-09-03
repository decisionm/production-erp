<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /maintenance/assets — sort, page and page size (03-Sep-2026).
 *
 * `per_page` has no ceiling HERE: the base Controller::perPage clamp (1000)
 * stays, because the asset PICKER reads this list at 1000 and an oversize
 * ask is answered with the clamped page, never a 422
 * (PickerListsAreCompleteTest). An unknown sort column is refused.
 */
class ListAssetsRequest extends FormRequest
{
    /** The columns the list may sort on, besides id — each one a real column of `assets`. */
    public const SORTABLE = ['code', 'name', 'category', 'location', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
