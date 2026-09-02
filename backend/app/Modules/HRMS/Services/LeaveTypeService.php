<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Http\Requests\ListLeaveTypesRequest;
use App\Modules\HRMS\Models\LeaveType;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LeaveTypeService
{
    /** Ordered by `sort` (ListSort; validated by ListLeaveTypesRequest), by name as it always was when absent. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        return ListSort::apply(LeaveType::query(), $sort, ListLeaveTypesRequest::SORTABLE, 'name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data): LeaveType
    {
        return LeaveType::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(LeaveType $leaveType, array $data): LeaveType
    {
        $leaveType->update($data);

        return $leaveType;
    }
}
