<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Services\SalesDocumentQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string every Sales list shares (Phase 3.5): customer, item,
 * date range, free text, sort, page size. Each document's request adds its
 * own keys (status, sales_order_id) and names its own sortable columns.
 *
 * Nothing is required. A value that could only be a mistake — a status
 * that does not exist, a non-date, a reversed range, a page size outside
 * 1..100, a sort column this document does not have — is refused with a
 * 422 rather than silently matching everything or nothing. A key nobody
 * documented is simply not validated and so not read: an old tab's stale
 * query string still loads.
 *
 * `per_page` IS validated here (unlike ListTallySyncEntriesRequest, which
 * clamps): the contract for these lists is 1..100 or 422.
 */
abstract class ListSalesDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The bare column names this document may sort on, besides id. */
    abstract protected function sortableColumns(): array;

    /** The document's own rules, merged over the shared ones. */
    protected function documentRules(): array
    {
        return [];
    }

    public function rules(): array
    {
        return [
            // 'nullable' throughout: an empty `?q=` (null after the
            // empty-string middleware) is "no filter", not a malformed one.
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'nullable', Rule::in($this->sortOptions())],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.SalesDocumentQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...$this->documentRules(),
        ];
    }

    /** id / -id plus each sortable column bare and "-" prefixed. */
    private function sortOptions(): array
    {
        $options = ['id', '-id'];
        foreach ($this->sortableColumns() as $column) {
            $options[] = $column;
            $options[] = "-{$column}";
        }

        return $options;
    }
}
