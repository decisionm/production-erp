<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSerialNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'serial_number' => [
                'required', 'string', 'max:64',
                Rule::unique('serial_numbers')->where(fn ($query) => $query->where('item_id', $this->input('item_id'))),
            ],
        ];
    }
}
