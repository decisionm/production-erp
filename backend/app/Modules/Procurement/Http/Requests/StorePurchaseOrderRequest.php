<?php

namespace App\Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // WS-B (audit 17-Aug-2026 §1): a vendor the factory has retired
            // can no longer be given NEW work.
            //
            // SCOPED TO ERP-ENTERED ORDERS ON PURPOSE. A `source: tally` row
            // is a read-only MIRROR of an order that already exists in Tally,
            // which is the PO source of truth (PurchaseOrderService's class
            // docblock). Refusing to mirror an order Tally already holds
            // would make the ERP argue with the book it only reflects, so the
            // mirror keeps the bare existence check. Whether a retired vendor
            // should also block the mirror is an owner question, not ours.
            'vendor_id' => $this->input('source') === 'tally'
                ? ['required', 'integer', 'exists:vendors,id']
                : ['required', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'purchase_requisition_id' => ['nullable', 'integer', 'exists:purchase_requisitions,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            // A Tally-mirror order: Tally is the PO/schedule source of truth,
            // this row is its read-only reflection with the exact identities.
            'source' => ['sometimes', 'in:erp,tally'],
            'tally_order_no' => ['nullable', 'string', 'max:64', 'required_if:source,tally'],
            'lines.*.schedules' => ['sometimes', 'array'],
            'lines.*.schedules.*.due_date' => ['required_with:lines.*.schedules', 'date'],
            'lines.*.schedules.*.quantity' => ['required_with:lines.*.schedules', 'numeric', 'gt:0'],
            'lines.*.schedules.*.tally_reference' => ['nullable', 'string', 'max:64'],
        ];
    }
}
