<?php

namespace App\Modules\HRMS\Services;

use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\EmployeeStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EmployeeService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Employee::query()
            ->with(['manager'])
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Active employees for other modules to aggregate over (e.g. Payroll
     * run generation). Not paginated: this is meant for batch processing,
     * not a list screen.
     */
    public function active(): Collection
    {
        return Employee::query()
            ->where('status', EmployeeStatus::Active)
            ->orderBy('name')
            ->get();
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
