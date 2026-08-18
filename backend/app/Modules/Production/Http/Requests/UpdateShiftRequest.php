<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a shift — the Edit column of the Configuration Lifecycle
 * Contract, which this master had no endpoint for at all.
 *
 * The name rule is StoreShiftRequest's, with the row itself ignored. It
 * stays GLOBAL — Laravel's unique rule queries the table without Eloquent's
 * soft-delete scope, so an ARCHIVED shift's name is still taken, which is
 * DEC-20260817-002 §2 exactly: an archived record retains and RESERVES its
 * business code. Live still carries the deactivated Morning/Afternoon/Night
 * rows, and their names must stay taken.
 *
 * `is_active` is deliberately NOT settable here: taking a shift out of
 * service is the archive/activate action, which is the one place that
 * decision is made and audited.
 */
class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:64', Rule::unique('shifts', 'name')->ignore($this->route('shift'))],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
        ];
    }
}
