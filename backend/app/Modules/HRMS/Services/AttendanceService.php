<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Models\Attendance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AttendanceService
{
    public function __construct(private readonly HrmsListQuery $query) {}

    /**
     * The list page's read. Every filter is ListAttendanceRequest's — `q`
     * THROUGH the employee (code, name, department, designation), `status`
     * and `employee_id` exact, `from`/`to` inclusive on the attendance date.
     * Newest date first, as it always was, with id breaking ties so a day
     * with thirty marks reads in one order on every load.
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

        return $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
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
