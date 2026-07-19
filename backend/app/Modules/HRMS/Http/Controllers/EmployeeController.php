<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\StoreEmployeeRequest;
use App\Modules\HRMS\Http\Requests\UpdateEmployeeRequest;
use App\Modules\HRMS\Http\Resources\EmployeeResource;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Services\EmployeeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->employees->paginate());
    }

    public function store(StoreEmployeeRequest $request): EmployeeResource
    {
        return EmployeeResource::make($this->employees->create($request->validated()));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        return EmployeeResource::make($this->employees->update($employee, $request->validated()));
    }
}
