<?php

namespace App\Modules\TallySync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The stock-item batch the local agent posts each masters-pull cycle. Ability
 * gating (tally-sync:items) is enforced in the controller, matching how the
 * other agent endpoints check tokenCan() — see TallySyncAgentController.
 */
class SyncItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.guid' => ['required', 'string', 'max:255'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.base_unit' => ['nullable', 'string', 'max:50'],
            'items.*.parent' => ['nullable', 'string', 'max:255'],
            'items.*.alter_id' => ['nullable', 'integer'],
        ];
    }
}
