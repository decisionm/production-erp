<?php

namespace App\Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCalibrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'calibrated_date' => ['required', 'date'],
            'certificate_number' => ['nullable', 'string', 'max:255'],
            'result' => ['required', Rule::in(['pass', 'fail', 'adjusted'])],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
