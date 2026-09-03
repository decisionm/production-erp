<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Http\Requests\Rules\SelectableEmployee;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /hrms/attendance/person — one person's days over one range.
 *
 * The range is REQUIRED rather than defaulted. A screen that quietly picks
 * a month for you is a screen that shows a figure nobody chose, and this
 * one is read as "how has this person been"; the caller says which days.
 */
class AttendancePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', SelectableEmployee::rule()],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
