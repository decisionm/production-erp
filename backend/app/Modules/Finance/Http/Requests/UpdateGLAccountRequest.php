<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGLAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $account = $this->route('gl_account');

        return [
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('gl_accounts', 'code')->ignore($account)],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'is_active' => ['boolean'],
        ];
    }
}
