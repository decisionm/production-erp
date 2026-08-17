<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately permissive on the standards themselves: a configuration
     * is CREATED as a draft to capture what is known so far, and half the
     * factory's answers arrive one at a time. Completeness is enforced at
     * approval (ProductionConfigurationService::assertComplete), which is
     * the moment it starts governing production.
     *
     * `status` is not accepted here — creation always yields a draft.
     */
    public function rules(): array
    {
        return [
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            // WS-B: a retired mould cannot govern a NEW configuration.
            // Existing configurations keep (and still display) theirs.
            'mold_id' => ['nullable', 'integer', Rule::exists('molds', 'id')->whereNot('status', 'retired')],
            'colour' => ['nullable', 'string', 'max:64'],
            'unit_weight_grams' => ['nullable', 'numeric', 'gt:0'],
            'default_cycle_time' => ['nullable', 'numeric', 'gt:0', 'max:9999.99'],
            'cycle_time_min' => ['nullable', 'numeric', 'gt:0'],
            'cycle_time_max' => ['nullable', 'numeric', 'gt:0', 'gte:cycle_time_min'],
            'default_cavities' => ['nullable', 'integer', 'min:1', 'max:512'],
            'cavities_min' => ['nullable', 'integer', 'min:1'],
            'cavities_max' => ['nullable', 'integer', 'min:1', 'gte:cavities_min'],
            'permitted_cavities' => ['nullable', 'array'],
            'permitted_cavities.*' => ['integer', 'min:1', 'max:512'],
            'bom_id' => ['nullable', 'integer', 'exists:boms,id'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string'],
            'confirmation_status' => ['nullable', 'string', 'max:32'],
        ];
    }
}
