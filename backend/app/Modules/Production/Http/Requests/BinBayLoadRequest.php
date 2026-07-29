<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One bag into one machine's bin bay, from the central bay workspace.
 *
 * This records an inventory LOCATION movement (store → machine day bin).
 * It is not consumption and it posts nothing to Tally.
 */
class BinBayLoadRequest extends FormRequest
{
    /**
     * The bay screen credits the load to whoever is signed in. Naming a
     * DIFFERENT person (the supervisor loading on behalf of the operator who
     * actually carried the bag) needs production.manage.
     *
     * Note this is deliberately belt-and-braces: `module:production` already
     * demands production.manage for every non-GET route in the group, so an
     * unprivileged caller never reaches here. The check stays because it
     * states the rule where the field is defined, and survives the route
     * group being re-gated later.
     */
    public function authorize(): bool
    {
        $named = $this->input('loaded_by');

        if ($named === null || (int) $named === (int) $this->user()?->id) {
            return true;
        }

        return (bool) $this->user()?->can('production.manage');
    }

    public function rules(): array
    {
        return [
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            // Scan-first workspace: the bag is always identified by the code
            // on it, whether typed, gun-scanned or read by the camera.
            'barcode' => ['required', 'string', 'exists:material_bags,barcode'],
            // Absent = the whole bag goes to the bay; present = a weighed
            // partial pour, the bag stays in the store with the balance.
            'quantity_kg' => ['nullable', 'numeric', 'gt:0'],
            // Optional: loading is CENTRAL and normally not tied to a batch.
            // Supplied only when the bay is topping up a specific running
            // segment and the floor wants the load attributed to it.
            'shift_production_entry_id' => ['nullable', 'integer', 'exists:shift_production_entries,id'],
            'override_fifo' => ['sometimes', 'boolean'],
            // A users row — the day-bin ledger's recorded_by is a users FK,
            // not an employees one. Defaults to the authenticated user.
            'loaded_by' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'barcode.exists' => 'Unknown bag barcode — no registered bag carries this code.',
        ];
    }

    /**
     * The payload TraceabilityService::loadBagToDayBin accepts — `loaded_by`
     * is an attribution decision made here, not part of the movement input.
     *
     * @return array<string, mixed>
     */
    public function movementData(): array
    {
        return array_diff_key($this->validated(), ['loaded_by' => null]);
    }

    /** Who the movement is credited to: the named user, else whoever is signed in. */
    public function attributedUserId(): ?int
    {
        $named = $this->validated('loaded_by');

        return $named !== null ? (int) $named : $this->user()?->id;
    }
}
