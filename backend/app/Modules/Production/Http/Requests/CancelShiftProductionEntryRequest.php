<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling a mistakenly-started batch.
 *
 * The reason is REQUIRED, unlike the rejection reason next door which is
 * nullable. A rejection is already explained by where it sits in the approval
 * chain — somebody looked at a finished shift and sent it back. A cancellation
 * has no such context: months later the only thing distinguishing "started by
 * mistake during the demo" from "a real batch someone made disappear" is the
 * sentence typed here.
 */
class CancelShiftProductionEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this batch is being cancelled — it is the only record of it afterwards.',
        ];
    }
}
