<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveSubcontractOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity_received' => ['required', 'numeric', 'gt:0'],
            'service_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
