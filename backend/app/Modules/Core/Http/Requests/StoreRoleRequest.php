<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Services\PermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(app(PermissionService::class)->allPermissionNames())],
        ];
    }
}
