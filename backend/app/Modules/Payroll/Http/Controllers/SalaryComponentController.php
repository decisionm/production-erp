<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\ListSalaryComponentsRequest;
use App\Modules\Payroll\Http\Requests\StoreSalaryComponentRequest;
use App\Modules\Payroll\Http\Resources\SalaryComponentResource;
use App\Modules\Payroll\Services\SalaryComponentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalaryComponentController extends Controller
{
    public function __construct(private readonly SalaryComponentService $components) {}

    /**
     * The salary component list. `per_page` is honoured so a PICKER can ask for the
     * whole master: its dropdown offers ACTIVE rows only now, and
     * filtering the first 20 would hide part of a list that was already
     * truncated (the item/vendor picker defect, 12-Aug). The default is
     * unchanged for every other caller. `sort` is ListSalaryComponentsRequest's.
     */
    public function index(ListSalaryComponentsRequest $request): AnonymousResourceCollection
    {
        return SalaryComponentResource::collection(
            $this->components->paginate($this->perPage($request, max: ListSalaryComponentsRequest::PER_PAGE_MAX), $request->validated('sort')),
        );
    }

    public function store(StoreSalaryComponentRequest $request): SalaryComponentResource
    {
        return SalaryComponentResource::make($this->components->create($request->validated()));
    }
}
