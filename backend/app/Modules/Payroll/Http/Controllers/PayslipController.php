<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\ListPayslipsRequest;
use App\Modules\Payroll\Http\Resources\PayslipResource;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayslipController extends Controller
{
    public function __construct(private readonly PayslipService $payslips) {}

    /** The list, filtered by ListPayslipsRequest; `?payroll_run_id=` alone is the list every earlier caller got. */
    public function index(ListPayslipsRequest $request): AnonymousResourceCollection
    {
        return PayslipResource::collection($this->payslips->paginate($request->validated()));
    }

    public function show(Payslip $payslip): PayslipResource
    {
        return PayslipResource::make($this->payslips->show($payslip));
    }
}
