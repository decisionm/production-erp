<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * THE `source: tally` BYPASS OF THE RETIRED-VENDOR RULE — READ BEFORE EDITING.
 *
 * WS-B (audit 17-Aug-2026 §1) refuses a RETIRED vendor on a new purchase
 * order. That refusal is scoped to ERP-entered orders: when `source` is
 * `tally` the rule falls back to a bare existence check, because a mirror is
 * a read-only reflection of an order Tally already holds (Tally is the PO
 * source of truth — PurchaseOrderService's class docblock) and the ERP does
 * not argue with the book it reflects.
 *
 * The mechanical fact that makes this a hole and not merely a scope: `source`
 * arrives in the CLIENT'S REQUEST BODY (`'source' => ['sometimes',
 * 'in:erp,tally']` below) and NOTHING verifies that a matching order exists
 * in Tally. So any caller who may create a purchase order at all (the route
 * is behind `auth:sanctum` and the procurement write permission — that is the
 * only gate) can opt out of the retired-vendor rule by sending
 * `source: tally`. There is no trust check on the claim itself.
 *
 * This is DELIBERATELY LEFT AS IT IS, not overlooked. Whether a retired
 * vendor should also block a mirror is PENDING-OWNER-QUESTIONS Q53(c) — an
 * owner question, recorded there, unanswered. Until it is answered the rule
 * does not change.
 *
 * Today's behaviour is pinned by
 * tests/Feature/Procurement/TallyMirrorRetiredVendorBypassTest.php, so an
 * answer to Q53(c) turns that test red and is applied deliberately rather
 * than arriving as drift.
 */
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
            // SCOPED TO ERP-ENTERED ORDERS ON PURPOSE — and `source` is a
            // client-supplied body field with no trust check, so that scope
            // is also a BYPASS anyone can take. See the class docblock and
            // PENDING-OWNER-QUESTIONS Q53(c); do not narrow or widen this
            // branch before that question is answered.
            'vendor_id' => $this->input('source') === 'tally'
                ? ['required', 'integer', 'exists:vendors,id']
                : ['required', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'purchase_requisition_id' => ['nullable', 'integer', 'exists:purchase_requisitions,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            // A Tally-mirror order: Tally is the PO/schedule source of truth,
            // this row is its read-only reflection with the exact identities.
            'source' => ['sometimes', 'in:erp,tally'],
            'tally_order_no' => ['nullable', 'string', 'max:64', 'required_if:source,tally'],
            'lines.*.schedules' => ['sometimes', 'array'],
            'lines.*.schedules.*.due_date' => ['required_with:lines.*.schedules', 'date'],
            'lines.*.schedules.*.quantity' => ['required_with:lines.*.schedules', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.schedules.*.tally_reference' => ['nullable', 'string', 'max:64'],
        ];
    }
}
