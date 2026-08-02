<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding a machine to the master.
 *
 * Deliberately the SAME vocabulary as UpdateWorkCenterRequest, field for
 * field. Before this, capabilities could only be set by saving a machine
 * twice — create it, then edit it — which is not a rule anybody stated, just
 * an accident of the store request having been written first and never
 * caught up. One vocabulary means a machine added with its cavity range known
 * arrives complete, and the form can be the same form in both directions.
 */
class StoreWorkCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // "A maximum must not be below the minimum" — but ONLY when a
        // minimum was actually stated. A bare `gte:min_cavities` compares
        // against a field that isn't there and refuses the request, so
        // stating one bound and leaving the other unknown ("this machine
        // never runs above 12 cavities; nobody has measured a floor")
        // became impossible. R5's rule is that a blank limit never blocks;
        // a blank limit that produced a 422 was blocking hardest of all.
        $cavityFloor = $this->filled('min_cavities') ? ['gte:min_cavities'] : [];
        $cycleFloor = $this->filled('cycle_time_min') ? ['gte:cycle_time_min'] : [];

        return [
            'code' => ['required', 'string', 'max:32', 'unique:work_centers,code'],
            'name' => ['required', 'string', 'max:255'],
            'display_sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'capacity_hours_per_day' => ['nullable', 'numeric', 'min:0'],
            // Omitted means active — WorkCenterService::create() defaults it.
            // Present and false is how a machine is staged before it reaches
            // the floor.
            'is_active' => ['sometimes', 'boolean'],

            // Machine capabilities. Every one is nullable and null means
            // "no limit known" — the honest state for all ten machines
            // today, since the factory's master workbook leaves every
            // cavity field empty. A null limit never blocks; only a stated
            // one does.
            'capacity_class' => ['sometimes', 'nullable', 'string', 'max:32'],
            'min_cavities' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:512'],
            'max_cavities' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:512', ...$cavityFloor],
            'permitted_cavities' => ['sometimes', 'nullable', 'array'],
            'permitted_cavities.*' => ['integer', 'min:1', 'max:512'],
            'cycle_time_min' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'cycle_time_max' => ['sometimes', 'nullable', 'numeric', 'gt:0', ...$cycleFloor],
            'default_shift_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],
            'confirmation_status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
