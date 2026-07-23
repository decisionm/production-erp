<?php

namespace App\Modules\TallySync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTallyCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by module:tally-sync
    }

    public function rules(): array
    {
        return [
            'company' => ['nullable', 'string', 'max:255'],
        ];
    }
}
