<?php

namespace App\Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNonConformanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'incoming_inspection_id' => ['nullable', 'integer', 'exists:incoming_inspections,id'],
            'item_id' => ['required_without:incoming_inspection_id', 'nullable', 'integer', 'exists:items,id'],
            'description' => ['required', 'string'],
            'severity' => ['required', Rule::in(['minor', 'major', 'critical'])],
            'quantity_affected' => ['nullable', 'numeric', 'min:0'],
            'raised_date' => ['required', 'date'],
        ];
    }
}
