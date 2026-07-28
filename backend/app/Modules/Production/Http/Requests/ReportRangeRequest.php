<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Services\ProductionReportService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Shared date-range contract for the ranged report endpoints: both bounds
 * required, ordered, and capped at MAX_RANGE_DAYS — the reports compute
 * per entry with no pagination, so an unbounded range must be a clear 422,
 * not a slow melt.
 */
abstract class ReportRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rangeRules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from', $this->maxRangeRule()],
        ];
    }

    private function maxRangeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $from = $this->input('date_from');

            // The `date` rules on both fields produce the friendly errors
            // for unparseable input — this rule only measures a real range.
            if (! is_string($from) || strtotime($from) === false || ! is_string($value) || strtotime($value) === false) {
                return;
            }

            if (Carbon::parse($from)->diffInDays(Carbon::parse($value), false) > ProductionReportService::MAX_RANGE_DAYS) {
                $fail(sprintf(
                    'The report range may span at most %d days — narrow date_from/date_to.',
                    ProductionReportService::MAX_RANGE_DAYS,
                ));
            }
        };
    }
}
