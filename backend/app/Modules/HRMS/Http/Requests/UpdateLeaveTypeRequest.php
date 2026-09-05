<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $leaveType = $this->route('leave_type');

        return [
            'code' => ['sometimes', 'string', 'max:16', Rule::unique('leave_types', 'code')->ignore($leaveType)],
            'name' => ['sometimes', 'string', 'max:255'],
            'default_annual_days' => ['sometimes', 'numeric', 'min:0'],
            // Zero — the default — means this type does not accrue monthly.
            'monthly_accrual_days' => ['sometimes', 'numeric', 'min:0', 'max:31'],
            'is_active' => ['boolean'],
        ];
    }
}
