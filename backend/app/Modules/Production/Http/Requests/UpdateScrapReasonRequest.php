<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a scrap reason — the Edit column the master was missing.
 *
 * The code rule is StoreScrapReasonRequest's with this row ignored, and it
 * stays GLOBAL on purpose: `unique` does not apply Eloquent's soft-delete
 * scope, so an archived scrap reason keeps its code reserved
 * (DEC-20260817-002 §2). Do not narrow this to active rows.
 *
 * `is_active` is not settable here — withdrawing a reason is the
 * archive/activate action.
 */
class UpdateScrapReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('scrap_reasons', 'code')->ignore($this->route('scrap_reason'))],
            'name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
