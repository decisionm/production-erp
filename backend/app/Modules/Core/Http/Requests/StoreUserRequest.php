<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level auth:sanctum gate applies; per-action policies land per module as needed.
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ];
    }
}
