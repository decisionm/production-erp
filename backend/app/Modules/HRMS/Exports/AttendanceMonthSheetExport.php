<?php

namespace App\Modules\HRMS\Exports;

use App\Modules\Core\Exports\AbstractExportKind;
use App\Modules\HRMS\Models\AttendanceImport;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Models\Enums\AttendanceImportResolution as Resolution;
use App\Modules\HRMS\Services\AttendanceImportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Enumerable;

/**
 * The payroll month sheet of one punch-report import: one row per
 * employee with the day counts, hours and minutes, then one column per
 * day of the period carrying the resolved status code — P present, H
 * half day, A absent, L on leave, W week off, blank where the line is
 * still open or the day is not in the file.
 *
 * Produced from the IMPORT LINES, never from `attendances`, so the file is
 * exactly what was reviewed on the screen — week off included, which
 * `attendances` cannot carry (Q34).
 *
 * The period decides the day columns, and columns() is asked without
 * filters — so the run is memoised on the filter (count() runs first in
 * ExportService::run, the documented pattern of the report kinds) and
 * columns() reads the period from it.
 */
class AttendanceMonthSheetExport extends AbstractExportKind
{
    public function __construct(private readonly AttendanceImportService $imports) {}

    public function key(): string
    {
        return 'attendance_month_sheet';
    }

    public function label(): string
    {
        return 'Attendance month sheet';
    }

    public function module(): string
    {
        return 'hrms';
    }

    public function permissionAny(): array
    {
        return ['hrms.view', 'hrms.manage'];
    }

    public function filterRules(): array
    {
        return [
            'attendance_import_id' => ['required', 'integer', 'exists:attendance_imports,id'],
        ];
    }

    public function columns(?Authenticatable $reader): array
    {
        $fixed = [
            'employee_code' => 'employee_code',
            'name' => 'name',
            'department' => 'department',
            'designation' => 'designation',
            'days_in_period' => 'days_in_period',
            'present' => 'present',
            'half_day' => 'half_day',
            'absent' => 'absent',
            'on_leave' => 'on_leave',
            'week_off' => 'week_off',
            'worked_hours' => 'worked_hours',
            'ot_hours' => 'ot_hours',
            'late_minutes' => 'late_minutes',
            'early_out_minutes' => 'early_out_minutes',
        ];

        $days = [];
        foreach ($this->days() as $day) {
            $days[$day] = "days.{$day}";
        }

        return $fixed + $days;
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $import = $this->import($filters);
        $days = $this->days();

        foreach ($this->imports->linesByEmployee($import) as $lines) {
            yield $this->row($lines, $days);
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->imports->employeeCount($this->import($filters));
    }

    /**
     * @param  Enumerable<int, AttendanceImportLine>  $lines  one employee's lines, in date order
     * @param  list<string>  $days
     * @return array<string, mixed>
     */
    private function row(Enumerable $lines, array $days): array
    {
        /** @var AttendanceImportLine $first */
        $first = $lines->first();
        $employee = $first->employee;

        $counts = array_fill_keys(array_map(fn (Resolution $r) => $r->value, Resolution::cases()), 0);
        $codes = array_fill_keys($days, '');
        $worked = $ot = $late = $early = 0;

        foreach ($lines as $line) {
            if ($line->resolution !== null) {
                $counts[$line->resolution->value]++;
                $codes[$line->date->toDateString()] = $line->resolution->sheetCode();
            }
            $worked += $line->worked_minutes;
            $ot += $line->ot_minutes;
            $late += $line->late_minutes;
            $early += $line->early_minutes;
        }

        return [
            'employee_code' => $first->employee_code,
            'name' => $employee?->name ?? $first->employee_name,
            'department' => $employee?->department,
            'designation' => $employee?->designation,
            'days_in_period' => count($days),
            'present' => $counts[Resolution::Present->value],
            'half_day' => $counts[Resolution::HalfDay->value],
            'absent' => $counts[Resolution::Absent->value],
            'on_leave' => $counts[Resolution::OnLeave->value],
            'week_off' => $counts[Resolution::WeekOff->value],
            'worked_hours' => self::hours($worked),
            'ot_hours' => self::hours($ot),
            'late_minutes' => $late,
            'early_out_minutes' => $early,
            'days' => $codes,
        ];
    }

    /** Minutes as decimal hours, two places, exact (bcmath) — never a float. */
    public static function hours(int $minutes): string
    {
        return bcdiv((string) $minutes, '60', 2);
    }

    /** @var array{id: int, import: AttendanceImport}|null the run's import, so count(), columns() and rows() read one */
    private ?array $memo = null;

    /** @param  array<string, mixed>  $filters */
    private function import(array $filters): AttendanceImport
    {
        $id = (int) $filters['attendance_import_id'];
        if ($this->memo === null || $this->memo['id'] !== $id) {
            $this->memo = ['id' => $id, 'import' => AttendanceImport::query()->findOrFail($id)];
        }

        return $this->memo['import'];
    }

    /** Every date of the memoised import's period, Y-m-d; none before count() has run. */
    private function days(): array
    {
        if ($this->memo === null) {
            return [];
        }

        $import = $this->memo['import'];
        $days = [];
        $day = CarbonImmutable::parse($import->period_from->toDateString());
        $last = CarbonImmutable::parse($import->period_to->toDateString());
        while ($day->lessThanOrEqualTo($last)) {
            $days[] = $day->toDateString();
            $day = $day->addDay();
        }

        return $days;
    }
}
