<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteReworkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity_recovered' => ['required', 'numeric', 'gt:0'],
            'labor_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
