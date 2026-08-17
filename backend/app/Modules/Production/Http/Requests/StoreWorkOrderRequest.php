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
            // WS-B: the routing must still be the item's AND still active —
            // a withdrawn routing described how the item USED to be made.
            'routing_id' => [
                'nullable', 'integer',
                Rule::exists('routings', 'id')->where(
                    fn ($query) => $query->where('item_id', $this->input('item_id'))->where('is_active', true),
                ),
            ],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'scheduled_date' => ['nullable', 'date'],
            'quantity_planned' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
