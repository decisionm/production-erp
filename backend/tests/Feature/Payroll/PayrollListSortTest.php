<?php

namespace Tests\Feature\Payroll;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Payroll\Models\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE FOUR PAYROLL LISTS SORT ON THE SERVER (ListSort, 03-Sep-2026) — runs,
 * payslips, salary components, salary structures. For each:
 *
 *   - a `sort` naming no column the list shows is a 422;
 *   - `-column` orders descending with `id desc` as the tiebreak;
 *   - `per_page` is honoured and `meta.total` is the whole collection.
 *
 * A run's `period` is year and month read as one, and its two stamps are
 * nullable: an unprocessed run has no processed_at, and it sorts LAST in
 * either direction, because "never" is not "earliest".
 *
 * Rows are built directly — no payroll is computed, and every figure here
 * is synthetic.
 */
class PayrollListSortTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anitha;

    private Employee $bala;

    protected function setUp(): void
    {
        parent::setUp();

        $reader = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('payroll.view', 'web');
        $reader->givePermissionTo('payroll.view');
        Sanctum::actingAs($reader->fresh());

        $this->anitha = Employee::create(['employee_code' => 'RG-E1', 'name' => 'Anitha Kumar', 'date_of_joining' => '2026-01-01']);
        $this->bala = Employee::create(['employee_code' => 'RG-E2', 'name' => 'Bala Murugan', 'date_of_joining' => '2026-01-01']);
    }

    /** @param  array<string, mixed>  $query */
    private function list(string $path, array $query = []): TestResponse
    {
        return $this->getJson("/api/v1/payroll/{$path}".($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @return list<int> */
    private function idsOf(string $path, string $sort): array
    {
        return $this->list($path, ['sort' => $sort])->assertOk()->json('data.*.id');
    }

    private function assertPagesHonestly(string $path, int $total): void
    {
        $page = $this->list($path, ['per_page' => 2])->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame($total, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.per_page'));
    }

    // ---- payroll runs ------------------------------------------------------

    public function test_runs_sort_by_period_status_and_the_nullable_stamps(): void
    {
        // Created oldest-period-last on purpose, so id order and period order differ.
        $jul = PayrollRun::create(['year' => 2026, 'month' => 7, 'status' => PayrollRunStatus::Draft]);
        $dec = PayrollRun::create(['year' => 2025, 'month' => 12, 'status' => PayrollRunStatus::Paid, 'processed_at' => '2026-01-02 10:00:00', 'paid_at' => '2026-01-05 10:00:00']);
        $aug = PayrollRun::create(['year' => 2026, 'month' => 8, 'status' => PayrollRunStatus::Draft]);

        $this->list('runs', ['sort' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('runs', ['sort' => 'year'])->assertStatus(422)->assertJsonValidationErrors(['sort']);

        $this->assertSame([$aug->id, $jul->id, $dec->id], $this->list('runs')->assertOk()->json('data.*.id'), 'newest period first when nothing is asked');
        $this->assertSame([$aug->id, $jul->id, $dec->id], $this->idsOf('runs', '-period'));
        $this->assertSame([$dec->id, $jul->id, $aug->id], $this->idsOf('runs', 'period'));

        // Two drafts, the newer id first, after the one paid run.
        $this->assertSame([$dec->id, $aug->id, $jul->id], $this->idsOf('runs', '-status'));

        // The two undated runs sort last whichever way the dated one goes.
        $this->assertSame([$dec->id, $aug->id, $jul->id], $this->idsOf('runs', 'processed_at'));
        $this->assertSame([$dec->id, $aug->id, $jul->id], $this->idsOf('runs', '-processed_at'));
        $this->assertSame([$dec->id, $aug->id, $jul->id], $this->idsOf('runs', 'paid_at'));

        $this->assertPagesHonestly('runs', 3);
    }

    // ---- payslips ----------------------------------------------------------

    public function test_payslips_sort_by_their_three_stored_figures(): void
    {
        $run = PayrollRun::create(['year' => 2026, 'month' => 8, 'status' => PayrollRunStatus::Processed]);
        $other = PayrollRun::create(['year' => 2026, 'month' => 7, 'status' => PayrollRunStatus::Paid]);

        $low = $this->slip($run, $this->anitha, '1.0000', '0.5000', '0.5000');
        $highFirst = $this->slip($run, $this->bala, '3.0000', '1.0000', '2.0000');
        $highSecond = $this->slip($other, $this->anitha, '2.0000', '0.0000', '2.0000');

        $this->list('payslips', ['sort' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('payslips', ['sort' => 'employee'])->assertStatus(422)->assertJsonValidationErrors(['sort']);

        // Two of net 2, the newer id first, then the lower one.
        $this->assertSame([$highSecond->id, $highFirst->id, $low->id], $this->idsOf('payslips', '-net_pay'));
        $this->assertSame([$low->id, $highSecond->id, $highFirst->id], $this->idsOf('payslips', 'net_pay'));
        $this->assertSame([$highFirst->id, $highSecond->id, $low->id], $this->idsOf('payslips', '-gross_earnings'));
        $this->assertSame([$highSecond->id, $low->id, $highFirst->id], $this->idsOf('payslips', 'total_deductions'));

        // The sort composes with the run filter the page has always sent.
        $this->assertSame([$highFirst->id, $low->id], $this->list('payslips', ['sort' => '-net_pay', 'payroll_run_id' => $run->id])->assertOk()->json('data.*.id'));

        $this->assertPagesHonestly('payslips', 3);
    }

    // ---- salary components -------------------------------------------------

    public function test_salary_components_sort_page_and_keep_the_pickers_ceiling(): void
    {
        $basic = SalaryComponent::create(['code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning', 'calculation_type' => 'fixed_amount']);
        $hra = SalaryComponent::create(['code' => 'HRA', 'name' => 'Allowance', 'type' => 'earning', 'calculation_type' => 'percentage_of_basic', 'percentage' => 40]);
        $pt = SalaryComponent::create(['code' => 'PT', 'name' => 'Allowance', 'type' => 'deduction', 'calculation_type' => 'fixed_amount', 'is_active' => false]);

        $this->list('salary-components', ['sort' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('salary-components', ['per_page' => 0])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->list('salary-components', ['per_page' => 1001])->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        // "Basic" first, then the two "Allowance" rows with the newer id first.
        $this->assertSame([$basic->id, $pt->id, $hra->id], $this->idsOf('salary-components', '-name'));
        $this->assertSame([$pt->id, $hra->id, $basic->id], $this->idsOf('salary-components', '-code'));
        $this->assertSame([$hra->id, $basic->id, $pt->id], $this->idsOf('salary-components', '-type'), 'earning, earning (newer first), deduction');
        $this->assertSame([$pt->id, $hra->id, $basic->id], $this->idsOf('salary-components', 'is_active'), 'withdrawn first, then active newest first');

        // By name when nothing is asked, as it always was.
        $this->assertSame(['Allowance', 'Allowance', 'Basic'], $this->list('salary-components')->assertOk()->json('data.*.name'));

        $this->assertPagesHonestly('salary-components', 3);
        $this->assertSame(1000, $this->list('salary-components', ['per_page' => 1000])->assertOk()->json('meta.per_page'), 'the picker asks at 1000');
    }

    // ---- salary structures -------------------------------------------------

    public function test_salary_structures_sort_by_effective_date_and_still_filter_by_employee(): void
    {
        $anithaJan = SalaryStructure::create(['employee_id' => $this->anitha->id, 'effective_from' => '2026-01-01']);
        $balaApr = SalaryStructure::create(['employee_id' => $this->bala->id, 'effective_from' => '2026-04-01']);
        $anithaApr = SalaryStructure::create(['employee_id' => $this->anitha->id, 'effective_from' => '2026-04-01']);

        $this->list('salary-structures', ['sort' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('salary-structures', ['sort' => 'employee'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('salary-structures', ['employee_id' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['employee_id']);
        $this->list('salary-structures', ['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        // Two of April, the newer id first, then January — and that is the bare order too.
        $this->assertSame([$anithaApr->id, $balaApr->id, $anithaJan->id], $this->idsOf('salary-structures', '-effective_from'));
        $this->assertSame([$anithaApr->id, $balaApr->id, $anithaJan->id], $this->list('salary-structures')->assertOk()->json('data.*.id'));
        $this->assertSame([$anithaJan->id, $anithaApr->id, $balaApr->id], $this->idsOf('salary-structures', 'effective_from'));

        // The employee filter this index has always taken still narrows, with the sort.
        $mine = $this->list('salary-structures', ['employee_id' => $this->anitha->id, 'sort' => 'effective_from'])->assertOk();
        $this->assertSame([$anithaJan->id, $anithaApr->id], $mine->json('data.*.id'));
        $this->assertSame(2, $mine->json('meta.total'));

        $this->assertPagesHonestly('salary-structures', 3);
    }

    // ---- helpers -----------------------------------------------------------

    private function slip(PayrollRun $run, Employee $employee, string $gross, string $deductions, string $net): Payslip
    {
        return Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'gross_earnings' => $gross,
            'total_deductions' => $deductions,
            'net_pay' => $net,
        ]);
    }
}
