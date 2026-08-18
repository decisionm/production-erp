<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /inventory/material-requests/{id}/cancel — a reason is REQUIRED.
 *
 * Either side may cancel (the floor withdraws what it no longer needs, the
 * store hands back what it cannot fulfil), so the record has to say who and
 * why. A cancellation with no reason is a hole in the audit trail the next
 * shift cannot read.
 */
class CancelMaterialRequestRequest extends FormRequest
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
