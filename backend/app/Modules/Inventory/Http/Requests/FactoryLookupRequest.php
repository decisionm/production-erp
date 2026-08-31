<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Services\FactoryLookupService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The one parameter, and the one refusal.
 *
 * A MINIMUM LENGTH IS A PRIVACY RULE, not input hygiene. `issue_number` runs
 * SI-000001, SI-000002, … so a two-character `SI` walks the factory's
 * handover history for anyone holding inventory.view. The floor is the
 * cheapest possible guard and it costs a real user nothing — every
 * identifier a person actually holds in their hand is longer than this.
 */
class FactoryLookupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.FactoryLookupService::MIN_TERM, 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Type or scan a number to look up.',
            'q.min' => 'Use at least '.FactoryLookupService::MIN_TERM.' characters — a shorter one would match too much to be useful.',
        ];
    }
}
