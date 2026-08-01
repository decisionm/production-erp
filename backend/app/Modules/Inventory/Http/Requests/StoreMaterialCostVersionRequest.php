<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\MaterialCostVersionKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialCostVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route's module:finance middleware is the gate — Owner and
        // Accounts only. Same pattern as every other request in the module.
        return true;
    }

    public function rules(): array
    {
        return [
            'rate_per_kg' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            // 'receipt' is not appendable: the original rate belongs to the
            // system, and letting a person file a second "original" after
            // the fact would destroy the one number nothing may move.
            'kind' => ['required', 'string', Rule::in(MaterialCostVersionKind::appendableValues())],
            // Required, and required to say something. A rate revision that
            // nobody explained is exactly the entry an auditor cannot use;
            // min:5 is the cheapest possible bar against "ok" and ".".
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => 'Say why this rate is being recorded — the note is the audit trail.',
            'note.min' => 'Say why this rate is being recorded — the note is the audit trail.',
            'kind.in' => 'Choose invoice, landed cost or correction. The original receipt rate cannot be re-filed.',
        ];
    }
}
