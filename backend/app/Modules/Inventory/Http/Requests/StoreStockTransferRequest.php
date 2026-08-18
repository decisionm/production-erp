<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // WS-B: both ends of a transfer must be live stores, and the
            // item still on the catalogue.
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('is_active', true)],
            'from_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true), 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'quantity' => ['required', 'numeric', 'gt:0', new PlainDecimal],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'serial_number_id' => ['nullable', 'integer', 'exists:serial_numbers,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
