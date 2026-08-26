<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /production/requests/{id}/cancel — a reason is REQUIRED.
 *
 * Cancelling is TWO-SIDED (P3), exactly like a material request: the store
 * withdraws what the customer no longer wants, the floor hands back what it
 * cannot run. Whichever side did it, the record has to say why — a
 * withdrawn job with no reason is a hole the next shift cannot read.
 *
 * Same rule shape as CancelMaterialRequestRequest, deliberately: one
 * grammar for "withdraw this piece of paper, and say why".
 */
class CancelProductionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the request is being cancelled — the next shift reads this.',
        ];
    }
}
