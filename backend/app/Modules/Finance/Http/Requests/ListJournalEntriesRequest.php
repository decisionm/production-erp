<?php

namespace App\Modules\Finance\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /finance/journal-entries — sort, page and page size. An empty query
 * string is the newest-first first page every earlier caller still gets.
 */
class ListJournalEntriesRequest extends FormRequest
{
    public const SORTABLE = ['status', 'entry_date', 'reference'];

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
