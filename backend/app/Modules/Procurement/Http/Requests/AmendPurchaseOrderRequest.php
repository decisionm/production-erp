<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Http\Requests\Rules\PurchasableItem;
use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /procurement/purchase-orders/{po}/amend (Phase 6, P6-01): the
 * replacement lines and schedules for a DRAFT, plus an optional reason kept
 * on the revision row. The line rules are StorePurchaseOrderRequest's —
 * the same shape create() takes — so an amended draft can never carry a
 * line the original could not. Whether the order MAY be amended (Draft,
 * not a Tally mirror) is PurchaseOrderService::amend()'s call, not this
 * request's: validation shapes the input; the state machine judges the
 * order.
 */
class AmendPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            // The item half of "an amended draft can never carry a line the
            // original could not" (class docblock). It was the half that was
            // missing: create() refuses an item out of service, and until now
            // amend() would take one. Unconditional here — PurchaseOrderService
            // ::amend refuses a Tally mirror outright, so this rule has no
            // mirror scope to widen. That is pinned by
            // InactiveMasterGuardTest, not merely asserted here.
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id', new PurchasableItem],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            'lines.*.schedules' => ['sometimes', 'array'],
            'lines.*.schedules.*.due_date' => ['required_with:lines.*.schedules', 'date'],
            'lines.*.schedules.*.quantity' => ['required_with:lines.*.schedules', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.schedules.*.tally_reference' => ['nullable', 'string', 'max:64'],
        ];
    }
}
