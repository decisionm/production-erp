<?php

namespace App\Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // WS-B: no new opportunity against a retired customer.
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'stage' => ['nullable', Rule::in(['prospecting', 'qualification', 'proposal', 'negotiation', 'won', 'lost'])],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'probability' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
