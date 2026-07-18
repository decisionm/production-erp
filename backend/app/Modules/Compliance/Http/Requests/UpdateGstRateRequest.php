<?php

namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGstRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rate = $this->route('gst_rate');

        return [
            'hsn_sac_code' => ['sometimes', 'string', 'max:20', Rule::unique('gst_rates', 'hsn_sac_code')->ignore($rate)],
            'description' => ['nullable', 'string', 'max:255'],
            'rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
