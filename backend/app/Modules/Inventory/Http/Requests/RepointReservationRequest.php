<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /inventory/reservations/{reservation}/repoint — move a hold, or part
 * of one, to another order line.
 *
 * The target line has to EXIST (a 422 naming the field, not a 500 deep in
 * the service). Everything else the move depends on — that the target's
 * order is live, that it asks for the same item, that the hold is still
 * holding this much, and that the pieces in flight are counted as free —
 * is recomputed by StockReservationService inside ONE transaction under ONE
 * balance lock (S4). None of it can be settled here: the target line's
 * demand and the item's free stock both move between this validation and
 * the write.
 *
 * A REASON IS REQUIRED, as it is on a plain release: the source hold records
 * this move as its most recent give-up, and "moved to SO-14" is the only
 * thing that later explains why a customer's hold shrank.
 */
class RepointReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_order_line_id' => ['required', 'integer', Rule::exists('sales_order_lines', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'sales_order_line_id.exists' => 'That order line does not exist — reload the queue and pick again.',
            'reason.required' => 'Say why the hold is moving — the next shift reads this.',
        ];
    }
}
