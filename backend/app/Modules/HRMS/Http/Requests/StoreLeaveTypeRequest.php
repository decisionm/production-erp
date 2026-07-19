<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', 'unique:leave_types,code'],
            'name' => ['required', 'string', 'max:255'],
            'default_annual_days' => ['required', 'numeric', 'min:0'],
        ];
    }
}
