<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Services\PermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(app(PermissionService::class)->allPermissionNames())],
        ];
    }
}
