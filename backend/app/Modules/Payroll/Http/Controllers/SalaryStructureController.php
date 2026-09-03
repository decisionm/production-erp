<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\ListSalaryStructuresRequest;
use App\Modules\Payroll\Http\Requests\StoreSalaryStructureRequest;
use App\Modules\Payroll\Http\Resources\SalaryStructureResource;
use App\Modules\Payroll\Services\PayrollListQuery;
use App\Modules\Payroll\Services\SalaryStructureService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalaryStructureController extends Controller
{
    public function __construct(private readonly SalaryStructureService $structures) {}

    /** The list, filtered, sorted and paged by ListSalaryStructuresRequest; `?employee_id=` alone is the list every earlier caller got. */
    public function index(ListSalaryStructuresRequest $request, PayrollListQuery $query): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return SalaryStructureResource::collection(
            $this->structures->paginate(
                empty($filters['employee_id']) ? null : (int) $filters['employee_id'],
                $query->perPage($filters),
                $filters['sort'] ?? null,
            ),
        );
    }

    public function store(StoreSalaryStructureRequest $request): SalaryStructureResource
    {
        return SalaryStructureResource::make($this->structures->create($request->validated()));
    }
}
