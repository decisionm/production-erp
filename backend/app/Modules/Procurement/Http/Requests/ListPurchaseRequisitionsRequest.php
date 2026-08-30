<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Http\Requests\Rules\EnumOrList;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Services\ProcurementDocumentQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /procurement/purchase-requisitions — the 28-Aug audit's finding 8:
 * the queue had no way in beyond scrolling.
 *
 * Standalone rather than extending ListProcurementDocumentsRequest, for one
 * honest reason: a requisition names no vendor, and the shared base
 * validates `vendor_id` — a key this list would accept and silently ignore,
 * which is exactly what that base's own docblock forbids. Everything else
 * follows the shared grammar verbatim: `status` one value or a list,
 * `q` as "PR-12" / "pr 12" / "12" or a requester or item name, dates on
 * needed_by_date, a 422 for a value that could only be a mistake.
 */
class ListPurchaseRequisitionsRequest extends FormRequest
{
    /** Besides id: what the queue may be ordered by. */
    private const SORTABLE = ['needed_by_date', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', new EnumOrList(PurchaseRequisitionStatus::class)],
            'status.*' => [Rule::enum(PurchaseRequisitionStatus::class)],
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'nullable', Rule::in($this->sortOptions())],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.ProcurementDocumentQuery::PER_PAGE_MAX],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return list<string> */
    private function sortOptions(): array
    {
        $options = ['id', '-id'];
        foreach (self::SORTABLE as $column) {
            $options[] = $column;
            $options[] = "-{$column}";
        }

        return $options;
    }
}
