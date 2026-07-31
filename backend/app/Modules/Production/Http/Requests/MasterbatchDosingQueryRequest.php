<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The floor's read: "what dosing applies here, and what kg is that for the
 * bottles I have made?"
 *
 * Everything is optional, like BatchPreviewRequest, because the screen calls
 * it from a half-filled form: with nothing it is the master list, with
 * item_id it is the dosings that could apply to that bottle, and with
 * quantity_produced each one also carries its kg.
 */
class MasterbatchDosingQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The PRODUCT (bottle) — named item_id to match every other
            // production endpoint, where item_id is always the thing produced.
            'item_id' => ['sometimes', 'nullable', 'integer', 'exists:items,id'],
            'masterbatch_item_id' => ['sometimes', 'nullable', 'integer', 'exists:items,id'],
            // Bottles produced. Zero is allowed and means zero kg — a shift
            // that made nothing consumed no colour, which is a known fact,
            // not a missing one.
            'quantity_produced' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
