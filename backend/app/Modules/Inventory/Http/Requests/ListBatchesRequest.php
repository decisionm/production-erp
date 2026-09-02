<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /inventory/batches — `sort` on the batch number and its two nullable
 * dates (manufactured, expiry; undated rows last either way). Absent is
 * newest first.
 *
 * `item_id`, `search`, `code` and `per_page` keep their controller readers
 * (array refusal pinned by EveryListFilterRefusesAnArrayTest; the Stock
 * page's batch picker reads one item's batches at the 1000 ceiling).
 */
class ListBatchesRequest extends FormRequest
{
    public const SORTABLE = ['batch_number', 'manufactured_date', 'expiry_date'];

    public const NULLABLE_DATES = ['manufactured_date', 'expiry_date'];

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
