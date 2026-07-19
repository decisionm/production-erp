<?php

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => [
                'required', 'integer', 'min:1', 'max:12',
                // Composite uniqueness (year, month) — one run per period.
                Rule::unique('payroll_runs')->where(
                    fn ($query) => $query->where('year', $this->input('year')),
                ),
            ],
        ];
    }
}
