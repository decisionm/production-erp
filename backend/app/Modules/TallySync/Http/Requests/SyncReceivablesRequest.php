<?php

namespace App\Modules\TallySync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The outstanding position the agent read out of Tally — the bills the factory
 * is owed, and the sales orders it has still to ship.
 *
 * READ-ONLY IN EVERY SENSE THAT MATTERS. This payload creates no voucher,
 * touches no stock and changes no master. It is a record of what Tally already
 * holds, so a person chasing a client can see what is owed and how late it is
 * without reading it off a second screen.
 *
 * `bills` AND `orders` ARE `present`, NOT `required`. Laravel's `required`
 * rejects an empty array, and the purchase-rate path already paid for that
 * lesson: an export with nothing in it made the agent post `[]`, take a 422,
 * and log the failure on the factory PC where nobody on this side can see it
 * — indistinguishable from "nobody has pressed the button yet". A pull that
 * found nothing is a legitimate state and must arrive as a recorded zero, not
 * a rejected request. What the SERVICE then does with an all-empty pull is a
 * separate and deliberate decision (it declines to wipe the standing position
 * — see TallyReceivableSyncService).
 *
 * WHAT IS REQUIRED IS ONLY WHAT MAKES A ROW MEAN ANYTHING: which party, and —
 * for a bill — how much is outstanding. Every other field is nullable, because
 * a real Tally export varies by version and by how the factory has configured
 * its own reports, and a missing optional field must cost an empty column
 * rather than a rejected pull.
 *
 * `as_of` IS REQUIRED AND IS NOT `today`. It is the date the position was read
 * as at, which the operator may deliberately set to a month end. Defaulting it
 * here would let a stale export be filed as current.
 */
class SyncReceivablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'as_of' => ['required', 'date_format:Y-m-d'],

            'bills' => ['present', 'array'],
            'bills.*.party_ledger_name' => ['required', 'string', 'max:255'],
            'bills.*.party_ledger_guid' => ['nullable', 'string', 'max:255'],
            'bills.*.bill_reference' => ['nullable', 'string', 'max:255'],
            'bills.*.bill_date' => ['nullable', 'date_format:Y-m-d'],
            'bills.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            // Signed on purpose. At the agent boundary the payload is
            // normalised to the page contract: positive means the client owes
            // us; negative means a client credit or advance. No `min:0` here,
            // ever, or a client in credit reads as a debtor.
            'bills.*.closing_amount' => ['required', 'numeric'],
            'bills.*.opening_amount' => ['nullable', 'numeric'],

            'orders' => ['present', 'array'],
            'orders.*.party_ledger_name' => ['required', 'string', 'max:255'],
            'orders.*.party_ledger_guid' => ['nullable', 'string', 'max:255'],
            'orders.*.order_reference' => ['nullable', 'string', 'max:255'],
            'orders.*.order_date' => ['nullable', 'date_format:Y-m-d'],
            'orders.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'orders.*.stock_item_name' => ['nullable', 'string', 'max:255'],
            'orders.*.pending_quantity' => ['nullable', 'numeric'],
            'orders.*.quantity_unit' => ['nullable', 'string', 'max:64'],
            'orders.*.pending_amount' => ['nullable', 'numeric'],
        ];
    }
}
