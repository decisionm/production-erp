<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /production/cec — the CEC data's grammar (Phase 5.7, P5.7-02): the
 * production date is required as a factory day (Y-m-d, the entries index's
 * own day format), `shift_id` optional — one shift when given, every shift
 * with records on the date plus the day block when omitted. An empty
 * `shift_id=` is the day-wide read, not a malformed one ('nullable').
 *
 * `date`, not `production_date`, on purpose: it is the CEC export slot's own
 * filter name (CecExport::filterRules), so the data endpoint and the file
 * slot read the same query the day the file exists.
 */
class CecReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ];
    }

    /** The factory day asked for, Y-m-d. */
    public function productionDate(): string
    {
        return (string) $this->validated('date');
    }

    /** The one shift asked for, or null for the day-wide read. */
    public function shiftId(): ?int
    {
        $shiftId = $this->validated('shift_id');

        return $shiftId === null ? null : (int) $shiftId;
    }
}
