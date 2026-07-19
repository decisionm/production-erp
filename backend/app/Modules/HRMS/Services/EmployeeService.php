<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EmployeeService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Employee::query()
            ->with(['manager'])
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): Employee
    {
        return Employee::create([
            'status' => 'active',
            ...$data,
        ])->load('manager');
    }

    public function update(Employee $employee, array $data): Employee
    {
        $employee->update($data);

        return $employee->load('manager');
    }
}
