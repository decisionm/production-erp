<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\SalaryComponent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SalaryComponentService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return SalaryComponent::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): SalaryComponent
    {
        return SalaryComponent::create([
            'is_active' => true,
            ...$data,
        ]);
    }
}
