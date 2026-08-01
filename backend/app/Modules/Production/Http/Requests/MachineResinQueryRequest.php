<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The per-machine resin estimate's one optional filter.
 *
 * VALIDATED RATHER THAN COERCED, deliberately. A silent `is_numeric() ? ... :
 * null` would turn `?work_center_id=abc` into "every machine" and
 * `?work_center_id=99999` into an empty page — both of them confident answers
 * to a question nobody asked. This is the same rule the retired
 * reconciliation read applied to its date, and it is the one worth keeping
 * from it: a read that cannot honour its filter says so.
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
