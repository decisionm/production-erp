<?php

namespace App\Modules\TallySync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The full masters batch the agent pulls from Tally. Every section is optional
 * so the agent can push whatever it read this cycle (items only, or the whole
 * chart of accounts). Ability gating (tally-sync:masters) is in the controller.
 */
class SyncMastersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The Tally company this pull came from — used to bind the instance
            // to one company and reject masters from any other (see the
            // controller's single-tenant guard).
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],

            'item_groups' => ['sometimes', 'array'],
            'item_groups.*.guid' => ['required', 'string', 'max:255'],
            'item_groups.*.name' => ['required', 'string', 'max:255'],
            'item_groups.*.parent' => ['nullable', 'string', 'max:255'],

            'godowns' => ['sometimes', 'array'],
            'godowns.*.guid' => ['required', 'string', 'max:255'],
            'godowns.*.name' => ['required', 'string', 'max:255'],
            'godowns.*.parent' => ['nullable', 'string', 'max:255'],

            'ledger_groups' => ['sometimes', 'array'],
            'ledger_groups.*.guid' => ['required', 'string', 'max:255'],
            'ledger_groups.*.name' => ['required', 'string', 'max:255'],
            'ledger_groups.*.parent' => ['nullable', 'string', 'max:255'],

            'ledgers' => ['sometimes', 'array'],
            'ledgers.*.guid' => ['required', 'string', 'max:255'],
            'ledgers.*.name' => ['required', 'string', 'max:255'],
            'ledgers.*.group' => ['nullable', 'string', 'max:255'],
            /*
             * Party details, ABSENT unless the agent found them. The agent
             * sends nothing where Tally returned nothing, and the sync treats
             * absent as "leave alone" rather than "blank it" — a wrong guess
             * at a Tally field name must cost an empty column, never a
             * recorded GSTIN.
             *
             * THESE LIMITS ARE A SANITY CEILING, NOT THE COLUMN WIDTH, and the
             * difference is the whole lesson of 31-Aug-2026. `gstin` was
             * `max:15` — the column's exact width — and three of this
             * factory's 1742 ledgers carry a good GSTIN with Tally's
             * `&#13;&#10;` stuck on the end, 25 characters. The rule did what
             * it was told and rejected the request, and because validation is
             * all-or-nothing that took down THE ENTIRE PULL: 1741 innocent
             * ledgers, every item, every godown, every ledger group. The
             * factory's masters sync simply stopped.
             *
             * A MIRROR MUST NOT FAIL CLOSED ON ONE ROW. The ceiling now only
             * refuses input too large to be anybody's honest mistake; deciding
             * whether a value is usable, and cleaning Tally's export artefacts
             * off it, belongs to LedgerSyncService via TallyText — which can
             * drop ONE field on ONE ledger instead of refusing 1742.
             *
             * ONE CEILING FOR ALL FOUR, AND DELIBERATELY FAR ABOVE ANY COLUMN.
             * The first draft of this fix set each rule to its column width
             * again (255, 64) and a test caught it immediately: a 400-character
             * phone number still took the whole pull down, which is the same
             * defect at a different number. A ceiling that tracks the column
             * IS the bug. This one exists only to bound the payload, so it
             * sits an order of magnitude above anything a person could type
             * into a Tally field by accident.
             */
            'ledgers.*.gstin' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'ledgers.*.state_name' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Contact details, on the same absent-means-leave-alone contract
            // and the same sanity-ceiling reasoning. Measured on the live
            // company's All Masters export: 4 of 1742 ledgers carry an email
            // and 78 a phone, so these keys will be absent from almost every
            // row and that is the truth about the books, not a gap in the pull.
            'ledgers.*.email' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'ledgers.*.phone' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'items' => ['sometimes', 'array'],
            'items.*.guid' => ['required', 'string', 'max:255'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.base_unit' => ['nullable', 'string', 'max:50'],
            'items.*.parent' => ['nullable', 'string', 'max:255'],
            'items.*.alter_id' => ['nullable', 'integer'],
        ];
    }
}
