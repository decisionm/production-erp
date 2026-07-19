<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'bom_id' => [
                'nullable', 'integer',
                Rule::exists('boms', 'id')->where(fn ($query) => $query->where('item_id', $this->input('item_id'))),
            ],
            'routing_id' => [
                'nullable', 'integer',
                Rule::exists('routings', 'id')->where(fn ($query) => $query->where('item_id', $this->input('item_id'))),
            ],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity_planned' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
