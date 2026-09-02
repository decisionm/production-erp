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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * process() skips employees who have no salary structure effective for the
 * period rather than failing the whole run — one employee missing setup
 * shouldn't block payroll for everyone else. Skipped employees are reported
 * back so they can be fixed and picked up in the next run.
 */
class PayrollRunService
{
    /** Lower-case, for the period grammar's month-name prefixes. */
    private const MONTHS = [
        'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december',
    ];

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly SalaryStructureService $structures,
        private readonly PayrollListQuery $query,
    ) {}

    /**
     * The list, filtered (ListPayrollRunsRequest's validated input); an
     * empty array is the list every earlier caller got — newest period
     * first, same page size. Ties cannot occur (one run per period), and
     * id breaks them anyway so the order is stable by construction.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = PayrollRun::query();
        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('id')
            ->paginate($this->query->perPage($filters))
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $period = $this->periodTerm((string) $filters['q']);

            // A term that names no period names no run: an empty page, not
            // the whole list wearing a search term.
            if ($period === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            if ($period['year'] !== null) {
                $query->where('year', $period['year']);
            }
            if ($period['month'] !== null) {
                $query->where('month', $period['month']);
            }
            if ($period['status'] !== null) {
                $query->where('status', $period['status']);
            }
        }
    }

    /**
     * What a typed `q` names about a run. A run has no name of its own — its
     * identity is the period, and the page prints it as "August 2026" — so
     * the grammar is the period in the spellings people type: "aug",
     * "August", "2026", "2026-08", "08/2026", "Aug 2026", "2026 aug"; a
     * status word ("paid") narrows by status. Two years, two months, or any
     * token that is none of these names no run, and null says so.
     *
     * @return array{year: ?int, month: ?int, status: ?PayrollRunStatus}|null
     */
    private function periodTerm(string $term): ?array
    {
        $tokens = preg_split('/[\s\-\/.,]+/', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return null;
        }

        $year = $month = $status = null;

        foreach ($tokens as $token) {
            if (preg_match('/^\d{4}$/', $token) === 1) {
                if ($year !== null) {
                    return null;
                }
                $year = (int) $token;

                continue;
            }

            if (preg_match('/^\d{1,2}$/', $token) === 1 && (int) $token >= 1 && (int) $token <= 12) {
                if ($month !== null) {
                    return null;
                }
                $month = (int) $token;

                continue;
            }

            $named = $this->monthNamed($token);
            if ($named !== null) {
                if ($month !== null) {
                    return null;
                }
                $month = $named;

                continue;
            }

            $case = PayrollRunStatus::tryFrom($token);
            if ($case !== null) {
                if ($status !== null) {
                    return null;
                }
                $status = $case;

                continue;
            }

            return null;
        }

        return ['year' => $year, 'month' => $month, 'status' => $status];
    }

    /** "aug" → 8, "sept" → 9; three letters at least, so "ma" does not pick between March and May. */
    private function monthNamed(string $token): ?int
    {
        if (strlen($token) < 3 || ! ctype_alpha($token)) {
            return null;
        }

        foreach (self::MONTHS as $index => $name) {
            if (str_starts_with($name, $token)) {
                return $index + 1;
            }
        }

        return null;
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
