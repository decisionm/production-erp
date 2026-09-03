<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Http\Requests\ListSalaryComponentsRequest;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SalaryComponentService
{
    /** Ordered by `sort` (ListSort; validated by ListSalaryComponentsRequest), by name as it always was when absent. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        return ListSort::apply(SalaryComponent::query(), $sort, ListSalaryComponentsRequest::SORTABLE, 'name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data): SalaryComponent
    {
        return SalaryComponent::create([
            'is_active' => true,
            ...$data,
        ]);
    }
}
