<?php

namespace App\Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /procurement/purchase-orders/{po}/cancel (Phase 6, P6-01): a cancel
 * needs a reason — kept on the order (cancelled_reason). Whether the order
 * MAY be cancelled (Draft | Sent with zero receipts, not a Tally mirror) is
 * PurchaseOrderService::cancel()'s call.
 */
class CancelPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
