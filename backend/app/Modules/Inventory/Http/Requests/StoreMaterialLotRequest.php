<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bagCount = (int) $this->input('bag_count', 0);

        return [
            'grn_id' => ['nullable', 'integer', 'exists:goods_receipt_notes,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'supplier_lot_no' => ['nullable', 'string', 'max:100'],
            'received_date' => ['required', 'date'],
            'bag_count' => ['required', 'integer', 'min:1', 'max:2000'],
            'bag_weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'total_received_kg' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            // Supplier barcodes (Vincent Q1): when the bags carry scannable
            // codes, one per bag; omitted = app-generated LOT{lot}-B{seq}.
            // distinct + unique give the friendly 422 — the material_bags
            // UNIQUE column is the hard guard underneath either way.
            'barcodes' => ['nullable', 'array', 'size:'.$bagCount],
            'barcodes.*' => ['required', 'string', 'max:64', 'distinct', Rule::unique('material_bags', 'barcode')],
            // Individually weighed bags; omitted = nominal bag_weight_kg,
            // else total/count.
            'bag_weights' => ['nullable', 'array', 'size:'.$bagCount],
            'bag_weights.*' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
