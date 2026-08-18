<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Services\FactoryDayBinService;
use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Shift Floor's bag scan — barcode in, kg out of the store, into the
 * COMMON RESIN INPUT.
 *
 * THERE IS NO MACHINE FIELD, and its absence is the owner's correction
 * (2-Aug): the factory has one common resin input point for all machines and
 * a bag is never assigned or scanned to a machine. work_center_id used to be
 * required here; it is not merely optional now, it is not a field.
 *
 * IT IS STILL ACCEPTED AND IGNORED, for the length of the deploy window
 * only. A floor tablet running the previous build will keep posting
 * work_center_id for as long as it has not reloaded, and refusing those
 * scans would stop material entering the factory over a field the server no
 * longer wants. It is deliberately absent from rules() rather than validated
 * as `sometimes`, so nothing downstream can read it back out of validated()
 * and quietly start attributing loads to machines again.
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
            // partial load. Partial loads and the bag's remaining balance are
            // preserved exactly as they were.
            'quantity_kg' => ['nullable', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
            // THE ACKNOWLEDGEMENT, sent only when the previous attempt was
            // refused because the common input still shows material.
            // Optional here rather than conditionally required, because
            // whether it is needed depends on the live estimate — a fact this
            // request cannot see and the service must decide (see
            // FactoryDayBinService::guardCommonInputBalance). Validating the
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
        ];
    }
}
