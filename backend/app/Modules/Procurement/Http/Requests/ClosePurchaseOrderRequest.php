<?php

namespace App\Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /procurement/purchase-orders/{po}/close (Phase 6, P6-01): a
 * short-close needs a reason — it is kept on the order (closed_reason) and
 * on the kind-'close' revision row, and it is what the next reader of the
 * order sees first. Whether the order MAY be closed (Sent |
 * PartiallyReceived, not a Tally mirror) is PurchaseOrderService::close()'s
 * call.
 */
class ClosePurchaseOrderRequest extends FormRequest
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
