<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Models\Attendance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AttendanceService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Attendance::query()
            ->with('employee')
            ->orderByDesc('date')
            ->paginate($perPage);
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
