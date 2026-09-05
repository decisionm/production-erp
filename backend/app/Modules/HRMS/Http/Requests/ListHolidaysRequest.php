<?php

namespace App\Modules\HRMS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The calendar is read a YEAR at a time; the year is the date's own. */
class ListHolidaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
