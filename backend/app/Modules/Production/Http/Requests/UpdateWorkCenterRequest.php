<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workCenter = $this->route('work_center');

        return [
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('work_centers', 'code')->ignore($workCenter)],
            'name' => ['sometimes', 'string', 'max:255'],
            'display_sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'capacity_hours_per_day' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            // Machine capabilities. Every one is nullable and null means
            // "no limit known" — the honest state for all ten machines
            // today, since the factory's master workbook leaves every
            // cavity field empty. A null limit never blocks; only a stated
            // one does.
            'capacity_class' => ['sometimes', 'nullable', 'string', 'max:32'],
            'min_cavities' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:512'],
            'max_cavities' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:512', ...$this->floor('min_cavities', $workCenter)],
            'permitted_cavities' => ['sometimes', 'nullable', 'array'],
            'permitted_cavities.*' => ['integer', 'min:1', 'max:512'],
            'cycle_time_min' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'cycle_time_max' => ['sometimes', 'nullable', 'numeric', 'gt:0', ...$this->floor('cycle_time_min', $workCenter)],
            'default_shift_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],
            'confirmation_status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /**
     * The lower bound a maximum must respect, as a rule list — empty when
     * there is no lower bound to respect.
     *
     * A PATCH sends only what changed, so the minimum this maximum has to
     * clear is usually not in the payload at all. A bare `gte:min_cavities`
     * then compares against a field that isn't there and refuses the
     * request, which made "raise this machine's ceiling to 12" impossible to
     * express without also resending its floor. Three cases, in the order
     * they are true:
     *
     *  - the payload states a floor → compare against the payload's
     *  - the payload explicitly CLEARS the floor → nothing to clear
     *  - the payload is silent → compare against the machine's stored floor,
     *    so a maximum still cannot be dropped below a minimum that remains
     *    in force
     *
     * @return list<string>
     */
    private function floor(string $field, mixed $workCenter): array
    {
        if ($this->filled($field)) {
            return ["gte:{$field}"];
        }

        if ($this->has($field)) {
            return [];
        }

        $stored = $workCenter instanceof WorkCenter ? $workCenter->{$field} : null;

        return $stored === null ? [] : ['gte:'.$stored];
    }
}
