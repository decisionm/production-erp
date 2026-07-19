<?php

namespace App\Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'type' => ['required', Rule::in(['preventive', 'corrective'])],
            'description' => ['nullable', 'string'],
            'reported_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:employees,id'],
        ];
    }
}
