<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoutingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'operations.*.sequence' => ['required', 'integer', 'min:1', 'distinct'],
            'operations.*.name' => ['required', 'string', 'max:255'],
            'operations.*.standard_time_minutes' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
