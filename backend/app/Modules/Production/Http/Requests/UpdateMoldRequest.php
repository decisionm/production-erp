<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $mold = $this->route('mold');

        return [
            'code' => ['sometimes', 'string', 'max:64', Rule::unique('molds', 'code')->ignore($mold)],
            'name' => ['sometimes', 'string', 'max:255'],
            'cavity_count' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['active', 'under_repair', 'retired'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
