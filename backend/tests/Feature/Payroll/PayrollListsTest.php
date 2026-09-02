<?php

namespace Tests\Feature\Payroll;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Payroll\Models\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The two Payroll lists become SEARCHABLE and PAGED on the server, through
 * FormRequest-validated query strings (ListPayrollRunsRequest,
 * ListPayslipsRequest). What must hold:
 *
 *   - `q` on runs names a PERIOD in the spellings people type ("aug",
 *     "August 2026", "2026-08", "08/2026", "2026") or a status word, and a
 *     term that names no period matches NO run rather than every run;
 *   - `q` on payslips finds the employee by name or code through the
 *     relation, and composes with the run filter the page has always sent;
 *   - `meta.total` counts the whole filtered collection, whatever the page;
 *   - `per_page` is 1..100 (default 20) and anything else is a 422, as is a
 *     status that does not exist or a non-integer id;
 *   - an EMPTY query string is exactly the list every earlier caller got —
 *     newest period first, newest payslip first;
 *   - the whole surface is behind payroll.view (403 without it).
 *
 * Rows are built directly — these are list tests; no payroll is computed,
 * and every figure is synthetic.
 */
class PayrollListsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anitha;

    private Employee $bala;

    /** @var array<string, PayrollRun> */
    private array $runs = [];

    /** @var array<string, Payslip> */
    private array $slips = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['payroll.view']);

        $this->anitha = Employee::create(['employee_code' => 'RG-E1', 'name' => 'Anitha Kumar', 'date_of_joining' => '2026-01-01']);
        $this->bala = Employee::create(['employee_code' => 'RG-E2', 'name' => 'Bala Murugan', 'date_of_joining' => '2026-01-01']);

        // Created oldest-first on purpose: the list must order by PERIOD, not
        // by insertion or id.
        $this->runs['dec25'] = PayrollRun::create(['year' => 2025, 'month' => 12, 'status' => PayrollRunStatus::Paid]);
        $this->runs['aug26'] = PayrollRun::create(['year' => 2026, 'month' => 8, 'status' => PayrollRunStatus::Draft]);
        $this->runs['jul26'] = PayrollRun::create(['year' => 2026, 'month' => 7, 'status' => PayrollRunStatus::Processed]);

        $this->slips['anitha_jul'] = $this->slip($this->runs['jul26'], $this->anitha);
        $this->slips['anitha_aug'] = $this->slip($this->runs['aug26'], $this->anitha);
        $this->slips['bala_aug'] = $this->slip($this->runs['aug26'], $this->bala);
    }

    // ---- payroll runs ---------------------------------------------------------

    public function test_runs_list_newest_period_first_with_the_servers_own_page_meta(): void
    {
        $this->assertOrder(['aug26', 'jul26', 'dec25'], $this->runs, $this->list('runs'));

        $response = $this->list('runs');
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(20, $response->json('meta.per_page'));

        $page = $this->list('runs', ['per_page' => 1]);
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(3, $page->json('meta.total'), 'the total is the whole collection, not the page');
        $this->assertSame(3, $page->json('meta.last_page'));

        $this->assertOrder(['jul26'], $this->runs, $this->list('runs', ['per_page' => 1, 'page' => 2]));
    }

    public function test_runs_q_names_a_period_in_the_spellings_people_type_or_a_status(): void
    {
        foreach (['aug', 'Aug', 'august', 'August 2026', '2026 aug', '2026-08', '2026-8', '08/2026', '8/2026', '2026.08'] as $spelling) {
            $this->assertIds(['aug26'], $this->runs, $this->list('runs', ['q' => $spelling]), "q={$spelling}");
        }

        $this->assertIds(['jul26', 'aug26'], $this->runs, $this->list('runs', ['q' => '2026']));
        $this->assertIds(['dec25'], $this->runs, $this->list('runs', ['q' => '12']));
        $this->assertIds(['dec25'], $this->runs, $this->list('runs', ['q' => 'december']));
        $this->assertIds(['dec25'], $this->runs, $this->list('runs', ['q' => 'paid']));
        $this->assertIds(['jul26'], $this->runs, $this->list('runs', ['q' => 'processed 2026']));

        // A term that names no period names no run — never the whole list.
        $this->assertIds([], $this->runs, $this->list('runs', ['q' => 'zzz']));
        $this->assertIds([], $this->runs, $this->list('runs', ['q' => '%%%']));
        $this->assertIds([], $this->runs, $this->list('runs', ['q' => '2026 2025']), 'two years name nothing');
        $this->assertIds([], $this->runs, $this->list('runs', ['q' => 'ma']), 'two letters do not pick between March and May');
        $this->assertIds([], $this->runs, $this->list('runs', ['q' => 'aug 2025']), 'a period with no run');
    }

    public function test_runs_filter_by_status_and_refuse_malformed_query_strings(): void
    {
        $this->assertIds(['jul26'], $this->runs, $this->list('runs', ['status' => 'processed']));
        $this->assertIds(['dec25'], $this->runs, $this->list('runs', ['status' => 'paid', 'q' => '2025']));
        $this->assertIds([], $this->runs, $this->list('runs', ['status' => 'paid', 'q' => '2026']));

        // An empty q is no filter, not a malformed one.
        $this->getJson('/api/v1/payroll/runs?q=')->assertOk()->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/payroll/runs?status=bogus')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/payroll/runs?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/payroll/runs?per_page=101')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/payroll/runs?per_page=abc')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/payroll/runs?page=0')->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->getJson('/api/v1/payroll/runs?q='.str_repeat('a', 101))->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    // ---- payslips -------------------------------------------------------------

    public function test_payslips_filter_by_run_and_employee_and_q_matches_the_employee_by_name_or_code(): void
    {
        $this->assertOrder(['bala_aug', 'anitha_aug', 'anitha_jul'], $this->slips, $this->list('payslips'));

        $this->assertIds(['anitha_aug', 'bala_aug'], $this->slips, $this->list('payslips', ['payroll_run_id' => $this->runs['aug26']->id]));
        $this->assertIds(['anitha_jul', 'anitha_aug'], $this->slips, $this->list('payslips', ['employee_id' => $this->anitha->id]));

        $this->assertIds(['anitha_jul', 'anitha_aug'], $this->slips, $this->list('payslips', ['q' => 'anitha']));
        $this->assertIds(['anitha_jul', 'anitha_aug'], $this->slips, $this->list('payslips', ['q' => 'KUMAR']));
        $this->assertIds(['bala_aug'], $this->slips, $this->list('payslips', ['q' => 'rg-e2']));
        $this->assertIds(['anitha_aug'], $this->slips, $this->list('payslips', ['q' => 'anitha', 'payroll_run_id' => $this->runs['aug26']->id]));
        $this->assertIds([], $this->slips, $this->list('payslips', ['q' => 'bala', 'payroll_run_id' => $this->runs['jul26']->id]));
        $this->assertIds([], $this->slips, $this->list('payslips', ['q' => 'zzz']));
        // The typed % and _ are characters, not wildcards.
        $this->assertIds([], $this->slips, $this->list('payslips', ['q' => '%%%']));
        $this->assertIds([], $this->slips, $this->list('payslips', ['q' => 'rg_e2']));

        $page = $this->list('payslips', ['per_page' => 2]);
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));
        $this->assertOrder(['anitha_jul'], $this->slips, $this->list('payslips', ['per_page' => 2, 'page' => 2]));
    }

    public function test_payslips_refuse_malformed_query_strings(): void
    {
        $this->getJson('/api/v1/payroll/payslips?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/payroll/payslips?per_page=101')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/payroll/payslips?payroll_run_id=abc')->assertStatus(422)->assertJsonValidationErrors(['payroll_run_id']);
        $this->getJson('/api/v1/payroll/payslips?employee_id=0')->assertStatus(422)->assertJsonValidationErrors(['employee_id']);
        $this->getJson('/api/v1/payroll/payslips?q='.str_repeat('a', 101))->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_the_lists_are_behind_payroll_view(): void
    {
        $this->actingWith(['hrms.view', 'finance.view']);

        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->getJson('/api/v1/payroll/payslips')->assertForbidden();
    }

    // ---- helpers ----------------------------------------------------------------

    private function slip(PayrollRun $run, Employee $employee): Payslip
    {
        return Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'gross_earnings' => '1.0000',
            'total_deductions' => '0.0000',
            'net_pay' => '1.0000',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $query */
    private function list(string $path, array $query = []): TestResponse
    {
        return $this->getJson("/api/v1/payroll/{$path}".($query === [] ? '' : '?'.http_build_query($query)))->assertOk();
    }

    /**
     * @param  list<string>  $expectedKeys
     * @param  array<string, Model>  $fixtures
     */
    private function assertIds(array $expectedKeys, array $fixtures, TestResponse $response, string $message = ''): void
    {
        $expected = collect($expectedKeys)->map(fn ($key) => $fixtures[$key]->id)->sort()->values()->all();
        $actual = collect($response->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $actual, $message);
        $this->assertSame(count($expected), $response->json('meta.total'), $message);
    }

    /**
     * @param  list<string>  $expectedKeys
     * @param  array<string, Model>  $fixtures
     */
    private function assertOrder(array $expectedKeys, array $fixtures, TestResponse $response, string $message = ''): void
    {
        $expected = collect($expectedKeys)->map(fn ($key) => $fixtures[$key]->id)->all();
        $actual = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame($expected, $actual, $message);
    }
}
