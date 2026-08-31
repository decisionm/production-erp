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
            // Party details, ABSENT unless the agent found them. The agent
            // sends nothing where Tally returned nothing, and the sync treats
            // absent as "leave alone" rather than "blank it" — a wrong guess
            // at a Tally field name must cost an empty column, never a
            // recorded GSTIN.
            'ledgers.*.gstin' => ['sometimes', 'nullable', 'string', 'max:15'],
            'ledgers.*.state_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Contact details, on the same absent-means-leave-alone contract.
            // Measured on the live company's All Masters export: 4 of 1742
            // ledgers carry an email and 78 a phone, so these keys will be
            // absent from almost every row and that is the truth about the
            // books, not a gap in the pull.
            'ledgers.*.email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ledgers.*.phone' => ['sometimes', 'nullable', 'string', 'max:64'],

            'items' => ['sometimes', 'array'],
            'items.*.guid' => ['required', 'string', 'max:255'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.base_unit' => ['nullable', 'string', 'max:50'],
            'items.*.parent' => ['nullable', 'string', 'max:255'],
            'items.*.alter_id' => ['nullable', 'integer'],
        ];
    }
}
