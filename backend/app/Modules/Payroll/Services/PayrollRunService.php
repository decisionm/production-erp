<?php

namespace App\Modules\Payroll\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Services\EmployeeService;
use App\Modules\Payroll\Models\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\Enums\PayslipLineType;
use App\Modules\Payroll\Models\Enums\SalaryComponentType;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\SalaryStructure;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * process() skips employees who have no salary structure effective for the
 * period rather than failing the whole run — one employee missing setup
 * shouldn't block payroll for everyone else. Skipped employees are reported
 * back so they can be fixed and picked up in the next run.
 */
class PayrollRunService
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly SalaryStructureService $structures,
    ) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return PayrollRun::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate($perPage);
    }

    public function create(array $data): PayrollRun
    {
        return PayrollRun::create([
            'year' => $data['year'],
            'month' => $data['month'],
            'status' => PayrollRunStatus::Draft,
        ]);
    }

    /**
     * @return array{run: PayrollRun, skipped: array<int, array{employee_id: int, employee_name: string}>}
     */
    public function process(PayrollRun $run): array
    {
        if ($run->status !== PayrollRunStatus::Draft) {
            throw InvalidStatusTransitionException::make(
                'payroll run',
                $run->status->value,
                PayrollRunStatus::Processed->value,
            );
        }

        $periodEnd = Carbon::create($run->year, $run->month, 1)->endOfMonth()->toDateString();
        $skipped = [];

        DB::transaction(function () use ($run, $periodEnd, &$skipped) {
            foreach ($this->employees->active() as $employee) {
                $structure = $this->structures->currentFor($employee->id, $periodEnd);

                if (! $structure) {
                    $skipped[] = ['employee_id' => $employee->id, 'employee_name' => $employee->name];

                    continue;
                }

                $this->generatePayslip($run, $employee, $structure);
            }

            $run->update(['status' => PayrollRunStatus::Processed, 'processed_at' => now()]);
        });

        return ['run' => $run->fresh(['payslips.lines']), 'skipped' => $skipped];
    }

    public function markPaid(PayrollRun $run): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Processed) {
            throw InvalidStatusTransitionException::make(
                'payroll run',
                $run->status->value,
                PayrollRunStatus::Paid->value,
            );
        }

        $run->update(['status' => PayrollRunStatus::Paid, 'paid_at' => now()]);

        return $run;
    }

    private function generatePayslip(PayrollRun $run, Employee $employee, SalaryStructure $structure): void
    {
        $lines = [];
        $grossEarnings = '0.0000';
        $totalDeductions = '0.0000';
        $basicAmount = '0';

        foreach ($structure->lines as $line) {
            $amount = (string) $line->amount;

            if ($line->component->code === 'BASIC') {
                $basicAmount = $amount;
            }

            $type = $line->component->type === SalaryComponentType::Earning
                ? PayslipLineType::Earning
                : PayslipLineType::Deduction;

            $lines[] = ['label' => $line->component->name, 'type' => $type, 'amount' => $amount];

            $grossEarnings = $type === PayslipLineType::Earning
                ? bcadd($grossEarnings, $amount, 4)
                : $grossEarnings;

            $totalDeductions = $type === PayslipLineType::Deduction
                ? bcadd($totalDeductions, $amount, 4)
                : $totalDeductions;
        }

        [$pfLines, $pfEmployeeDeduction] = $this->calculatePf($basicAmount);
        [$esiLines, $esiEmployeeDeduction] = $this->calculateEsi($grossEarnings);

        $lines = [...$lines, ...$pfLines, ...$esiLines];
        $totalDeductions = bcadd(bcadd($totalDeductions, $pfEmployeeDeduction, 4), $esiEmployeeDeduction, 4);
        $netPay = bcsub($grossEarnings, $totalDeductions, 4);

        $payslip = $run->payslips()->create([
            'employee_id' => $employee->id,
            'gross_earnings' => $grossEarnings,
            'total_deductions' => $totalDeductions,
            'net_pay' => $netPay,
        ]);

        foreach ($lines as $line) {
            $payslip->lines()->create($line);
        }
    }

    /**
     * @return array{0: array<int, array{label: string, type: PayslipLineType, amount: string}>, 1: string}
     */
    private function calculatePf(string $basicAmount): array
    {
        if (bccomp($basicAmount, '0', 4) <= 0) {
            return [[], '0.0000'];
        }

        $ceiling = (string) config('payroll.pf.wage_ceiling');
        $cappedBasic = bccomp($basicAmount, $ceiling, 4) > 0 ? $ceiling : $basicAmount;

        $employeeAmount = bcmul($cappedBasic, (string) config('payroll.pf.employee_rate'), 4);
        $employerAmount = bcmul($cappedBasic, (string) config('payroll.pf.employer_rate'), 4);

        return [
            [
                ['label' => 'Provident Fund (Employee)', 'type' => PayslipLineType::Deduction, 'amount' => $employeeAmount],
                ['label' => 'Provident Fund (Employer)', 'type' => PayslipLineType::EmployerContribution, 'amount' => $employerAmount],
            ],
            $employeeAmount,
        ];
    }

    /**
     * @return array{0: array<int, array{label: string, type: PayslipLineType, amount: string}>, 1: string}
     */
    private function calculateEsi(string $grossEarnings): array
    {
        $ceiling = (string) config('payroll.esi.wage_ceiling');

        if (bccomp($grossEarnings, '0', 4) <= 0 || bccomp($grossEarnings, $ceiling, 4) > 0) {
            return [[], '0.0000'];
        }

        $employeeAmount = bcmul($grossEarnings, (string) config('payroll.esi.employee_rate'), 4);
        $employerAmount = bcmul($grossEarnings, (string) config('payroll.esi.employer_rate'), 4);

        return [
            [
                ['label' => 'ESI (Employee)', 'type' => PayslipLineType::Deduction, 'amount' => $employeeAmount],
                ['label' => 'ESI (Employer)', 'type' => PayslipLineType::EmployerContribution, 'amount' => $employerAmount],
            ],
            $employeeAmount,
        ];
    }
}
