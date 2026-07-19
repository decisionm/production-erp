<?php

namespace App\Modules\HRMS\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\HRMS\Exceptions\InsufficientLeaveBalanceException;
use App\Modules\HRMS\Models\Enums\LeaveRequestStatus;
use App\Modules\HRMS\Models\LeaveBalance;
use App\Modules\HRMS\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LeaveRequestService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return LeaveRequest::query()
            ->with(['employee', 'leaveType', 'approvedBy'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function pendingCount(): int
    {
        return LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Pending)
            ->count();
    }

    /**
     * @param  array{employee_id: int, leave_type_id: int, start_date: string, end_date: string, days: string, reason?: string}  $data
     */
    public function create(array $data): LeaveRequest
    {
        return DB::transaction(function () use ($data) {
            $year = Carbon::parse($data['start_date'])->year;
            $balance = $this->lockBalance($data['employee_id'], $data['leave_type_id'], $year);

            $this->guardRemaining($balance, $data['employee_id'], $data['leave_type_id'], (string) $data['days']);

            return LeaveRequest::create([
                'employee_id' => $data['employee_id'],
                'leave_type_id' => $data['leave_type_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $data['days'],
                'reason' => $data['reason'] ?? null,
                'status' => LeaveRequestStatus::Pending,
            ])->load(['employee', 'leaveType']);
        });
    }

    /**
     * Balance is re-checked here too, not just at request time — it can
     * have changed since submission (another request approved in the
     * meantime), and the row lock makes two concurrent approvals against
     * the same balance safe.
     */
    public function approve(LeaveRequest $request, ?int $approvedBy): LeaveRequest
    {
        $this->guardPending($request, LeaveRequestStatus::Approved);

        return DB::transaction(function () use ($request, $approvedBy) {
            $year = $request->start_date->year;
            $balance = $this->lockBalance($request->employee_id, $request->leave_type_id, $year);

            $this->guardRemaining($balance, $request->employee_id, $request->leave_type_id, $request->days);

            $balance->increment('used_days', $request->days);

            $request->update([
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $approvedBy,
                'decided_at' => now(),
            ]);

            return $request;
        });
    }

    public function reject(LeaveRequest $request, ?int $approvedBy): LeaveRequest
    {
        $this->guardPending($request, LeaveRequestStatus::Rejected);

        $request->update([
            'status' => LeaveRequestStatus::Rejected,
            'approved_by' => $approvedBy,
            'decided_at' => now(),
        ]);

        return $request;
    }

    private function guardPending(LeaveRequest $request, LeaveRequestStatus $target): void
    {
        if ($request->status !== LeaveRequestStatus::Pending) {
            throw InvalidStatusTransitionException::make('leave request', $request->status->value, $target->value);
        }
    }

    private function lockBalance(int $employeeId, int $leaveTypeId, int $year): LeaveBalance
    {
        $balance = LeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            throw InsufficientLeaveBalanceException::noAllocation($employeeId, $leaveTypeId, $year);
        }

        return $balance;
    }

    private function guardRemaining(LeaveBalance $balance, int $employeeId, int $leaveTypeId, string $requestedDays): void
    {
        $remaining = bcsub($balance->allocated_days, $balance->used_days, 2);

        if (bccomp($requestedDays, $remaining, 2) > 0) {
            throw InsufficientLeaveBalanceException::forEmployee($employeeId, $leaveTypeId, $remaining, $requestedDays);
        }
    }
}
