<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Procurement\Models\Enums\VendorClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional: VendorService mints "V-0001" when none is sent. Still
            // accepted and still unique when a caller brings its own — /api/v1
            // is a reusable surface, and an existing client that posts a code
            // keeps working.
            'code' => ['sometimes', 'nullable', 'string', 'max:32', 'unique:vendors,code'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'],
            'state_code' => ['nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'is_active' => ['boolean'],
            // The vendor's Tally ledger name (Phase 6) — typed by Accounts, never pulled.
            'tally_ledger_name' => ['nullable', 'string', 'max:255'],
            // DEC-20260902-026: one or more of five classifications, set by a person.
            'classifications' => ['sometimes', 'array'],
            'classifications.*' => ['string', Rule::enum(VendorClassification::class)],
        ];
    }
}
