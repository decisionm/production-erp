<?php

namespace App\Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'name' => ['required', 'string', 'max:255'],
            'frequency_days' => ['required', 'integer', 'min:1'],
            'next_due_date' => ['required', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
