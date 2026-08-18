<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /inventory/material-requests — the floor raises a request.
 *
 * `work_center_id` is OPTIONAL and, for a common-input (kg-family) item, it
 * is REFUSED — FC-01 / DEC-20260807-006. That refusal is NOT expressed here:
 * it needs the item rows, it must read exactly the same predicate the rest
 * of the codebase uses, and it must be impossible to bypass by calling the
 * service directly. It lives in MaterialRequestService instead, and answers
 * a 422 with `code: common_input_names_no_machine`.
 *
 * `uom` is NOT accepted. The unit is snapshotted from the item (FC-03: a
 * tape figure in metres filed as Nos is a different number about a
 * different thing, and that reached live once). A caller who sends one is
 * simply not read.
 *
 * Only ACTIVE masters may be named: a request against a retired machine,
 * shift or item is a request nobody can fulfil.
 */
class StoreMaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['sometimes', 'nullable', 'integer', Rule::exists('shifts', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'work_center_id' => ['sometimes', 'nullable', 'integer', Rule::exists('work_centers', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')->where('is_active', true)],
            // Stock quantities are decimal everywhere in this codebase, and
            // an ask of zero is not an ask.
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'A material request has to name at least one material.',
            'lines.*.quantity.gt' => 'Ask for a quantity greater than zero.',
        ];
    }
}
