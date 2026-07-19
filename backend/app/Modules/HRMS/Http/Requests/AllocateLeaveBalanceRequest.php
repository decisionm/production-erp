<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AllocateLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'year' => [
                'required', 'integer', 'min:2000', 'max:2100',
                // Composite uniqueness (employee_id, leave_type_id, year) —
                // gives a clean 422 instead of surfacing the DB's unique
                // constraint violation as a raw query exception.
                Rule::unique('leave_balances')->where(
                    fn ($query) => $query
                        ->where('employee_id', $this->input('employee_id'))
                        ->where('leave_type_id', $this->input('leave_type_id')),
                ),
            ],
            'allocated_days' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
