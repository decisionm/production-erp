<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Http\Requests\ListLeaveBalancesRequest;
use App\Modules\HRMS\Models\LeaveBalance;
use App\Modules\HRMS\Models\LeaveType;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LeaveBalanceService
{
    /** Ordered by `sort` (ListSort; validated by ListLeaveBalancesRequest), newest year first as it always was when absent. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = LeaveBalance::query()->with(['employee', 'leaveType']);

        return ListSort::apply($query, $sort, ListLeaveBalancesRequest::SORTABLE, '-year')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{employee_id: int, leave_type_id: int, year: int, allocated_days?: string}  $data
     */
    public function allocate(array $data): LeaveBalance
    {
        $allocatedDays = $data['allocated_days']
            ?? LeaveType::findOrFail($data['leave_type_id'])->default_annual_days;

        return LeaveBalance::create([
            'employee_id' => $data['employee_id'],
            'leave_type_id' => $data['leave_type_id'],
            'year' => $data['year'],
            'allocated_days' => $allocatedDays,
            'used_days' => 0,
        ])->load(['employee', 'leaveType']);
    }
}
