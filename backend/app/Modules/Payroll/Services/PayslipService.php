<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\Payslip;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PayslipService
{
    public function __construct(private readonly PayrollListQuery $query) {}

    /**
     * The list, filtered (ListPayslipsRequest's validated input). `q`
     * matches the employee's name or code through the relation — a payslip's
     * identity is its employee and its run, and the run is the page's own
     * filter. Newest first; id is unique, so the order is stable as it is.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Payslip::query();

        if (! empty($filters['payroll_run_id'])) {
            $query->where('payroll_run_id', (int) $filters['payroll_run_id']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = trim((string) $filters['q']);

            $query->whereHas('employee', function (Builder $employee) use ($term) {
                $employee->where(function (Builder $either) use ($term) {
                    $this->query->whereLike($either, 'name', $term);
                    $either->orWhere(fn (Builder $code) => $this->query->whereLike($code, 'employee_code', $term));
                });
            });
        }

        return $query
            ->with(['employee', 'payrollRun', 'lines'])
            ->orderByDesc('id')
            ->paginate($this->query->perPage($filters))
            ->withQueryString();
    }

    public function show(Payslip $payslip): Payslip
    {
        return $payslip->load(['employee', 'payrollRun', 'lines']);
    }
}
