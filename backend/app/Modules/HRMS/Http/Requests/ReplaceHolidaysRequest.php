<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A whole list at once — the factory's own calendar, uploaded.
 *
 * Bounded at 400 rows: a year cannot hold more days than it has, and a
 * body that claims to is not a calendar. Duplicated dates inside the same
 * upload are refused rather than silently letting the last one win.
 */
class ReplaceHolidaysRequest extends FormRequest
{
    public const MAX_HOLIDAYS = 400;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'holidays' => ['required', 'array', 'min:1', 'max:'.self::MAX_HOLIDAYS],
            'holidays.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'holidays.*.name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'holidays.*.date.distinct' => 'The same date is listed twice in this upload.',
        ];
    }
}
