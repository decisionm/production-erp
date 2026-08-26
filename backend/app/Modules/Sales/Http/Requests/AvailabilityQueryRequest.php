<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /sales/availability?item_ids[]= — what the sales desk may promise, per
 * item, right now.
 *
 * `item_ids` is REQUIRED and capped. The desk asks about the lines it is
 * typing, never about the whole item master: an unbounded read would answer
 * a question nobody asked and grow with the master until the create modal
 * paused on every keystroke.
 *
 * An id that does not exist is NOT an error — it comes back with four zeroes,
 * exactly as an item the factory holds none of does. The availability read
 * answers "how many can I promise", and for a product that is not there the
 * honest answer is none, not a 422 in the middle of typing an order.
 */
class AvailabilityQueryRequest extends FormRequest
{
    /** One order's lines, generously — never the item master. */
    public const MAX_ITEMS = 200;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'item_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_ids.required' => 'Name the items to check — availability is asked per product, never for the whole master.',
        ];
    }
}
