<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Rules\PlainDecimal;
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
            // WS-B: an arrival cannot be booked into a retired store.
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
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
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            // Arrival references (owner-confirmed): the Receipt Note reference
            // is recorded at physical arrival; both default deterministically
            // when blank so every arrival stays referenceable.
            'receipt_note_reference' => ['nullable', 'string', 'max:64'],
            'tracking_number' => ['nullable', 'string', 'max:64'],
            // Edited allocation preview — omitted means oldest-due-first.
            'lines.*.schedule_allocations' => ['sometimes', 'array', 'min:1'],
            'lines.*.schedule_allocations.*.purchase_order_schedule_id' => ['required_with:lines.*.schedule_allocations', 'integer', 'exists:purchase_order_schedules,id'],
            'lines.*.schedule_allocations.*.quantity' => ['required_with:lines.*.schedule_allocations', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.lots' => ['sometimes', 'array', 'min:1'],
            'lines.*.lots.*.supplier_lot_no' => ['nullable', 'string', 'max:100'],
            'lines.*.lots.*.bag_count' => ['required_with:lines.*.lots', 'integer', 'min:1', 'max:2000'],
            'lines.*.lots.*.bag_weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:99999999', new PlainDecimal],
            'lines.*.lots.*.total_received_kg' => ['nullable', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.lots.*.barcodes' => ['nullable', 'array'],
            'lines.*.lots.*.barcodes.*' => [
                'required',
                'string',
                'max:64',
                'distinct',
            ],
            'lines.*.lots.*.bag_weights' => ['nullable', 'array'],
            'lines.*.lots.*.bag_weights.*' => ['required', 'numeric', 'gt:0', 'max:99999999', new PlainDecimal],
            'lines.*.lots.*.notes' => ['nullable', 'string'],
        ];
    }
}
