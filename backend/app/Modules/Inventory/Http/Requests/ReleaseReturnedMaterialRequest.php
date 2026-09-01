<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Quality looked and it is NOT damaged: the quantity goes to a store as
 * usable stock (DEC-20260901-003).
 *
 * This is the door that keeps a mis-ticked return from stranding good
 * material for ever. It is the agent's reading of the word "directly" in "a
 * damaged return must never go DIRECTLY back to usable stock", recorded as
 * such in the decision so the owner can withdraw it in one line.
 *
 * The destination is validated only as an existing warehouse here; that it is
 * not the quality-hold location itself is the SERVICE's refusal, in words
 * written for a person who picked the wrong row rather than for a caller with
 * a bug.
 */
class ReleaseReturnedMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'max:99999999999', new PlainDecimal],
        ];
    }
}
