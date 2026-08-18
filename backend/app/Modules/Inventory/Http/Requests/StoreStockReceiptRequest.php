<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // WS-B (audit 17-Aug-2026 §1): Item and Warehouse were unfiltered
            // on every stock path even though production filters them. A
            // retired item or store takes no NEW movement; the movements
            // already recorded against it are untouched and still read back.
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('is_active', true)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'quantity' => ['required', 'numeric', 'gt:0', new PlainDecimal],
            'unit_cost' => ['required', 'numeric', 'min:0', new PlainDecimal],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'serial_number_id' => ['nullable', 'integer', 'exists:serial_numbers,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
