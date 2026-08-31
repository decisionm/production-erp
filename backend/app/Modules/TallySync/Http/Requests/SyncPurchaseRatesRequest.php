<?php

namespace App\Modules\TallySync\Http\Requests;

use App\Modules\TallySync\Models\TallyPurchaseRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The purchase-rate lines the agent read out of the factory's Day Book.
 *
 * READ-ONLY IN EVERY SENSE THAT MATTERS. This payload creates no voucher,
 * touches no stock and changes no master; it is a record of what Tally already
 * holds, so a person raising a purchase order can see what was last agreed and
 * last billed instead of remembering it.
 *
 * WHAT IS REQUIRED IS WHAT MAKES A RATE QUOTABLE: which voucher it was, of
 * which kind, on what date, from which party, for which item, and at what
 * rate. Everything else is nullable, because a real Tally export varies by
 * version and setup and a missing optional field must cost an empty column
 * rather than a rejected pull.
 *
 * `rate_unit` is nullable and NOT optional furniture: the lookup refuses to
 * prefill a rate whose unit does not match the item's own, and a rate that
 * arrived with no unit at all is one whose basis cannot be confirmed. Both
 * cases are shown to the reader and neither prefills.
 */
class SyncPurchaseRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],

            'lines' => ['required', 'array'],
            'lines.*.voucher_guid' => ['required', 'string', 'max:255'],
            'lines.*.line_index' => ['required', 'integer', 'min:0'],
            'lines.*.voucher_type' => ['required', Rule::in(TallyPurchaseRate::TYPES)],
            'lines.*.voucher_number' => ['nullable', 'string', 'max:255'],
            'lines.*.voucher_reference' => ['nullable', 'string', 'max:255'],
            'lines.*.voucher_date' => ['required', 'date'],

            'lines.*.party_ledger_name' => ['required', 'string', 'max:255'],
            'lines.*.party_gstin' => ['nullable', 'string', 'max:15'],

            'lines.*.stock_item_name' => ['required', 'string', 'max:255'],

            'lines.*.rate_value' => ['required', 'numeric'],
            'lines.*.rate_unit' => ['nullable', 'string', 'max:64'],
            'lines.*.quantity' => ['nullable', 'numeric'],
            'lines.*.quantity_unit' => ['nullable', 'string', 'max:64'],
            'lines.*.amount' => ['nullable', 'numeric'],

            'lines.*.cgst_rate' => ['nullable', 'numeric'],
            'lines.*.sgst_rate' => ['nullable', 'numeric'],
            'lines.*.igst_rate' => ['nullable', 'numeric'],
            'lines.*.cess_rate' => ['nullable', 'numeric'],
            'lines.*.hsn_code' => ['nullable', 'string', 'max:64'],
            'lines.*.purchase_ledger_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
