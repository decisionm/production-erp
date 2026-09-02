<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Models\AttendanceImport;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\AttendanceImportIssue as Issue;
use App\Modules\HRMS\Models\Enums\AttendanceImportResolution as Resolution;
use App\Modules\HRMS\Models\Enums\AttendanceImportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

/**
 * The Pooja punch-report import — Track 2 of the 03-Sep design.
 *
 * The browser parses the workbook and POSTs plain rows; this service keeps
 * them as `attendance_import_lines`, classifies each employee-day
 * (classify — the issue table, unit-tested), takes the reviewer's
 * corrections, and is the ONLY path from an import into `attendances`,
 * always through AttendanceService::mark so a re-import of the same month
 * updates the same rows rather than duplicating them.
 *
 * Times: the report prints IST wall-clock ("10:10 AM"); the lines keep them
 * as TIME columns exactly as printed. The instant written to
 * `attendances.check_in` / `check_out` is built from date + time in
 * config('tally-sync.factory_timezone') and stored in UTC, which is what
 * app.timezone is and must stay (CLAUDE.md).
 *
 * `week_off` never reaches `attendances`: it is not an AttendanceStatus
 * and adding one would touch payroll's day counting (Q34 open). The month
 * sheet reads it from the lines instead.
 */
class AttendanceImportService
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly HrmsListQuery $query,
        private readonly AttendanceService $attendance,
    ) {}

    // ---- the issue table ------------------------------------------------------

    /**
     * One employee-day → { issue, resolution }. Exactly one of the two is
     * set: a line with an issue waits for a person; a line without one is
     * already resolved to what the report said.
     *
     * @return array{issue: ?Issue, resolution: ?Resolution}
     */
    public static function classify(string $rawStatus, ?string $firstIn, ?string $lastOut, bool $employeeKnown): array
    {
        if (! $employeeKnown) {
            return ['issue' => Issue::UnknownEmployee, 'resolution' => null];
        }

        $raw = strtolower(trim($rawStatus));
        $in = $firstIn !== null && trim($firstIn) !== '';
        $out = $lastOut !== null && trim($lastOut) !== '';

        if (in_array($raw, ['week off', 'weekoff', 'wo'], true)) {
            return ['issue' => null, 'resolution' => Resolution::WeekOff];
        }
        if ($in && ! $out) {
            return ['issue' => Issue::InNoOut, 'resolution' => null];
        }
        if (! $in && $out) {
            return ['issue' => Issue::OutNoIn, 'resolution' => null];
        }
        if (! $in && ! $out) {
            return ['issue' => Issue::NoPunch, 'resolution' => null];
        }

        return ['issue' => null, 'resolution' => $raw === 'hd' ? Resolution::HalfDay : Resolution::Present];
    }

    // ---- runs --------------------------------------------------------------------

    /** The runs, newest first. */
    public function paginate(int $perPage = HrmsListQuery::PER_PAGE_DEFAULT): LengthAwarePaginator
    {
        return $this->runsQuery()
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): ?AttendanceImport
    {
        return $this->runsQuery()->find($id);
    }

    /** The run with its uploader and how many issue lines still wait. */
    public function fresh(AttendanceImport $import): AttendanceImport
    {
        return $this->runsQuery()->findOrFail($import->id);
    }

    private function runsQuery(): Builder
    {
        return AttendanceImport::query()
            ->with('uploader')
            ->withCount([
                'lines as open_count' => fn (Builder $lines) => $lines->whereNotNull('issue')->whereNull('resolution'),
            ]);
    }

    /**
     * One run from the parsed workbook. Every employee-day becomes a line,
     * classified against the employee master as it stands now; nothing is
     * written to `attendances` here — that is resolve() and apply().
     *
     * @param  array{period_from: string, period_to: string, source: string, file_name?: ?string, employees: list<array<string, mixed>>}  $data
     */
    public function create(array $data, Authenticatable $user): AttendanceImport
    {
        $codes = array_values(array_unique(array_map(
            fn (array $employee) => (string) $employee['employee_code'],
            $data['employees'],
        )));
        $known = Employee::query()->whereIn('employee_code', $codes)->pluck('id', 'employee_code');

        $now = now();
        $rows = [];
        $seen = [];
        foreach ($data['employees'] as $employee) {
            $code = (string) $employee['employee_code'];
            $employeeId = $known->get($code);

            foreach ($employee['days'] as $day) {
                $key = $code.'|'.$day['date'];
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages([
                        'employees' => "{$code} appears twice for {$day['date']}.",
                    ]);
                }
                $seen[$key] = true;

                $firstIn = $this->time($day['first_in'] ?? null);
                $lastOut = $this->time($day['last_out'] ?? null);
                $verdict = self::classify((string) $day['status'], $firstIn, $lastOut, $employeeId !== null);

                $rows[] = [
                    'employee_id' => $employeeId,
                    'employee_code' => $code,
                    'employee_name' => (string) $employee['name'],
                    'date' => $day['date'],
                    'raw_status' => (string) $day['status'],
                    'first_in' => $firstIn,
                    'last_out' => $lastOut,
                    'ot_minutes' => (int) ($day['ot_minutes'] ?? 0),
                    'late_minutes' => (int) ($day['late_minutes'] ?? 0),
                    'early_minutes' => (int) ($day['early_minutes'] ?? 0),
                    'worked_minutes' => (int) ($day['worked_minutes'] ?? 0),
                    'issue' => $verdict['issue']?->value,
                    'resolution' => $verdict['resolution']?->value,
                    'resolved_check_in' => $verdict['resolution'] === null ? null : $firstIn,
                    'resolved_check_out' => $verdict['resolution'] === null ? null : $lastOut,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return DB::transaction(function () use ($data, $user, $codes, $rows) {
            $import = AttendanceImport::create([
                'source' => $data['source'],
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'file_name' => $data['file_name'] ?? null,
                'uploaded_by' => $user->getAuthIdentifier(),
                'status' => AttendanceImportStatus::Review,
                'employee_count' => count($codes),
                'day_count' => count($rows),
                'issue_count' => count(array_filter($rows, fn (array $row) => $row['issue'] !== null)),
            ]);

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                AttendanceImportLine::insert(array_map(
                    fn (array $row) => ['attendance_import_id' => $import->id, ...$row],
                    $chunk,
                ));
            }

            return $this->fresh($import);
        });
    }

    // ---- lines ------------------------------------------------------------------

    /**
     * The review list. `issue` narrows to what still needs a person
     * (`open`), one kind of open issue, what has been answered
     * (`resolved`), or what never needed anyone (`clean`); `q` matches the
     * employee code or name AS THE REPORT PRINTED THEM — an unknown
     * employee has no master row to search through. Open issues first,
     * then answered ones, then clean lines; within each, by employee then
     * date, id breaking ties.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateLines(AttendanceImport $import, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return $this->linesQuery($import, $filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param  array<string, mixed>  $filters */
    private function linesQuery(AttendanceImport $import, array $filters): Builder
    {
        $query = AttendanceImportLine::query()
            ->with(['employee', 'resolver'])
            ->where('attendance_import_id', $import->id);

        if (($term = $this->query->term($filters)) !== null) {
            $this->query->whereImportLineMatches($query, $term);
        }

        $this->applyIssueFilter($query, $filters['issue'] ?? null);

        return $query
            ->orderByRaw('CASE WHEN issue IS NOT NULL AND resolution IS NULL THEN 0 WHEN issue IS NOT NULL THEN 1 ELSE 2 END')
            ->orderBy('employee_code')
            ->orderBy('date')
            ->orderBy('id');
    }

    private function applyIssueFilter(Builder $query, ?string $issue): void
    {
        match ($issue) {
            null, '' => null,
            'open' => $query->whereNotNull('issue')->whereNull('resolution'),
            'resolved' => $query->whereNotNull('issue')->whereNotNull('resolution'),
            'clean' => $query->whereNull('issue'),
            default => $query->where('issue', $issue)->whereNull('resolution'),
        };
    }

    /**
     * The reviewer's answer for one line, written to the line AND to
     * `attendances` at once (through AttendanceService::mark, an upsert on
     * employee + date). An unknown-employee line is re-linked first and
     * refused while the code is still not in the master.
     *
     * @param  array{resolution: string, check_in?: ?string, check_out?: ?string, notes?: ?string}  $data
     */
    public function resolve(AttendanceImportLine $line, array $data, Authenticatable $user): AttendanceImportLine
    {
        $this->relink($line);
        if ($line->employee_id === null) {
            throw ValidationException::withMessages([
                'resolution' => "{$line->employee_code} is not in the employee master. Add the employee first.",
            ]);
        }

        $resolution = Resolution::from($data['resolution']);
        $timed = in_array($resolution, [Resolution::Present, Resolution::HalfDay], true);

        return DB::transaction(function () use ($line, $data, $user, $resolution, $timed) {
            $line->fill([
                'resolution' => $resolution,
                'resolved_check_in' => $timed ? ($this->time($data['check_in'] ?? null) ?? $line->first_in) : null,
                'resolved_check_out' => $timed ? ($this->time($data['check_out'] ?? null) ?? $line->last_out) : null,
                'notes' => $data['notes'] ?? $line->notes,
                'resolved_by' => $user->getAuthIdentifier(),
                'resolved_at' => now(),
            ]);

            $this->writeAttendance($line);
            $line->save();

            return $line->load(['employee', 'resolver']);
        });
    }

    /**
     * Everything the reviewer has not been asked about, into `attendances`:
     * every line that never had an issue and every answered one. Refused
     * with the count while an open issue line remains — a half-applied
     * month is worse than an unapplied one. Unknown-employee lines are
     * re-linked first, so adding the missing employee and pressing Apply
     * is enough when the punches themselves were clean.
     */
    public function apply(AttendanceImport $import, Authenticatable $user): AttendanceImport
    {
        return DB::transaction(function () use ($import) {
            $import->lines()
                ->where('issue', Issue::UnknownEmployee->value)
                ->whereNull('resolution')
                ->each(fn (AttendanceImportLine $line) => $this->relink($line)->save());

            $open = $import->lines()->whereNotNull('issue')->whereNull('resolution')->count();
            if ($open > 0) {
                throw ValidationException::withMessages([
                    'lines' => "{$open} ".($open === 1 ? 'line still needs' : 'lines still need').' a correction.',
                ]);
            }

            $import->lines()
                ->whereNull('applied_at')
                ->orderBy('id')
                ->each(function (AttendanceImportLine $line) {
                    $this->writeAttendance($line);
                    $line->save();
                });

            $import->update([
                'status' => AttendanceImportStatus::Applied,
                'applied_at' => now(),
            ]);

            return $this->fresh($import);
        });
    }

    /**
     * The lines of one run, one employee at a time in code order, for the
     * month sheet — the file is produced from what was reviewed, never
     * from `attendances`.
     *
     * @return LazyCollection<int, LazyCollection<int, AttendanceImportLine>>
     */
    public function linesByEmployee(AttendanceImport $import): LazyCollection
    {
        return $import->lines()
            ->with('employee')
            ->orderBy('employee_code')
            ->orderBy('date')
            ->orderBy('id')
            ->lazy(self::INSERT_CHUNK)
            ->chunkWhile(fn (AttendanceImportLine $line, int $key, $chunk) => $line->employee_code === $chunk->last()->employee_code);
    }

    public function employeeCount(AttendanceImport $import): int
    {
        return $import->lines()->distinct()->count('employee_code');
    }

    // ---- the pieces --------------------------------------------------------------

    /**
     * A line whose employee was unknown when the report came in, looked up
     * again now: if the code has since been added, the line is linked and
     * classified on its punches as any other line would have been.
     */
    private function relink(AttendanceImportLine $line): AttendanceImportLine
    {
        if ($line->issue !== Issue::UnknownEmployee || $line->employee_id !== null) {
            return $line;
        }

        $employee = Employee::query()->where('employee_code', $line->employee_code)->first();
        if ($employee === null) {
            return $line;
        }

        $verdict = self::classify($line->raw_status, $line->first_in, $line->last_out, true);
        $line->fill([
            'employee_id' => $employee->id,
            'issue' => $verdict['issue'],
            'resolution' => $verdict['resolution'],
            'resolved_check_in' => $verdict['resolution'] === null ? null : $line->first_in,
            'resolved_check_out' => $verdict['resolution'] === null ? null : $line->last_out,
        ]);

        return $line;
    }

    /**
     * The line's resolution into `attendances` — or nothing, for a week
     * off — and the line stamped applied. The caller saves the line.
     */
    private function writeAttendance(AttendanceImportLine $line): void
    {
        $resolution = $line->resolution;
        if ($resolution === null || $line->employee_id === null) {
            return;
        }

        $status = $resolution->attendanceStatus();
        if ($status !== null) {
            $date = $line->date->toDateString();
            $this->attendance->mark([
                'employee_id' => $line->employee_id,
                'date' => $date,
                'status' => $status->value,
                'check_in' => $this->instant($date, $line->resolved_check_in),
                'check_out' => $this->instant($date, $line->resolved_check_out),
                'notes' => $line->notes,
            ]);
        }

        $line->applied_at = now();
    }

    /** "10:10" / "10:10:00" / "10:10 AM" → "10:10:00"; blank or "-" → null. */
    private function time(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }

        return CarbonImmutable::parse($value)->format('H:i:s');
    }

    /** A factory-day wall-clock time as the UTC instant `attendances` stores. */
    private function instant(string $date, ?string $time): ?CarbonImmutable
    {
        if ($time === null) {
            return null;
        }

        return CarbonImmutable::parse("{$date} {$time}", config('tally-sync.factory_timezone'))->utc();
    }
}
