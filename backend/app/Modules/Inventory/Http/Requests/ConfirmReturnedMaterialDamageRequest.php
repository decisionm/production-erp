<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Quality confirming the damage on material returned from Production
 * (DEC-20260901-003) — after which the quantity is scrapped and leaves stock.
 *
 * The quantity is only bounded here by "a number"; whether it is more than
 * what is actually standing in quality hold is the SERVICE's refusal, because
 * only the service holds the balance under a lock while it decides. A
 * validator that read the standing figure first would be reading it without
 * one.
 *
 * NO `->where('is_active', true)` ON THE ITEM, for the same reason the return
 * door has none: a deactivated master must still be able to finish its
 * journey. Material that reached quality hold is already in the system, and
 * refusing to dispose of it because somebody retired the item would strand it
 * in a location nothing can draw from.
 */
class ConfirmReturnedMaterialDamageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'max:99999999999', new PlainDecimal],
        ];
    }
}
