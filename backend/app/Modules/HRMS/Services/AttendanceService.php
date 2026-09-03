<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Http\Requests\ListAttendanceRequest;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\AttendanceStatus;
use App\Support\Lists\ListSort;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /** What an employee with no department is called on the summary. */
    private const NO_DEPARTMENT = 'No department';

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
    public function paginate(int $perPage = HrmsListQuery::PER_PAGE_DEFAULT, array $filters = []): LengthAwarePaginator
    {
        $query = Attendance::query()->with('employee');

        if (($term = $this->query->term($filters)) !== null) {
            $query->whereHas('employee', fn (Builder $employee) => $this->query->whereEmployeeMatches($employee, $term));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        $this->query->applyDateRange($query, 'date', $filters['from'] ?? null, $filters['to'] ?? null);

        return ListSort::apply($query, $filters['sort'] ?? null, ListAttendanceRequest::SORTABLE, '-date')
            ->paginate($perPage)
            ->withQueryString();
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
        $days = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $counted = [];
        foreach ($days as $day) {
            $key = $day->status->value;
            $counted[$key] = ($counted[$key] ?? 0) + 1;
        }

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
            'days' => $days->map(static fn (Attendance $day) => [
                'id' => $day->id,
                'date' => $day->date->toDateString(),
                'status' => $day->status->value,
                'check_in' => $day->check_in?->toIso8601String(),
                'check_out' => $day->check_out?->toIso8601String(),
                'notes' => $day->notes,
            ])->all(),
            'summary' => $this->tally($counted),
        ];
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
        foreach ($rows as $row) {
            $name = $this->departmentName($row->department);
            $status = (string) $row->status;
            $days = (int) $row->days;

            $counted[$name][$status] = ($counted[$name][$status] ?? 0) + $days;
            $factory[$status] = ($factory[$status] ?? 0) + $days;
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

        $departments = [];
        foreach ($counted as $name => $statuses) {
            $tally = $this->tally($statuses);
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

        $factoryTally = $this->tally($factory);

        return [
            'from' => $from,
            'to' => $to,
            'departments' => $departments,
            'totals' => [
                ...$factoryTally,
                'employees' => DB::table('attendances')
                    ->whereBetween('date', [$from, $to])
                    ->distinct()
                    ->count('employee_id'),
                'departments' => count($departments),
                'present_percent' => $this->presentPercent($factoryTally),
            ],
            'most_absent' => $this->mostAbsent($from, $to),
        ];
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
    private function tally(array $counted): array
    {
        $tally = [];
        foreach (AttendanceStatus::cases() as $status) {
            $tally[$status->value] = (int) ($counted[$status->value] ?? 0);
        }
        $tally['recorded'] = array_sum($tally);

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
