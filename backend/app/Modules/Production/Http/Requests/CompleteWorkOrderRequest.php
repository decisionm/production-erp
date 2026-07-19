<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity_completed' => ['required', 'numeric', 'gt:0'],
            'batch_number' => ['nullable', 'string', 'max:64'],
            'scrap' => ['nullable', 'array'],
            'scrap.*.scrap_reason_id' => ['required', 'integer', 'exists:scrap_reasons,id'],
            'scrap.*.quantity' => ['required', 'numeric', 'gt:0'],
            'scrap.*.notes' => ['nullable', 'string'],
        ];
    }
}
