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
            // WS-B: a RETIRED asset takes no new work order;
            // `under_maintenance` must and does still pass.
            'asset_id' => ['required', 'integer', Rule::exists('assets', 'id')->whereNot('status', 'retired')],
            'type' => ['required', Rule::in(['preventive', 'corrective'])],
            'description' => ['nullable', 'string'],
            'reported_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:employees,id'],
        ];
    }
}
