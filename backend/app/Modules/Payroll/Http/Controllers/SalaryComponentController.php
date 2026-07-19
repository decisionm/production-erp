<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\StoreSalaryComponentRequest;
use App\Modules\Payroll\Http\Resources\SalaryComponentResource;
use App\Modules\Payroll\Services\SalaryComponentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalaryComponentController extends Controller
{
    public function __construct(private readonly SalaryComponentService $components) {}

    public function index(): AnonymousResourceCollection
    {
        return SalaryComponentResource::collection($this->components->paginate());
    }

    public function store(StoreSalaryComponentRequest $request): SalaryComponentResource
    {
        return SalaryComponentResource::make($this->components->create($request->validated()));
    }
}
