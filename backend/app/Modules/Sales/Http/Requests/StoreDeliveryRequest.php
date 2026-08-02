<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'delivered_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            // Either typed lines OR scanned carton codes — the scan path
            // derives its lines from the physical cartons server-side.
            'carton_codes' => ['sometimes', 'array', 'min:1'],
            'carton_codes.*' => ['string', 'max:64'],
            'lines' => ['required_without:carton_codes', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => [
                'required',
                'integer',
                Rule::exists('sales_order_lines', 'id')
                    ->where('sales_order_id', $this->input('sales_order_id')),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
