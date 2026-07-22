<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseDowntimeLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remedy' => ['nullable', 'string'],
            'parts_changed' => ['nullable', 'string', 'max:255'],
        ];
    }
}
