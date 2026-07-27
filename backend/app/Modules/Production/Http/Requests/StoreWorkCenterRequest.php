<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'unique:work_centers,code'],
            'name' => ['required', 'string', 'max:255'],
            'display_sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'capacity_hours_per_day' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
