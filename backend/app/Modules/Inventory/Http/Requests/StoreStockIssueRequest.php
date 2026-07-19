<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'serial_number_id' => ['nullable', 'integer', 'exists:serial_numbers,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
