<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'batch_number' => [
                'required', 'string', 'max:64',
                Rule::unique('batches')->where(fn ($query) => $query->where('item_id', $this->input('item_id'))),
            ],
            'manufactured_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:manufactured_date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
