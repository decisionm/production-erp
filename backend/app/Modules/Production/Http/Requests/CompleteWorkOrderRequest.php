<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // WS-B: a withdrawn scrap reason is no longer selectable here.
            'scrap.*.scrap_reason_id' => ['required', 'integer', Rule::exists('scrap_reasons', 'id')->where('is_active', true)],
            'scrap.*.quantity' => ['required', 'numeric', 'gt:0'],
            'scrap.*.notes' => ['nullable', 'string'],
        ];
    }
}
