<?php

namespace App\Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeasuringInstrumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'unique:measuring_instruments,code'],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'calibration_frequency_days' => ['required', 'integer', 'min:1'],
            'next_calibration_due' => ['required', 'date'],
        ];
    }
}
