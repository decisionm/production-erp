<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST/PUT /procurement/supplier-bills — the paper bill's figures, typed.
 * Field-shape validation only; the bill's own arithmetic (subtotal = Σ
 * amounts, total = subtotal + taxes + rounding) and the GRN-line matching
 * are the service's guards, where they can name the gap. Taxes and
 * rounding are TYPED from the paper, never computed (DEC-20260812-003:
 * no rate is ever seeded; Q39 open). `rounding` is the one signed figure —
 * paper bills round both ways.
 */
class StoreSupplierBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('vendor_id', $this->input('vendor_id'))],
            // Unique PER VENDOR (the schema enforces it too): the same
            // vendor's invoice number twice is the double-payment path. The
            // route's own bill is ignored so an update does not refuse
            // itself.
            'bill_number' => [
                'required', 'string', 'max:100',
                Rule::unique('supplier_bills', 'bill_number')
                    ->where('vendor_id', $this->input('vendor_id'))
                    ->ignore($this->route('supplier_bill')?->id),
            ],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'purchase_ledger_name' => ['nullable', 'string', 'max:255'],
            'subtotal' => ['required', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            'cgst' => ['sometimes', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            'sgst' => ['sometimes', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            'igst' => ['sometimes', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            // Signed, small: a rounding line larger than a rupee either way
            // is not rounding — it is a figure on the wrong row.
            'rounding' => ['sometimes', 'numeric', 'between:-0.99,0.99'],
            'total' => ['required', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.goods_receipt_note_line_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.rate' => ['required', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            'lines.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
        ];
    }
}
