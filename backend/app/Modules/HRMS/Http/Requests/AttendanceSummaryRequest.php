<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /hrms/attendance/summary — the factory's attendance for one range,
 * by department. The screen's Today / Yesterday / Last week / Last month
 * buttons are just two dates by the time they arrive here.
 */
class AttendanceSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
