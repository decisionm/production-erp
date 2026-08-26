<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /inventory/reservations/{reservation}/release — give a hold up, with
 * a reason.
 *
 * THE REASON IS REQUIRED for the same purpose it is on a cancelled material
 * request: the row keeps its history, and `released_reason` is the only
 * thing that later says whether the customer changed their mind, the order
 * was cancelled, or the store needed the pieces for somebody more urgent.
 * Giving stock back to the pool without saying why is a decision the next
 * shift cannot read.
 *
 * Releasing MOVES NO STOCK. The pieces stay exactly where they are; they
 * simply stop being spoken for.
 */
class ReleaseReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the hold is being given up — the next shift reads this.',
        ];
    }
}
