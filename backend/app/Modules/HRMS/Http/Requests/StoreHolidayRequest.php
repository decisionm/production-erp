<?php

namespace App\Modules\HRMS\Http\Requests;

use App\Modules\HRMS\Models\Holiday;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One holiday. The DATE is the identity — a date either is a holiday or is
 * not — so a second one for the same day is refused here with a clean 422
 * rather than surfacing the unique index as a query exception.
 */
class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => [
                'required', 'date_format:Y-m-d',
                // whereDate, not Rule::unique: the column stores a datetime,
                // so an exact match on "2026-08-15" never finds the row and
                // the unique index surfaces as a 500 instead of a 422.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Holiday::query()->whereDate('date', $value)->exists()) {
                        $fail('That date is already a holiday.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
