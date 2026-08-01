<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // THE ACKNOWLEDGEMENT, sent only when the previous attempt was
            // refused because the machine still shows material. Optional
            // here rather than conditionally required, because whether it is
            // needed depends on the machine's live estimate — a fact this
            // request cannot see and the service must decide (see
            // FactoryDayBinService::guardMachineBalance). Validating the
            // VOCABULARY is still this layer's job.
            'balance_ack_reason' => ['nullable', 'string', Rule::in(FactoryDayBinService::ACK_REASONS)],
            'balance_ack_note' => ['nullable', 'string', 'max:200'],
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
