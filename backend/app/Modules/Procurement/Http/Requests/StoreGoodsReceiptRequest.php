<?php

namespace App\Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // New clients send this for safe replay. Optional keeps an
            // already-open pre-deployment browser/API client able to receive
            // stock while the new frontend rolls out.
            'receipt_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'received_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => [
                'required',
                'integer',
                Rule::exists('purchase_order_lines', 'id')
                    ->where('purchase_order_id', $this->input('purchase_order_id')),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lots' => ['sometimes', 'array', 'min:1'],
            'lines.*.lots.*.supplier_lot_no' => ['nullable', 'string', 'max:100'],
            'lines.*.lots.*.bag_count' => ['required_with:lines.*.lots', 'integer', 'min:1', 'max:2000'],
            'lines.*.lots.*.bag_weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.lots.*.total_received_kg' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.lots.*.barcodes' => ['nullable', 'array'],
            'lines.*.lots.*.barcodes.*' => [
                'required',
                'string',
                'max:64',
                'distinct',
            ],
            'lines.*.lots.*.bag_weights' => ['nullable', 'array'],
            'lines.*.lots.*.bag_weights.*' => ['required', 'numeric', 'gt:0'],
            'lines.*.lots.*.notes' => ['nullable', 'string'],
        ];
    }
}
