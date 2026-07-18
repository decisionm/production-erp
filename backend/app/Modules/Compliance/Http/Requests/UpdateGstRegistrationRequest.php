<?php

namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGstRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $registration = $this->route('gst_registration');

        return [
            'gstin' => [
                'sometimes', 'string', 'size:15', Rule::unique('gst_registrations', 'gstin')->ignore($registration),
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
            ],
            'state_code' => ['sometimes', 'string', 'regex:/^[0-9]{2}$/'],
            'state_name' => ['sometimes', 'string', 'max:255'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
