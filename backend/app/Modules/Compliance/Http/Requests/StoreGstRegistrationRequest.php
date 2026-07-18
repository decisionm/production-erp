<?php

namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGstRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gstin' => [
                'required', 'string', 'size:15', 'unique:gst_registrations,gstin',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
            ],
            'state_code' => ['required', 'string', 'regex:/^[0-9]{2}$/'],
            'state_name' => ['required', 'string', 'max:255'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
