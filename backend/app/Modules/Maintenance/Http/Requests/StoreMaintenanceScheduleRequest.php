<?php

namespace App\Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // WS-B: a RETIRED asset gets no new schedule. `under_maintenance`
            // deliberately still qualifies — an asset being worked on is
            // exactly the asset maintenance is planned for.
            'asset_id' => ['required', 'integer', Rule::exists('assets', 'id')->whereNot('status', 'retired')],
            'name' => ['required', 'string', 'max:255'],
            'frequency_days' => ['required', 'integer', 'min:1'],
            'next_due_date' => ['required', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
