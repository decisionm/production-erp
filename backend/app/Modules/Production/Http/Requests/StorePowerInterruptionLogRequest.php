<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePowerInterruptionLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'production_date' => ['nullable', 'date'],
            'from_time' => ['required', 'date'],
            'to_time' => ['required', 'date', 'after:from_time'],
        ];
    }
}
