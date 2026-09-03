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
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
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

    /** Lines answered per pass in a bulk sweep — a month is ~600 of them. */
    private const RESOLVE_CHUNK = 200;

    /**
     * WHAT A DAY IS WORTH, IN MINUTES ON THE CLOCK (DEC-20260903-005).
     *
     * The owner set two anchors: an eight-hour shift is a full day, four
     * hours is a half day. FULL_DAY_MINUTES is one hour under the shift
     * because a shift is worked, not clocked to the second: in July 2026,
     * 238 days ran between seven and eight hours and every one of them was
     * somebody's whole shift. Reading the punch app's own Full/Half label
     * instead would have paid 232 of those as halves.
     *
     * IMPLAUSIBLE_MINUTES is the other end. The app sometimes pairs an
     * in-punch with an out-punch from the wrong day and credits twenty-two
     * hours; a day longer than this is a data fault, not a long shift, and
     * is asked about rather than counted.
     */
    public const SHIFT_MINUTES = 480;

    public const FULL_DAY_MINUTES = 420;

    public const HALF_DAY_MINUTES = 240;

    public const IMPLAUSIBLE_MINUTES = 960;

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
    public static function classify(
        string $rawStatus,
        ?string $firstIn,
        ?string $lastOut,
        int $workedMinutes,
        bool $employeeKnown,
    ): array {
        if (! $employeeKnown) {
            return ['issue' => Issue::UnknownEmployee, 'resolution' => null];
        }

        $raw = strtolower(trim($rawStatus));
        $in = $firstIn !== null && trim($firstIn) !== '';
        $out = $lastOut !== null && trim($lastOut) !== '';
        $weekOff = in_array($raw, ['week off', 'weekoff', 'wo'], true);

        // A missing punch is asked about before anything else: without both
        // ends of the day there are no hours to judge.
        if ($in && ! $out) {
            return ['issue' => Issue::InNoOut, 'resolution' => null];
        }
        if (! $in && $out) {
            return ['issue' => Issue::OutNoIn, 'resolution' => null];
        }
        if (! $in && ! $out) {
            return $weekOff
                ? ['issue' => null, 'resolution' => Resolution::WeekOff]
                : ['issue' => Issue::NoPunch, 'resolution' => null];
        }

        // An in and an out at the SAME MINUTE is a broken pair, whatever
        // hours the app then credited against it. One such day in July
        // 2026 carried 7h30m against two identical punches.
        if (trim((string) $firstIn) === trim((string) $lastOut)) {
            return ['issue' => Issue::HoursUnclear, 'resolution' => null];
        }

        // Both punches are here, so the CLOCK decides — never the app's
        // Full/Half label (DEC-20260903-005).
        if ($workedMinutes >= self::FULL_DAY_MINUTES && $workedMinutes <= self::IMPLAUSIBLE_MINUTES) {
            return $weekOff
                ? ['issue' => Issue::WorkedOnWeekOff, 'resolution' => null]
                : ['issue' => null, 'resolution' => Resolution::Present];
        }

        if ($workedMinutes >= self::HALF_DAY_MINUTES && $workedMinutes <= self::IMPLAUSIBLE_MINUTES) {
            return $weekOff
                ? ['issue' => Issue::WorkedOnWeekOff, 'resolution' => null]
                : ['issue' => null, 'resolution' => Resolution::HalfDay];
        }

        // Under half a shift, or a length no shift can be. A week off with
        // a few minutes on it stays a week off: nobody worked it.
        if ($weekOff && $workedMinutes < self::HALF_DAY_MINUTES) {
            return ['issue' => null, 'resolution' => Resolution::WeekOff];
        }

        return ['issue' => Issue::HoursUnclear, 'resolution' => null];
    }

    // ---- runs --------------------------------------------------------------------

    /**
     * The runs, newest first. `q` matches the file name or the period as
     * typed — "2026-07" finds July's run.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = HrmsListQuery::PER_PAGE_DEFAULT, array $filters = []): LengthAwarePaginator
    {
        $query = $this->runsQuery();

        if (($term = $this->query->term($filters)) !== null) {
            $this->query->whereImportRunMatches($query, $term);
        }

        return $query
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

    /**
     * A run with its uploader and the review counts the screen's chips
     * show: open issues in all and by kind, answered issues, clean lines.
     * Seven correlated counts per row, on a list that grows by one a month.
     */
    private function runsQuery(): Builder
    {
        $counts = [
            'lines as open_count' => fn (Builder $lines) => $lines->whereNotNull('issue')->whereNull('resolution'),
            'lines as resolved_count' => fn (Builder $lines) => $lines->whereNotNull('issue')->whereNotNull('resolution'),
            'lines as clean_count' => fn (Builder $lines) => $lines->whereNull('issue'),
        ];
        foreach (Issue::cases() as $issue) {
            $counts["lines as {$issue->value}_count"] = fn (Builder $lines) => $lines->where('issue', $issue->value)->whereNull('resolution');
        }

        return AttendanceImport::query()->with('uploader')->withCount($counts);
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
                $workedMinutes = (int) ($day['worked_minutes'] ?? 0);
                $verdict = self::classify((string) $day['status'], $firstIn, $lastOut, $workedMinutes, $employeeId !== null);

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
                    'worked_minutes' => $workedMinutes,
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

    /**
     * THE REVIEW'S PERSON GRAIN: one row per employee in the run, with the
     * month beside them.
     *
     * Why this exists at all: the line list is 1,829 rows for a 59-person
     * July, the same person repeated thirty-one times, and it can never
     * answer "how is one person's month". HR reads a muster per person, so
     * the screen offers that shape and this builds it.
     *
     * TWO queries, not one per employee and not one big load: an aggregate
     * grouped by code (counted in SQL), then the day states for the CURRENT
     * PAGE's codes only. Search and paging are applied to the aggregate
     * before the days are fetched, so a thousand-person month costs about
     * what a fifty-person one costs.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateEmployees(AttendanceImport $import, int $perPage, array $filters = []): LengthAwarePaginator
    {
        $aggregates = AttendanceImportLine::query()
            ->where('attendance_import_id', $import->id)
            ->groupBy('employee_code')
            ->select('employee_code')
            ->selectRaw('MAX(employee_name) as employee_name')
            ->selectRaw('MAX(employee_id) as employee_id')
            ->selectRaw('COUNT(*) as day_count')
            ->selectRaw('SUM(CASE WHEN issue IS NOT NULL AND resolution IS NULL THEN 1 ELSE 0 END) as open_count')
            ->selectRaw('SUM(CASE WHEN issue IS NOT NULL AND resolution IS NOT NULL THEN 1 ELSE 0 END) as resolved_count')
            ->selectRaw('SUM(CASE WHEN issue IS NULL THEN 1 ELSE 0 END) as clean_count')
            ->get();

        if (($term = $this->query->term($filters)) !== null) {
            $needle = mb_strtolower($term);
            $aggregates = $aggregates->filter(
                fn ($row) => str_contains(mb_strtolower((string) $row->employee_code), $needle)
                    || str_contains(mb_strtolower((string) $row->employee_name), $needle)
            );
        }

        // The people with something to answer first — that is the work —
        // then in code order, so two loads never disagree.
        $aggregates = $aggregates->sortBy([['open_count', 'desc'], ['employee_code', 'asc']])->values();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = $aggregates->forPage($page, $perPage)->values();

        $employees = Employee::query()
            ->whereIn('id', $rows->pluck('employee_id')->filter()->all())
            ->get(['id', 'name', 'department', 'designation'])
            ->keyBy('id');

        $days = $this->dayStates($import, $rows->pluck('employee_code')->all());

        $data = $rows->map(function ($row) use ($employees, $days): array {
            $employee = $row->employee_id === null ? null : $employees->get($row->employee_id);

            return [
                'employee_code' => (string) $row->employee_code,
                // The MASTER's spelling wins over the punch file's, which
                // shouts every name in capitals.
                'employee_name' => $employee?->name ?? (string) $row->employee_name,
                'employee_id' => $row->employee_id === null ? null : (int) $row->employee_id,
                'known' => $row->employee_id !== null,
                'department' => $employee?->department,
                'designation' => $employee?->designation,
                'day_count' => (int) $row->day_count,
                'open_count' => (int) $row->open_count,
                'resolved_count' => (int) $row->resolved_count,
                'clean_count' => (int) $row->clean_count,
                'days' => $days[(string) $row->employee_code] ?? [],
            ];
        })->all();

        return new Paginator(
            $data,
            $aggregates->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * The month strip for the given people: one entry per day the report
     * carried, in date order. A day with no answer yet IS the thing to fix,
     * so it says so rather than borrowing the raw status and looking
     * decided.
     *
     * @param  list<string>  $codes
     * @return array<string, list<array{date: string, state: string}>>
     */
    private function dayStates(AttendanceImport $import, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $states = [];
        AttendanceImportLine::query()
            ->where('attendance_import_id', $import->id)
            ->whereIn('employee_code', $codes)
            ->orderBy('date')
            ->orderBy('id')
            ->get(['employee_code', 'date', 'resolution'])
            ->each(function (AttendanceImportLine $line) use (&$states): void {
                $states[$line->employee_code][] = [
                    'date' => $line->date->toDateString(),
                    'state' => $line->resolution?->value ?? 'needs_fix',
                ];
            });

        return $states;
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

        if (! empty($filters['employee_code'])) {
            $query->where('employee_code', $filters['employee_code']);
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

        return DB::transaction(fn () => $this->answer($line, $data, $user)->load(['employee', 'resolver']));
    }

    /**
     * ONE answer for ONE KIND of problem, across every day of the run that
     * still carries it. The same write as resolve() — the same fill, the
     * same AttendanceService::mark — applied in one transaction over the
     * lines a filter selects, never over a list of ids the client chose.
     *
     * TWO REFUSALS AND ONE SKIP, all of them deliberate:
     *   · An APPLIED run takes no further answer. The month has been written
     *     and closed; reopening it silently through a bulk button would put
     *     payroll figures behind a screen nobody was looking at.
     *   · A day ALREADY ANSWERED is never touched (`whereNull('resolution')`).
     *     Somebody decided that day; a bulk confirm is not a decision about
     *     it. This is what lets the reviewer answer the exceptions first and
     *     sweep the rest afterwards.
     *   · A line whose employee code is NOT IN THE MASTER is skipped and
     *     COUNTED, never guessed. Its codes come back so the screen can name
     *     them: the fix is to add the person, not to pick one.
     *
     * @param  array{issue: string, resolution: string, check_in?: ?string, check_out?: ?string, notes?: ?string}  $data
     * @return array{resolved: int, skipped: int, skipped_codes: list<string>}
     */
    public function resolveMany(AttendanceImport $import, array $data, Authenticatable $user): array
    {
        if ($import->status === AttendanceImportStatus::Applied) {
            throw ValidationException::withMessages([
                'issue' => 'This run has been applied. Corrections to an applied month are made on the Attendance screen.',
            ]);
        }

        $resolved = 0;
        $skipped = [];

        DB::transaction(function () use ($import, $data, $user, &$resolved, &$skipped) {
            $import->lines()
                ->where('issue', $data['issue'])
                ->whereNull('resolution')
                ->orderBy('id')
                ->chunkById(self::RESOLVE_CHUNK, function ($lines) use ($data, $user, &$resolved, &$skipped) {
                    foreach ($lines as $line) {
                        $this->relink($line);
                        if ($line->employee_id === null) {
                            $skipped[$line->employee_code] = true;
                            $line->save();

                            continue;
                        }

                        $this->answer($line, $data, $user);
                        $resolved++;
                    }
                });
        });

        $codes = array_keys($skipped);
        sort($codes);

        return ['resolved' => $resolved, 'skipped' => count($codes), 'skipped_codes' => $codes];
    }

    /**
     * RE-JUDGE A RUN under the current rule, for the days NOBODY HAS
     * ANSWERED.
     *
     * The July 2026 month was imported while the code read the punch app's
     * Full / Half label, so 245 days went in as half days that the clock
     * says were whole shifts. The rule changed (DEC-20260903-005) and the
     * month should not have to be uploaded again to benefit from it.
     *
     * A day a PERSON answered is never touched — `resolved_by` is the
     * mark of a human decision, and re-judging is not allowed to overrule
     * one. An APPLIED run is refused outright: those days are already in
     * `attendances`, and moving them belongs on the Attendance screen
     * where the change is visible, not behind a re-check button.
     *
     * @return array{changed: int, checked: int}
     */
    public function recheck(AttendanceImport $import): array
    {
        if ($import->status === AttendanceImportStatus::Applied) {
            throw ValidationException::withMessages([
                'status' => 'This run has been applied, so its days are already attendance. Change those on the Attendance screen.',
            ]);
        }

        $changed = 0;
        $checked = 0;

        DB::transaction(function () use ($import, &$changed, &$checked) {
            $import->lines()
                ->whereNull('resolved_by')
                ->orderBy('id')
                ->chunkById(self::RESOLVE_CHUNK, function ($lines) use (&$changed, &$checked) {
                    foreach ($lines as $line) {
                        $checked++;
                        $verdict = self::classify(
                            $line->raw_status,
                            $line->first_in,
                            $line->last_out,
                            (int) $line->worked_minutes,
                            $line->employee_id !== null,
                        );

                        $wasIssue = $line->issue?->value;
                        $wasResolution = $line->resolution?->value;
                        if ($wasIssue === $verdict['issue']?->value && $wasResolution === $verdict['resolution']?->value) {
                            continue;
                        }

                        $line->fill([
                            'issue' => $verdict['issue'],
                            'resolution' => $verdict['resolution'],
                            'resolved_check_in' => $verdict['resolution'] === null ? null : $line->first_in,
                            'resolved_check_out' => $verdict['resolution'] === null ? null : $line->last_out,
                        ])->save();
                        $changed++;
                    }
                });
        });

        return ['changed' => $changed, 'checked' => $checked];
    }

    /**
     * The write behind both resolve() and resolveMany(), without the
     * transaction so the bulk path can hold one for the whole sweep. A
     * time the caller did not give falls back to what the report printed.
     *
     * @param  array{resolution: string, check_in?: ?string, check_out?: ?string, notes?: ?string}  $data
     */
    private function answer(AttendanceImportLine $line, array $data, Authenticatable $user): AttendanceImportLine
    {
        $resolution = Resolution::from($data['resolution']);
        $timed = in_array($resolution, [Resolution::Present, Resolution::HalfDay], true);

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

        return $line;
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

        $verdict = self::classify($line->raw_status, $line->first_in, $line->last_out, (int) $line->worked_minutes, true);
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
