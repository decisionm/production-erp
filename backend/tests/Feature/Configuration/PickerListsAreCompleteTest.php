<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Finance\Models\Enums\GLAccountType;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\HRMS\Models\LeaveType;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Payroll\Models\Enums\SalaryCalculationType;
use App\Modules\Payroll\Models\Enums\SalaryComponentType;
use App\Modules\Payroll\Models\SalaryComponent;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four masters whose PICKER now filters retired rows out, and whose list
 * endpoint could not serve a picker until this pass.
 *
 * WS-B widened the refusal set; the picker fix (Phase 7.6 FIX-2) stops the
 * dropdown offering a row the FormRequest would refuse. But a client-side
 * filter is only honest if the client HAS every row: these four endpoints
 * ignored `per_page` and always served the first 20, so filtering them would
 * have hidden some of a list that was already truncated — the account the
 * bookkeeper needs simply absent, with nothing on screen saying so. (That is
 * the same defect the item and vendor pickers were fixed for on 12-Aug; the
 * `perPage()` helper on the base Controller exists for exactly this.)
 *
 * So this pins BOTH halves: the default page size is unchanged for every
 * existing caller, and a picker asking for the full list gets it.
 */
class PickerListsAreCompleteTest extends TestCase
{
    use RefreshDatabase;

    /** More than the 20-row default, so a truncated answer is visible. */
    private const ROWS = 25;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create(['name' => 'Picker Admin', 'is_active' => true]);
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);
    }

    /**
     * @param  callable(int): void  $make
     */
    private function assertPickerCanReadEveryRow(string $endpoint, callable $make): void
    {
        for ($i = 1; $i <= self::ROWS; $i++) {
            $make($i);
        }

        // Unchanged for every existing caller: the paged list still pages.
        $this->getJson($endpoint)->assertOk()->assertJsonCount(20, 'data');

        // And the picker, which must show every ACTIVE row, gets all of them.
        $this->getJson($endpoint.'?per_page=1000')->assertOk()->assertJsonCount(self::ROWS, 'data');
    }

    public function test_the_gl_account_picker_can_read_every_account(): void
    {
        $this->assertPickerCanReadEveryRow(
            '/api/v1/finance/gl-accounts',
            fn (int $i) => GLAccount::create([
                'code' => sprintf('%04d', 1000 + $i),
                'name' => "Account {$i}",
                'type' => GLAccountType::Asset,
                'is_active' => true,
            ]),
        );
    }

    public function test_the_leave_type_picker_can_read_every_type(): void
    {
        $this->assertPickerCanReadEveryRow(
            '/api/v1/hrms/leave-types',
            fn (int $i) => LeaveType::create([
                'code' => "LT{$i}",
                'name' => "Leave Type {$i}",
                'default_annual_days' => 1,
            ]),
        );
    }

    public function test_the_salary_component_picker_can_read_every_component(): void
    {
        $this->assertPickerCanReadEveryRow(
            '/api/v1/payroll/salary-components',
            fn (int $i) => SalaryComponent::create([
                'code' => "SC{$i}",
                'name' => "Component {$i}",
                'type' => SalaryComponentType::Earning,
                'calculation_type' => SalaryCalculationType::FixedAmount,
            ]),
        );
    }

    public function test_the_asset_picker_can_read_every_asset(): void
    {
        $this->assertPickerCanReadEveryRow(
            '/api/v1/maintenance/assets',
            fn (int $i) => Asset::create(['code' => "A-{$i}", 'name' => "Asset {$i}"]),
        );
    }

    /** The clamp the base Controller already applies is not bypassed here. */
    public function test_a_picker_cannot_ask_for_an_unbounded_page(): void
    {
        for ($i = 1; $i <= self::ROWS; $i++) {
            Asset::create(['code' => "A-{$i}", 'name' => "Asset {$i}"]);
        }

        $response = $this->getJson('/api/v1/maintenance/assets?per_page=100000')->assertOk();

        $this->assertSame(1000, $response->json('meta.per_page'));
    }
}
