<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Shift Floor's bag scan — barcode in, kg out of the store, INTO A
 * NAMED MACHINE.
 *
 * work_center_id is REQUIRED, and that is the owner's ruling (31-Jul):
 * "Scanning a bag means material was loaded into the selected machine."
 * Optional would have been worse than absent — an unattributed load
 * silently overstates the estimated remaining of every machine except the
 * one that actually burnt the material, and nothing on any screen would
 * say so.
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
            // The machine the bag was emptied into.
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
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
            'work_center_id.required' => 'Pick the machine this bag was loaded into.',
        ];
    }
}
