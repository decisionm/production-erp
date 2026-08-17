<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubcontractOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // WS-B: a vendor the factory has retired can no longer be given
            // new work — the flag was set and filtered nowhere.
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('is_active', true)],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'bom_id' => [
                'nullable', 'integer',
                Rule::exists('boms', 'id')->where(fn ($query) => $query->where('item_id', $this->input('item_id'))),
            ],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity_planned' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
