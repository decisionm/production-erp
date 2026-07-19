<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\Payslip;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PayslipService
{
    public function paginate(?int $payrollRunId, int $perPage = 20): LengthAwarePaginator
    {
        return Payslip::query()
            ->when($payrollRunId, fn ($query) => $query->where('payroll_run_id', $payrollRunId))
            ->with(['employee', 'payrollRun', 'lines'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function show(Payslip $payslip): Payslip
    {
        return $payslip->load(['employee', 'payrollRun', 'lines']);
    }
}
