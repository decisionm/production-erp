<?php

namespace App\Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCapaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verified_effective' => ['required', 'boolean'],
        ];
    }
}
