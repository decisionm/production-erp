<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Models\LeaveType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LeaveTypeService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return LeaveType::query()
            ->orderBy('name')
            ->paginate($perPage);
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
