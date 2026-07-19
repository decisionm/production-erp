<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Resources\PayslipResource;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayslipController extends Controller
{
    public function __construct(private readonly PayslipService $payslips) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PayslipResource::collection(
            $this->payslips->paginate($request->integer('payroll_run_id') ?: null),
        );
    }

    public function show(Payslip $payslip): PayslipResource
    {
        return PayslipResource::make($this->payslips->show($payslip));
    }
}
