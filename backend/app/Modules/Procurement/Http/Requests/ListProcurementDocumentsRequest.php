<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Services\ProcurementDocumentQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string the two Procurement lists share (Phase 4.5, mirroring
 * Sales' ListSalesDocumentsRequest — the SAME grammar, so "PO-12", "po 12"
 * and "12" mean the same thing here as "SO-12" does there, and a date range
 * lands the same way): vendor, item, date range, free text, sort, page
 * size. Each document's request adds its own keys (status,
 * purchase_order_id) and names its own sortable columns.
 *
 * Nothing is required — an empty query string is the unfiltered list every
 * earlier caller still gets. A value that could only be a mistake — a
 * status that does not exist, a non-date, a reversed range, a page size
 * outside the range, a sort column this document does not have — is
 * refused with a 422 rather than silently matching everything or nothing.
 * A key nobody documented is simply not validated and so not read.
 *
 * `per_page` is bounded at 1..1000, NOT Sales' 1..100: these two lists
 * have served up to 1000 since the `?po=` / `?grn=` deep links needed to
 * find one older document past the first page (Controller::perPage), and
 * the frontend relies on it. Tightening it here would break those links.
 */
abstract class ListProcurementDocumentsRequest extends FormRequest
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
            'vendor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'nullable', Rule::in($this->sortOptions())],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.ProcurementDocumentQuery::PER_PAGE_MAX],
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
