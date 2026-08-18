<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:2'],
            // WS-B: a deactivated account takes no NEW posting. Entries
            // already posted against it are untouched and still read back.
            'lines.*.gl_account_id' => ['required', 'integer', Rule::exists('gl_accounts', 'id')->where('is_active', true)],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('lines', []) as $index => $line) {
                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);

                if (($debit > 0) === ($credit > 0)) {
                    $validator->errors()->add(
                        "lines.{$index}",
                        'Each line must have either a debit or a credit amount, not both or neither.',
                    );
                }
            }
        });
    }
}
