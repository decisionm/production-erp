<?php

namespace App\Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['call', 'email', 'meeting', 'note'])],
            'notes' => ['required', 'string'],
            'activity_date' => ['nullable', 'date'],
            'next_follow_up_date' => ['nullable', 'date'],
        ];
    }
}
