<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /inventory/fulfilment/lines/{line}/send-to-production — put this
 * line's shortfall on the floor's worklist.
 *
 * The quantity is what the store TYPED, and it is deliberately not checked
 * against the shortfall here: S14 says the request is CAPPED at what the
 * line is genuinely short of rather than refused, and the shortfall moves
 * every time somebody reserves or delivers. ProductionRequestService caps it
 * inside its transaction under the line's lock — so a store typing a round
 * 500 against a shortfall of 480 gets 480 on the worklist, not a 422 they
 * would only retype.
 */
class SendToProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.gt' => 'Ask the floor for a quantity greater than zero.',
        ];
    }
}
