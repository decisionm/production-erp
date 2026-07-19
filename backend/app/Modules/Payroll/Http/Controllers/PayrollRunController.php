<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\StorePayrollRunRequest;
use App\Modules\Payroll\Http\Resources\PayrollRunResource;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollRunController extends Controller
{
    public function __construct(private readonly PayrollRunService $runs) {}

    public function index(): AnonymousResourceCollection
    {
        return PayrollRunResource::collection($this->runs->paginate());
    }

    public function store(StorePayrollRunRequest $request): PayrollRunResource
    {
        return PayrollRunResource::make($this->runs->create($request->validated()));
    }

    public function process(PayrollRun $payrollRun): JsonResponse
    {
        $result = $this->runs->process($payrollRun);

        return response()->json([
            'data' => PayrollRunResource::make($result['run']),
            'skipped' => $result['skipped'],
        ]);
    }

    public function markPaid(PayrollRun $payrollRun): PayrollRunResource
    {
        return PayrollRunResource::make($this->runs->markPaid($payrollRun));
    }
}
