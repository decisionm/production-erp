<?php

namespace App\Modules\TallySync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FailTallySyncEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'error_message' => ['required', 'string'],
        ];
    }
}
