<?php

namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGstRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hsn_sac_code' => ['required', 'string', 'max:20', 'unique:gst_rates,hsn_sac_code'],
            'description' => ['nullable', 'string', 'max:255'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
