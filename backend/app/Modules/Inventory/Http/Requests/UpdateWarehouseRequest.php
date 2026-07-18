<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $warehouse = $this->route('warehouse');

        return [
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('warehouses', 'code')->ignore($warehouse)],
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
