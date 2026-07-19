<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\StoreSalaryStructureRequest;
use App\Modules\Payroll\Http\Resources\SalaryStructureResource;
use App\Modules\Payroll\Services\SalaryStructureService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalaryStructureController extends Controller
{
    public function __construct(private readonly SalaryStructureService $structures) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SalaryStructureResource::collection(
            $this->structures->paginate($request->integer('employee_id') ?: null),
        );
    }

    public function store(StoreSalaryStructureRequest $request): SalaryStructureResource
    {
        return SalaryStructureResource::make($this->structures->create($request->validated()));
    }
}
