<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Support\Lists\ListSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /inventory/material-lots — the register's filters, unchanged from the
 * controller's inline rules: material, receipt, a received-date window
 * (server-side, because the register is paginated) and the older
 * `order=newest|oldest` switch, kept for every caller that still sends it.
 *
 * `sort` (03-Sep-2026) orders on the lot's own columns — received date,
 * supplier lot, bags, received kg — and, when present, wins over `order`.
 */
class ListMaterialLotsRequest extends FormRequest
{
    public const SORTABLE = ['received_date', 'supplier_lot_no', 'bag_count', 'total_received_kg'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'grn_id' => ['nullable', 'integer', 'exists:goods_receipt_notes,id'],
            'received_from' => ['nullable', 'date'],
            'received_to' => ['nullable', 'date', 'after_or_equal:received_from'],
            'order' => ['nullable', 'in:newest,oldest'],
            'sort' => ListSort::rule(self::SORTABLE),
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function sort(): ?string
    {
        $value = $this->validated('sort');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
