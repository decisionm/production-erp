<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Shift Summary report's ONE grammar — read by ShiftSummaryController::
 * report and by the shift_summary export kind (Phase 4.5), so a filter added
 * to the screen reaches the Downloads form by construction. `shift_id`
 * omitted means "every shift that ran this date" — the day-wide rollup.
 */
class ShiftSummaryReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'production_date' => ['required', 'date'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ];
    }
}
