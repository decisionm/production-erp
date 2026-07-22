<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'unique:molds,code'],
            'name' => ['required', 'string', 'max:255'],
            'cavity_count' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'under_repair', 'retired'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
