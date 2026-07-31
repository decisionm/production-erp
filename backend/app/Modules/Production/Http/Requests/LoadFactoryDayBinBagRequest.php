<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Shift Floor's centralized bag scan into the FACTORY day bin —
 * barcode in, kg out of the store, no machine picked.
 *
 * There is deliberately NO recorded_by/loaded_by field: the audit identity
 * on the stock movements is always the authenticated user. supervisor_id
 * only NAMES who was acting supervisor at the scan (defaulted to the
 * logged-in user by the page), it never becomes the identity.
 */
class LoadFactoryDayBinBagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'exists:material_bags,barcode'],
            // Absent = the whole bag (its remaining_kg); present = a weighed
            // partial load, same convention as the per-machine day-bin load.
            'quantity_kg' => ['nullable', 'numeric', 'gt:0'],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            // A scanner gun mistyping (or a bag from a never-registered lot)
            // must read as exactly what it is, not a generic "invalid".
            'barcode.required' => 'Scan or type a bag barcode.',
            'barcode.exists' => 'Unknown bag barcode — no registered bag carries this code.',
        ];
    }
}
