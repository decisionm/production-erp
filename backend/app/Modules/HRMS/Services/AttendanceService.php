<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Http\Requests\ListAttendanceRequest;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\AttendanceImport;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\AttendanceImportResolution;
use App\Modules\HRMS\Models\Enums\AttendanceImportStatus;
use App\Modules\HRMS\Models\Enums\AttendanceStatus;
use App\Support\Lists\ListSort;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /** What an employee with no department is called on the summary. */
    private const NO_DEPARTMENT = 'No department';

    /**
     * A LONG DAY is two hours past the shift, a VERY LONG one four.
     *
     * Deliberately measured on the clock rather than read off the punch
     * app's "Total OT" column: that column is the app's arithmetic against
     * the shift window it was configured with, and that window is the one
     * which called 232 full shifts half days in July 2026. The clock is
     * what the factory agreed to trust (DEC-20260903-005).
     */
    private const LONG_DAY_MINUTES = 600;

    private const VERY_LONG_DAY_MINUTES = 720;

    public function __construct(private readonly HrmsListQuery $query) {}

    /**
     * The list page's read. Every filter is ListAttendanceRequest's — `q`
     * THROUGH the employee (code, name, department, designation), `status`
     * and `employee_id` exact, `from`/`to` inclusive on the attendance date.
     * Ordered by `sort` (ListSort), newest date first as it always was when
     * absent, with id breaking ties so a day with thirty marks reads in one
     * order on every load.
     *
     * @param  array<string, mixed>  $filters
     */
    /**
     * EVERY MARK IN THE LIST, applied or merely uploaded.
     *
     * This list used to page `attendances` alone, which meant it was empty
     * for a month under review — the same blankness the person and
     * department reads had, in the one table an office actually scrolls.
     * So it pages the SAME UNION those reads use: an applied day, or the
     * uploaded day the applied record does not cover.
     *
     * A day nobody has answered yet appears with NO STATUS rather than
     * being left out. It is a day the office still owes an answer for, and
     * a list that hides it is how 659 of them went unnoticed.
     */
    public function paginate(int $perPage = HrmsListQuery::PER_PAGE_DEFAULT, array $filters = []): LengthAwarePaginator
    {
        $query = DB::query()
            ->fromSub($this->everyDay(), 'd')
            ->leftJoin('employees as e', 'e.id', '=', 'd.employee_id')
            ->select('d.*', 'e.name as employee_name');

        // The employee grammar is Eloquent's and is shared with the other
        // HRMS lists — so the people are matched THERE and their days are
        // asked for by id, rather than a second spelling of "matches an
        // employee" growing here.
        if (($term = $this->query->term($filters)) !== null) {
            $query->whereIn('d.employee_id', Employee::query()
                ->tap(fn (Builder $employees) => $this->query->whereEmployeeMatches($employees, $term))
                ->pluck('id'));
        }

        if (! empty($filters['status'])) {
            $query->where('d.status', $filters['status']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('d.employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('d.date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('d.date', '<=', $filters['to']);
        }

        $this->orderDays($query, $filters['sort'] ?? null);

        return $query->paginate($perPage)
            ->withQueryString()
            ->through(fn (object $row) => $this->listedDay($row));
    }

    /**
     * The union behind the list: applied days, then the uploaded days no
     * applied row covers. Only the NEWEST line for a day survives — a month
     * re-uploaded as it goes carries the same day more than once, and the
     * person read has always taken the newest.
     */
    private function everyDay(): QueryBuilder
    {
        $applied = DB::table('attendances as a')
            ->selectRaw('a.id as id, a.employee_id as employee_id, a.date as date, a.status as status')
            // CAST, because the two halves of this column are not the same
            // kind of thing: an APPLIED day holds a datetime and an uploaded
            // one holds the report's wall clock. MySQL resolves a union
            // column to ONE type and quietly reads "06:00:00" as
            // "0000-00-00 06:00:00" when it picks datetime — which SQLite
            // does not do, so it passed every local test and failed only on
            // CI. Both sides arrive as text and listedDay() reads each by
            // its source.
            ->selectRaw('CAST(a.check_in AS CHAR) as check_in')
            ->selectRaw('CAST(a.check_out AS CHAR) as check_out')
            ->selectRaw('a.notes as notes')
            ->selectRaw("'attendance' as source, 0 as needs_review, 0 as provisional");

        $uploaded = DB::table('attendance_import_lines as l')
            ->join('attendance_imports as i', 'i.id', '=', 'l.attendance_import_id')
            ->whereNotNull('l.employee_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('attendances as a')
                ->whereColumn('a.employee_id', 'l.employee_id')
                ->whereColumn('a.date', 'l.date'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('attendance_import_lines as l2')
                ->whereColumn('l2.employee_id', 'l.employee_id')
                ->whereColumn('l2.date', 'l.date')
                ->whereColumn('l2.id', '>', 'l.id'))
            ->selectRaw('null as id, l.employee_id as employee_id, l.date as date, l.resolution as status')
            ->selectRaw('CAST(COALESCE(l.resolved_check_in, l.first_in) AS CHAR) as check_in')
            ->selectRaw('CAST(COALESCE(l.resolved_check_out, l.last_out) AS CHAR) as check_out')
            ->selectRaw('l.notes as notes')
            ->selectRaw("'import' as source")
            ->selectRaw('CASE WHEN l.resolution IS NULL THEN 1 ELSE 0 END as needs_review')
            ->selectRaw('CASE WHEN i.status = ? THEN 0 ELSE 1 END as provisional', [AttendanceImportStatus::Applied->value]);

        return $applied->unionAll($uploaded);
    }

    /**
     * The list's order. ListSort is Eloquent's and this query is not one —
     * but the URL grammar it defines is the app's, so the same `sort` values
     * mean the same thing here, and the tiebreak is spelled out because a
     * union has no id to fall back on.
     */
    private function orderDays(QueryBuilder $query, ?string $sort): void
    {
        $sort = ($sort === null || trim($sort) === '') ? '-date' : trim($sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, ListAttendanceRequest::SORTABLE, true)) {
            $column = 'date';
            $direction = 'desc';
        }

        // The list's long-standing tiebreak is the later mark first, so two
        // loads of a page never swap rows. An uploaded day has no id and
        // therefore sorts after the applied ones on its date — which is the
        // right way round: the record before the provisional reading of it.
        $query->orderBy("d.{$column}", $direction)
            ->orderByDesc('d.id')
            ->orderBy('d.employee_id')
            ->orderBy('d.source');
    }

    /**
     * One listed day in the shape the page has always been given, plus
     * where it came from. The clock is normalised HERE because the two
     * halves store it differently: `attendances` holds an instant in UTC,
     * an uploaded line holds the report's own wall clock.
     *
     * @return array<string, mixed>
     */
    private function listedDay(object $row): array
    {
        $date = CarbonImmutable::parse($row->date)->toDateString();
        $fromImport = $row->source === 'import';

        return [
            'id' => $row->id === null ? null : (int) $row->id,
            // A union row has no id when it comes from an upload, so the
            // page cannot key on one — this is what it keys on instead.
            'key' => "{$row->source}-{$row->employee_id}-{$date}",
            'employee' => [
                'id' => (int) $row->employee_id,
                'name' => $row->employee_name,
            ],
            'date' => $date,
            'status' => $row->status,
            'check_in' => $fromImport ? $this->instant($date, $row->check_in) : $this->utc($row->check_in),
            'check_out' => $fromImport ? $this->instant($date, $row->check_out) : $this->utc($row->check_out),
            'notes' => $row->notes,
            'source' => $row->source,
            'needs_review' => (bool) $row->needs_review,
            'provisional' => (bool) $row->provisional,
        ];
    }

    /** A stored instant, as the API has always spelled it. */
    private function utc(?string $stored): ?string
    {
        return $stored === null ? null : CarbonImmutable::parse($stored)->toIso8601String();
    }

    /**
     * MY OWN RANGE — the same read as one person's, or an EMPTY MONTH when
     * the login has no employee row behind it.
     *
     * Empty rather than an error, and empty rather than a guess: a user
     * account is not proof of a person on the factory's staff, and matching
     * a login to an employee by name would be exactly the kind of guess
     * that puts somebody else's attendance on your screen.
     *
     * @return array{employee: array<string, mixed>|null, from: string, to: string, days: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function mine(?Employee $employee, string $from, string $to): array
    {
        if ($employee === null) {
            return [
                'employee' => null,
                'from' => $from,
                'to' => $to,
                'days' => [],
                'summary' => $this->tally([]),
            ];
        }

        return $this->personRange($employee, $from, $to);
    }

    /**
     * ONE PERSON'S RANGE: who they are, every day recorded in it, and what
     * the range came to.
     *
     * `recorded` is deliberately not "days in the range". Attendance exists
     * only for a day somebody imported or marked, so a month half way
     * through has half a month of rows — and calling the remainder absent
     * would invent absences nobody recorded.
     *
     * @return array{employee: array<string, mixed>, from: string, to: string, days: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function personRange(Employee $employee, string $from, string $to): array
    {
        $rows = [];

        foreach (
            Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('date', [$from, $to])
                ->get() as $day
        ) {
            $rows[$day->date->toDateString()] = [
                'id' => $day->id,
                'date' => $day->date->toDateString(),
                'status' => $day->status->value,
                'check_in' => $day->check_in?->toIso8601String(),
                'check_out' => $day->check_out?->toIso8601String(),
                'notes' => $day->notes,
                'source' => 'attendance',
                'needs_review' => false,
                'provisional' => false,
            ];
        }

        // Whatever the applied record does not cover, the UPLOAD may.
        foreach ($this->uploadedDays($employee->id, $from, $to) as $line) {
            $date = $line->date->toDateString();
            $resolution = $line->resolution?->value;

            $rows[$date] = [
                // PROVISIONAL means the run behind this day is not applied
                // yet — not merely that the day came from an upload. An
                // APPLIED month still answers its week offs from the
                // upload, because applying deliberately writes no row for
                // them, and calling those provisional would tell the office
                // a finished month is unfinished.
                'id' => null,
                'date' => $date,
                'status' => $resolution,
                'check_in' => $this->instant($date, $line->resolved_check_in ?? $line->first_in),
                'check_out' => $this->instant($date, $line->resolved_check_out ?? $line->last_out),
                'notes' => $line->notes,
                'source' => 'import',
                // Not present, not absent, not anything yet — and the
                // software does not get to pick on the reviewer's behalf.
                'needs_review' => $resolution === null,
                'provisional' => $line->import?->status !== AttendanceImportStatus::Applied,
            ];
        }

        ksort($rows);
        $days = array_values($rows);

        return [
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->name,
                'department' => $employee->department,
                'designation' => $employee->designation,
            ],
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'summary' => $this->tally(
                array_count_values(array_filter(array_column($days, 'status'))),
                needsReview: count(array_filter($days, static fn (array $day) => $day['needs_review'])),
                fromImport: count(array_filter($days, static fn (array $day) => ($day['provisional'] ?? false) === true)),
                mismatches: $this->mismatchesFor($employee->id, $from, $to),
            ),
        ];
    }

    /**
     * The uploaded days for one person that the APPLIED RECORD DOES NOT
     * COVER — per day, not per period.
     *
     * Per day matters for two reasons. A month is reviewed over days, so
     * half of it can be applied while the rest is not. And `attendances`
     * has no week-off status, so even a fully applied month has no row for
     * those days and the upload stays the only place that knows.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AttendanceImportLine>
     */
    private function uploadedDays(int $employeeId, string $from, string $to)
    {
        return AttendanceImportLine::query()
            ->with('import:id,status')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from, $to])
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('attendances')
                ->whereColumn('attendances.employee_id', 'attendance_import_lines.employee_id')
                ->whereColumn('attendances.date', 'attendance_import_lines.date'))
            // The newest upload of a day wins: a month re-uploaded as it goes
            // carries the same day more than once.
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * A date and the report's wall-clock time as a stored instant, so an
     * uploaded day and an applied one read identically to the caller.
     * `attendances` holds UTC (app.timezone), and the report prints IST.
     */
    private function instant(string $date, ?string $wallClock): ?string
    {
        if ($wallClock === null || trim($wallClock) === '') {
            return null;
        }

        // "06:00", "06:00:00" or a whole datetime, from a model or from the
        // union's cast column — take the clock and nothing else. A date
        // arriving here glued to a date is what turned a punch time into
        // "0000-00-00 06:00:00" on MySQL once already.
        $clock = str_contains($wallClock, ' ') ? substr($wallClock, strpos($wallClock, ' ') + 1) : $wallClock;

        return CarbonImmutable::parse($date.' '.substr($clock, 0, 5), config('tally-sync.factory_timezone', 'Asia/Kolkata'))
            ->utc()
            ->toIso8601String();
    }

    /**
     * ONE PERSON'S MONTH, LAID OUT FOR PAPER.
     *
     * Two things separate this from personRange():
     *
     * EVERY DAY OF THE RANGE APPEARS, recorded or not. On a screen a gap is
     * a row that is not there; on a sheet somebody is paid against, a
     * missing day is exactly what they would query, so it is printed and
     * marked "not recorded" rather than left out — and never called absent,
     * which would be asserting something nobody recorded.
     *
     * THE CLOCK IS SHOWN IN THE FACTORY'S OWN TIME. `attendances` stores an
     * instant in UTC (app.timezone is UTC and must stay so); a sheet handed
     * to somebody on the floor has to read in IST or every time on it is
     * five and a half hours wrong.
     *
     * @return array<string, mixed>
     */
    public function monthSheet(Employee $employee, string $from, string $to): array
    {
        $zone = config('tally-sync.factory_timezone', 'Asia/Kolkata');
        $range = $this->personRange($employee, $from, $to);

        $recorded = [];
        foreach ($range['days'] as $day) {
            $recorded[$day['date']] = $day;
        }

        $days = [];
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $day = $recorded[$date] ?? null;

            $days[] = [
                'date' => $date,
                'label' => $cursor->format('D j'),
                'is_sunday' => $cursor->dayOfWeek === 0,
                'status' => $day['status'] ?? null,
                'check_in' => $this->wallClock($day['check_in'] ?? null, $zone),
                'check_out' => $this->wallClock($day['check_out'] ?? null, $zone),
                'notes' => $day['notes'] ?? null,
                // Three different silences, and the sheet must not print
                // them alike: no data at all, a day the reviewer has not
                // answered, and a day that IS answered but not yet applied.
                'present_in_data' => $day !== null,
                'needs_review' => (bool) ($day['needs_review'] ?? false),
            ];
            $cursor = $cursor->addDay();
        }

        return [
            'employee' => $range['employee'],
            'from' => $from,
            'to' => $to,
            'from_label' => CarbonImmutable::parse($from)->format('j M Y'),
            'to_label' => CarbonImmutable::parse($to)->format('j M Y'),
            'days' => $days,
            'summary' => $range['summary'],
            'printed_at' => now($zone)->format('j M Y H:i'),
            // A sheet printed from an upload nobody has applied is
            // PROVISIONAL, and has to say so on the page — otherwise it
            // circulates as the final word on somebody's month.
            'provisional' => $range['summary']['from_import'] > 0,
        ];
    }

    /** A stored UTC instant as the factory's own wall clock, or null. */
    private function wallClock(?string $instant, string $zone): ?string
    {
        return $instant === null ? null : CarbonImmutable::parse($instant)->setTimezone($zone)->format('H:i');
    }

    /**
     * THE FACTORY'S ATTENDANCE FOR A RANGE, BY DEPARTMENT — the management
     * read, with the people carrying the most absence named beneath it.
     *
     * Grouped queries and the pivot in PHP, rather than one clever
     * statement: this runs on SQLite in tests, MySQL 8 in CI and MariaDB on
     * the floor, and the SQL all three agree on is the plain kind. Nothing
     * here is vendor-specific.
     *
     * @return array{from: string, to: string, departments: list<array<string, mixed>>, totals: array<string, mixed>, most_absent: list<array<string, mixed>>}
     */
    public function departmentSummary(string $from, string $to): array
    {
        $rows = DB::table('attendances')
            ->join('employees', 'employees.id', '=', 'attendances.employee_id')
            ->whereBetween('attendances.date', [$from, $to])
            ->groupBy('employees.department', 'attendances.status')
            ->selectRaw('employees.department as department')
            ->selectRaw('attendances.status as status')
            ->selectRaw('COUNT(*) as days')
            ->get();

        /** @var array<string, array<string, int>> $counted */
        $counted = [];
        $factory = [];
        $needsReview = [];
        $fromImport = [];
        $uploaded = [];
        foreach ($rows as $row) {
            $name = $this->departmentName($row->department);
            $status = (string) $row->status;
            $days = (int) $row->days;

            $counted[$name][$status] = ($counted[$name][$status] ?? 0) + $days;
            $factory[$status] = ($factory[$status] ?? 0) + $days;
        }

        // The same fallback the person read uses, in aggregate: days the
        // applied record does not cover, taken from the upload. A line with
        // NO EMPLOYEE is skipped — it belongs to no department, and guessing
        // one would put somebody's day under a heading nobody chose.
        foreach ($this->uploadedDaysByDepartment($from, $to) as $row) {
            $name = $this->departmentName($row->department);
            $days = (int) $row->days;
            $uploaded[$name] = ($uploaded[$name] ?? 0) + $days;

            // Only a run nobody has applied makes its days provisional.
            if ($row->import_status !== AttendanceImportStatus::Applied->value) {
                $fromImport[$name] = ($fromImport[$name] ?? 0) + $days;
            }

            if ($row->resolution === null) {
                $needsReview[$name] = ($needsReview[$name] ?? 0) + $days;

                continue;
            }

            $status = (string) $row->resolution;
            $counted[$name][$status] = ($counted[$name][$status] ?? 0) + $days;
            $factory[$status] = ($factory[$status] ?? 0) + $days;
        }

        // A department may exist only in the upload.
        foreach (array_keys($uploaded) as $name) {
            $counted[$name] ??= [];
        }

        // A head-count cannot be added up from the rows above — one person
        // appears under several statuses — so it is asked for separately.
        $headcount = [];
        $people = DB::table('attendances')
            ->join('employees', 'employees.id', '=', 'attendances.employee_id')
            ->whereBetween('attendances.date', [$from, $to])
            ->groupBy('employees.department')
            ->selectRaw('employees.department as department')
            ->selectRaw('COUNT(DISTINCT attendances.employee_id) as people')
            ->get();
        foreach ($people as $row) {
            $name = $this->departmentName($row->department);
            $headcount[$name] = ($headcount[$name] ?? 0) + (int) $row->people;
        }

        $mismatches = [];
        foreach ($this->mismatchesByDepartment($from, $to) as $row) {
            $name = $this->departmentName($row->department);
            $mismatches[$name] = ($mismatches[$name] ?? 0) + (int) $row->days;
            $counted[$name] ??= [];
        }

        $departments = [];
        foreach ($counted as $name => $statuses) {
            $tally = $this->tally($statuses, $needsReview[$name] ?? 0, $fromImport[$name] ?? 0, $mismatches[$name] ?? 0);
            $departments[] = [
                'department' => $name,
                ...$tally,
                'employees' => $headcount[$name] ?? 0,
                'present_percent' => $this->presentPercent($tally),
            ];
        }

        // The busiest department first — that is where the reader looks —
        // with the name breaking ties so two loads never disagree.
        usort(
            $departments,
            static fn (array $a, array $b) => [$b['recorded'], $a['department']] <=> [$a['recorded'], $b['department']],
        );

        $factoryTally = $this->tally($factory, array_sum($needsReview), array_sum($fromImport), array_sum($mismatches));

        return [
            'from' => $from,
            'to' => $to,
            'departments' => $departments,
            'totals' => [
                ...$factoryTally,
                'employees' => $this->peopleInRange($from, $to),
                'departments' => count($departments),
                'present_percent' => $this->presentPercent($factoryTally),
            ],
            // Which uploads these numbers are partly read from, and whether
            // anybody has applied them — so a screen can say "provisional"
            // rather than leaving the reader to assume.
            'imports' => $this->importsCovering($from, $to),
            'most_absent' => $this->mostAbsent($from, $to),
        ];
    }

    /**
     * The uploaded days the applied record does not cover, grouped by
     * department and by what the reviewer answered — `resolution` null
     * being the days still waiting for one.
     *
     * A line with NO EMPLOYEE is left out: it belongs to no department, and
     * filing it under a guessed one would put somebody's day beneath a
     * heading nobody chose.
     */
    private function uploadedDaysByDepartment(string $from, string $to): Collection
    {
        return DB::table('attendance_import_lines as l')
            ->join('employees', 'employees.id', '=', 'l.employee_id')
            ->join('attendance_imports as i', 'i.id', '=', 'l.attendance_import_id')
            ->whereBetween('l.date', [$from, $to])
            ->whereNotNull('l.employee_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('attendances as a')
                ->whereColumn('a.employee_id', 'l.employee_id')
                ->whereColumn('a.date', 'l.date'))
            ->groupBy('employees.department', 'l.resolution', 'i.status')
            ->selectRaw('employees.department as department')
            ->selectRaw('l.resolution as resolution')
            ->selectRaw('i.status as import_status')
            ->selectRaw('COUNT(*) as days')
            ->get();
    }

    /**
     * The days in the range the punch report could not make sense of, for
     * one person — counted whether or not anybody has since answered them,
     * and once per day however often the month was re-uploaded.
     */
    private function mismatchesFor(int $employeeId, string $from, string $to): int
    {
        return $this->newestLines($from, $to)
            ->where('l.employee_id', $employeeId)
            ->whereNotNull('l.issue')
            ->count();
    }

    /**
     * The same count for the whole factory, by department. A line with NO
     * EMPLOYEE is left out for the reason the day counts are: it belongs to
     * no department, and guessing one files somebody's day under a heading
     * nobody chose.
     *
     * @return Collection<int, object>
     */
    private function mismatchesByDepartment(string $from, string $to): Collection
    {
        return $this->newestLines($from, $to)
            ->join('employees', 'employees.id', '=', 'l.employee_id')
            ->whereNotNull('l.employee_id')
            ->whereNotNull('l.issue')
            ->groupBy('employees.department')
            ->selectRaw('employees.department as department')
            ->selectRaw('COUNT(*) as days')
            ->get();
    }

    /**
     * The uploaded lines of a range with the SUPERSEDED ones dropped: a
     * month re-uploaded as it goes carries the same day more than once, and
     * the newest reading is the one every read here takes.
     */
    private function newestLines(string $from, string $to): QueryBuilder
    {
        return DB::table('attendance_import_lines as l')
            ->whereBetween('l.date', [$from, $to])
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('attendance_import_lines as l2')
                ->whereColumn('l2.employee_id', 'l.employee_id')
                ->whereColumn('l2.date', 'l.date')
                ->whereColumn('l2.id', '>', 'l.id'));
    }

    /**
     * THE READS THAT ARE NOT A COUNT OF STATUSES.
     *
     * Three questions the tally cannot answer and the office keeps asking:
     * which DAYS the factory ran short, how LONG the days actually were,
     * and who the punch report keeps failing on.
     *
     * Hours come from the punch report and NOT from the app's own OT, Late
     * In or Early Out columns. Those are the app's arithmetic against the
     * shift window it was configured with — the same window that called 232
     * full shifts half days in July. What is trusted here is the clock: how
     * long the person was actually inside, judged against the owner's own
     * anchors (DEC-20260903-005).
     *
     * @return array<string, mixed>
     */
    public function insights(string $from, string $to): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'turnout' => $this->turnoutByDay($from, $to),
            'hours' => $this->hoursWorked($from, $to),
            'longest_days' => $this->peopleByLongDays($from, $to),
            'most_mismatched' => $this->peopleByMismatches($from, $to),
        ];
    }

    /**
     * WHO TURNED UP, DAY BY DAY — the shape of a month, not its total.
     *
     * A month that is 80% present can be a steady month or a fortnight
     * where half the floor was missing, and only one of those needs
     * somebody to do something about it.
     *
     * Unanswered days are carried separately rather than folded into the
     * absences: a day nobody has reviewed is not a day nobody worked, and a
     * turnout chart that treats it as one invents a bad day.
     *
     * @return list<array<string, mixed>>
     */
    private function turnoutByDay(string $from, string $to): array
    {
        $days = [];
        $add = function (string $date, ?string $status, int $count) use (&$days) {
            $days[$date] ??= [
                'date' => $date, 'present' => 0, 'half_day' => 0, 'absent' => 0,
                'on_leave' => 0, 'week_off' => 0, 'needs_review' => 0,
            ];

            $key = match ($status) {
                null => 'needs_review',
                AttendanceImportResolution::WeekOff->value => 'week_off',
                default => $status,
            };

            if (array_key_exists($key, $days[$date])) {
                $days[$date][$key] += $count;
            }
        };

        foreach (
            DB::table('attendances')
                ->whereBetween('date', [$from, $to])
                ->groupBy('date', 'status')
                ->selectRaw('date as date, status as status, COUNT(*) as people')
                ->get() as $row
        ) {
            $add(CarbonImmutable::parse($row->date)->toDateString(), (string) $row->status, (int) $row->people);
        }

        foreach (
            $this->newestLines($from, $to)
                ->whereNotNull('l.employee_id')
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('attendances as a')
                    ->whereColumn('a.employee_id', 'l.employee_id')
                    ->whereColumn('a.date', 'l.date'))
                ->groupBy('l.date', 'l.resolution')
                ->selectRaw('l.date as date, l.resolution as status, COUNT(*) as people')
                ->get() as $row
        ) {
            $add(CarbonImmutable::parse($row->date)->toDateString(), $row->status, (int) $row->people);
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * HOW LONG THE DAYS ACTUALLY WERE.
     *
     * A long day is two hours past the shift. It is not overtime as the
     * punch app reports it — it is time on the clock, which is the only
     * part of that report the factory has agreed to trust.
     *
     * @return array<string, int>
     */
    private function hoursWorked(string $from, string $to): array
    {
        $row = $this->newestLines($from, $to)
            ->whereNotNull('l.employee_id')
            ->where('l.worked_minutes', '>', 0)
            ->selectRaw('COUNT(*) as days')
            ->selectRaw('COALESCE(SUM(l.worked_minutes), 0) as minutes')
            ->selectRaw('SUM(CASE WHEN l.worked_minutes >= ? THEN 1 ELSE 0 END) as long_days', [self::LONG_DAY_MINUTES])
            ->selectRaw('SUM(CASE WHEN l.worked_minutes >= ? THEN 1 ELSE 0 END) as very_long_days', [self::VERY_LONG_DAY_MINUTES])
            ->selectRaw('SUM(CASE WHEN l.worked_minutes < ? THEN 1 ELSE 0 END) as short_days', [AttendanceImportService::HALF_DAY_MINUTES])
            ->selectRaw('SUM(CASE WHEN l.worked_minutes > ? THEN 1 ELSE 0 END) as implausible_days', [AttendanceImportService::IMPLAUSIBLE_MINUTES])
            ->first();

        $days = (int) ($row->days ?? 0);

        return [
            'days' => $days,
            'total_minutes' => (int) ($row->minutes ?? 0),
            // Rounded to the minute, not the second: nobody schedules a
            // factory in seconds, and a mean to two decimals reads as false
            // precision over a punch clock.
            'average_minutes' => $days === 0 ? 0 : (int) round(((int) $row->minutes) / $days),
            'long_days' => (int) ($row->long_days ?? 0),
            'very_long_days' => (int) ($row->very_long_days ?? 0),
            'short_days' => (int) ($row->short_days ?? 0),
            'implausible_days' => (int) ($row->implausible_days ?? 0),
        ];
    }

    /**
     * The people working the longest days. Not a league table — it is a
     * fatigue and a cost question, and it is asked of the clock rather than
     * of the app's overtime column.
     *
     * @return list<array<string, mixed>>
     */
    private function peopleByLongDays(string $from, string $to, int $limit = 10): array
    {
        return $this->newestLines($from, $to)
            ->join('employees', 'employees.id', '=', 'l.employee_id')
            ->whereNotNull('l.employee_id')
            ->where('l.worked_minutes', '>=', self::LONG_DAY_MINUTES)
            ->where('l.worked_minutes', '<=', AttendanceImportService::IMPLAUSIBLE_MINUTES)
            ->groupBy('employees.id', 'employees.employee_code', 'employees.name', 'employees.department')
            ->selectRaw('employees.id as employee_id')
            ->selectRaw('employees.employee_code as employee_code')
            ->selectRaw('employees.name as name')
            ->selectRaw('employees.department as department')
            ->selectRaw('COUNT(*) as long_days')
            ->selectRaw('COALESCE(SUM(l.worked_minutes), 0) as minutes')
            ->orderByDesc('long_days')
            ->orderBy('employees.employee_code')
            ->limit($limit)
            ->get()
            ->map(static fn ($row) => [
                'employee_id' => (int) $row->employee_id,
                'employee_code' => (string) $row->employee_code,
                'name' => (string) $row->name,
                'department' => $row->department,
                'long_days' => (int) $row->long_days,
                'minutes' => (int) $row->minutes,
            ])
            ->all();
    }

    /**
     * The people the punch report keeps failing on. A badge that does not
     * read, a shift the app cannot place, somebody who forgets to punch out
     * — the fix is different in each case, and none of them is the reviewer
     * answering the same person eleven times a month.
     *
     * @return list<array<string, mixed>>
     */
    private function peopleByMismatches(string $from, string $to, int $limit = 10): array
    {
        return $this->newestLines($from, $to)
            ->join('employees', 'employees.id', '=', 'l.employee_id')
            ->whereNotNull('l.employee_id')
            ->whereNotNull('l.issue')
            ->groupBy('employees.id', 'employees.employee_code', 'employees.name', 'employees.department')
            ->selectRaw('employees.id as employee_id')
            ->selectRaw('employees.employee_code as employee_code')
            ->selectRaw('employees.name as name')
            ->selectRaw('employees.department as department')
            ->selectRaw('COUNT(*) as mismatches')
            ->selectRaw('SUM(CASE WHEN l.resolution IS NULL THEN 1 ELSE 0 END) as unanswered')
            ->orderByDesc('mismatches')
            ->orderBy('employees.employee_code')
            ->limit($limit)
            ->get()
            ->map(static fn ($row) => [
                'employee_id' => (int) $row->employee_id,
                'employee_code' => (string) $row->employee_code,
                'name' => (string) $row->name,
                'department' => $row->department,
                'mismatches' => (int) $row->mismatches,
                'unanswered' => (int) $row->unanswered,
            ])
            ->all();
    }

    /** Everybody with a day in the range, applied or merely uploaded. */
    private function peopleInRange(string $from, string $to): int
    {
        $applied = DB::table('attendances')
            ->whereBetween('date', [$from, $to])
            ->distinct()
            ->pluck('employee_id');

        $uploaded = DB::table('attendance_import_lines')
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id');

        return $applied->merge($uploaded)->unique()->count();
    }

    /**
     * The uploads whose period touches this range, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function importsCovering(string $from, string $to): array
    {
        return AttendanceImport::query()
            ->where('period_from', '<=', $to)
            ->where('period_to', '>=', $from)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AttendanceImport $import) => [
                'id' => $import->id,
                'file_name' => $import->file_name,
                'status' => $import->status->value,
                'period_from' => $import->period_from->toDateString(),
                'period_to' => $import->period_to->toDateString(),
            ])
            ->all();
    }

    /**
     * The people with the most absent days in the range. ONLY people who
     * were actually absent: a list padded with zeroes says nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function mostAbsent(string $from, string $to, int $limit = 10): array
    {
        return DB::table('attendances')
            ->join('employees', 'employees.id', '=', 'attendances.employee_id')
            ->whereBetween('attendances.date', [$from, $to])
            ->where('attendances.status', AttendanceStatus::Absent->value)
            ->groupBy('employees.id', 'employees.employee_code', 'employees.name', 'employees.department')
            ->selectRaw('employees.id as employee_id')
            ->selectRaw('employees.employee_code as employee_code')
            ->selectRaw('employees.name as name')
            ->selectRaw('employees.department as department')
            ->selectRaw('COUNT(*) as absent')
            ->orderByDesc('absent')
            ->orderBy('employees.employee_code')
            ->limit($limit)
            ->get()
            ->map(static fn ($row) => [
                'employee_id' => (int) $row->employee_id,
                'employee_code' => (string) $row->employee_code,
                'name' => (string) $row->name,
                'department' => $row->department,
                'absent' => (int) $row->absent,
            ])
            ->all();
    }

    private function departmentName(mixed $department): string
    {
        return $department === null || trim((string) $department) === ''
            ? self::NO_DEPARTMENT
            : (string) $department;
    }

    /**
     * One count per status the master knows, plus what was recorded at all.
     * Every status is present even at zero, so the screen's columns do not
     * appear and disappear with the data.
     *
     * @param  array<string, int>  $counted
     * @return array<string, int>
     */
    private function tally(array $counted, int $needsReview = 0, int $fromImport = 0, int $mismatches = 0): array
    {
        $tally = [];
        foreach (AttendanceStatus::cases() as $status) {
            $tally[$status->value] = (int) ($counted[$status->value] ?? 0);
        }
        // `recorded` is the four ATTENDANCE statuses and nothing else. A week
        // off is not attendance — it is the absence of a working day — and a
        // day nobody has answered is not attendance either.
        $tally['recorded'] = array_sum($tally);
        $tally['week_off'] = (int) ($counted[AttendanceImportResolution::WeekOff->value] ?? 0);
        $tally['needs_review'] = $needsReview;
        $tally['from_import'] = $fromImport;
        // A MISMATCH is what the punch report could not make sense of — a
        // punch in with no punch out, no punch at all, hours that do not
        // add up, work on a week off. It is NOT `needs_review`: answering a
        // mismatch settles the day without unmaking the fact that the
        // report was wrong, and the month's figure has to keep counting it
        // or it will not reconcile against the import review page.
        $tally['mismatches'] = $mismatches;

        return $tally;
    }

    /**
     * Present days over recorded days, A HALF DAY COUNTING AS HALF.
     *
     * Written out because it is a judgement somebody will want to argue
     * with: leave and absence both count against the figure, and a range
     * nobody recorded is 0 rather than 100.
     *
     * @param  array<string, int>  $tally
     */
    private function presentPercent(array $tally): float
    {
        $recorded = (int) ($tally['recorded'] ?? 0);
        if ($recorded === 0) {
            return 0.0;
        }

        $present = (int) ($tally[AttendanceStatus::Present->value] ?? 0)
            + ((int) ($tally[AttendanceStatus::HalfDay->value] ?? 0)) / 2;

        return round($present / $recorded * 100, 1);
    }

    /**
     * One record per employee+date — marking the same day again (e.g.
     * correcting a mistake) updates it in place rather than erroring on
     * the unique constraint.
     *
     * @param  array{employee_id: int, date: string, status: string, check_in?: string, check_out?: string, notes?: string}  $data
     */
    public function mark(array $data): Attendance
    {
        return Attendance::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'date' => $data['date']],
            [
                'status' => $data['status'],
                'check_in' => $data['check_in'] ?? null,
                'check_out' => $data['check_out'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        )->load('employee');
    }
}
