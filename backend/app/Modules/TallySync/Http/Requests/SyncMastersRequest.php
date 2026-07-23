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

            'items' => ['sometimes', 'array'],
            'items.*.guid' => ['required', 'string', 'max:255'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.base_unit' => ['nullable', 'string', 'max:50'],
            'items.*.parent' => ['nullable', 'string', 'max:255'],
            'items.*.alter_id' => ['nullable', 'integer'],
        ];
    }
}
