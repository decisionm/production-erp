<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The common-resin read's one leftover filter, kept ONLY for the deploy
 * window.
 *
 * The owner's correction (2-Aug) removed the machine dimension from this read
 * entirely — the factory has one common resin input point and a per-machine
 * balance was a number with no physical referent. So `work_center_id` narrows
 * nothing any more, and the controller ignores it.
 *
 * IT IS STILL VALIDATED RATHER THAN DROPPED, for the same reason the load
 * request still tolerates the field: a floor tablet running the previous
 * build keeps sending it until it reloads, and this read is what that tablet
 * polls. Accepting-and-ignoring shows it a stale-shaped answer it can fail to
 * parse loudly; a 422 would show it nothing at all. Validating the value
 * keeps `?work_center_id=abc` an obvious error rather than silent noise.
 *
 * DELETE THIS REQUEST once the floor has reloaded — the field is dead weight,
 * not a feature.
 */
class MachineResinQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by module:production
    }

    public function rules(): array
    {
        return [
            'work_center_id' => ['sometimes', 'integer', 'exists:work_centers,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'work_center_id.exists' => 'No such machine.',
        ];
    }
}
