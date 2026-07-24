<?php

namespace App\Modules\TallySync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLedgerMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by module:tally-sync
    }

    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array'],
            'mappings.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
