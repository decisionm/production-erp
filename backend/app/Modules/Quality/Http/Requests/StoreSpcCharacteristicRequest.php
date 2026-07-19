<?php

namespace App\Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpcCharacteristicRequest extends FormRequest
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
            'unit_of_measure' => ['nullable', 'string', 'max:32'],
            'target_value' => ['nullable', 'numeric'],
            'lower_spec_limit' => ['nullable', 'numeric'],
            'upper_spec_limit' => ['nullable', 'numeric'],
        ];
    }
}
