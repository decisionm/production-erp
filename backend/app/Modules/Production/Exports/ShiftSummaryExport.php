<?php

namespace App\Modules\Production\Exports;

use App\Modules\Production\Http\Requests\ShiftSummaryReportRequest;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Services\ShiftSummaryService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The Shift KPI Summary as a file — GET /production/shift-summaries/report,
 * downloaded and flattened. The report answers for ONE scope at a time
 * (a shift, or the whole day when shift_id is omitted); the file lays the
 * scopes out as rows, every one of them the report's own answer for that
 * scope (ShiftSummaryService::report), never a sum taken here:
 *
 *   shift_id given   → one row: that shift's report (what the screen shows
 *                      with that shift picked);
 *   shift_id omitted → one row per shift with anything recorded on the
 *                      date (ShiftSummaryService::shiftsWithRecordsOn, in
 *                      the picker's order), then the DAY row — the
 *                      day-wide rollup the screen shows on "Whole Day".
 *
 * `scope` says which a row is (`shift` / `day` — the screen's own toggle),
 * `shift_id` / `shift` name the shift (blank on the day row). The columns
 * are the report's KPI keys — every figure the KPI cards and the
 * supervisor inputs show, none derived here. The report's per-scope TABLES
 * (items_manufactured, downtime_logs, mold_change_logs,
 * power_interruption_logs, stock_counts) are lists, not figures of the
 * row, and are not flattened into it: one row per shift cannot carry them
 * without inventing a multi-row layout, so they are left to their own
 * list endpoints / future kinds rather than guessed at.
 *
 * The filters are the endpoint's own — its rules are declared inline in
 * ShiftSummaryController::report (there is no FormRequest to delegate to),
 * so they are mirrored here rule for rule: production_date required,
 * shift_id optional and existing.
 */
class ShiftSummaryExport extends AbstractProductionExport
{
    public function __construct(private readonly ShiftSummaryService $summaries) {}

    public function key(): string
    {
        return 'shift_summary';
    }

    public function label(): string
    {
        return 'Shift KPI summary';
    }

    /** ShiftSummaryController::report's rules (see class docblock) — the date first, as a form reads. */
    /** The report endpoint's own grammar (ShiftSummaryReportRequest) — never a copy of it. */
    public function filterRules(): array
    {
        return (new ShiftSummaryReportRequest)->rules();
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'scope' => 'scope',
            'shift_id' => 'shift_id',
            'shift' => 'shift_name',
            'production_date' => 'production_date',
            'supervisor_code' => 'supervisor.employee_code',
            'supervisor' => 'supervisor.name',
            'target_production_kg' => 'target_production_kg',
            'actual_production_kg' => 'actual_production_kg',
            'rejection_kg' => 'rejection_kg',
            'net_good_output_kg' => 'net_good_output_kg',
            'efficiency_percent' => 'efficiency_percent',
            'rejection_percent' => 'rejection_percent',
            // The CURRENT-STATE counts under their honest names (Phase 7,
            // P7-03 (f)): the report's *_now keys — the date's batches still
            // in progress / breakdowns still open as of the download, not a
            // fact of the date (ShiftSummaryService, class docblock). The
            // file read the aliases (`machines_running` / `machines_down`)
            // until now; the aliases stay on the JSON this release for the
            // screen's fallback, the file names what it carries.
            'machines_running_now' => 'machines_running_now',
            'machines_down_now' => 'machines_down_now',
            'idle_time_hours' => 'idle_time_hours',
            'no_of_mold_changes' => 'no_of_mold_changes',
            'power_consumption_units' => 'power_consumption_units',
            'unit_per_kg' => 'unit_per_kg',
            'power_interruption_hours' => 'power_interruption_hours',
            'remarks' => 'remarks',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $date = (string) $filters['production_date'];
        $shiftId = isset($filters['shift_id']) ? (int) $filters['shift_id'] : null;

        if ($shiftId !== null) {
            yield $this->row('shift', Shift::withTrashed()->find($shiftId), $date);

            return;
        }

        foreach ($this->summaries->shiftsWithRecordsOn($date) as $shift) {
            yield $this->row('shift', $shift, $date);
        }

        yield $this->row('day', null, $date);
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        if (isset($filters['shift_id'])) {
            return 1;
        }

        return $this->summaries->shiftsWithRecordsOn((string) $filters['production_date'])->count() + 1;
    }

    /**
     * The report for one scope, as the endpoint returns it, plus the row's
     * scope and the shift's name (the report carries only shift_id).
     *
     * @return array<string, mixed>
     */
    private function row(string $scope, ?Shift $shift, string $date): array
    {
        return [
            'scope' => $scope,
            'shift_name' => $shift?->name,
        ] + $this->summaries->report($shift?->id, $date);
    }
}
