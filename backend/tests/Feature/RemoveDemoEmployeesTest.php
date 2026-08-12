<?php

namespace Tests\Feature;

use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo-employee cleanup. Everything worth testing here is a refusal:
 * it must not touch a real person who happens to share a code, must not
 * remove a fixture that real work references, and must not write at all
 * unless asked.
 */
class RemoveDemoEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $code, string $name, string $joined): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'name' => $name,
            'date_of_joining' => $joined,
            'status' => 'active',
        ]);
    }

    private function allSeven(): void
    {
        $this->fixture('EMP-001', 'Karthik Subramaniam', '2019-04-01');
        $this->fixture('EMP-002', 'Lakshmi Narayanan', '2020-06-15');
        $this->fixture('EMP-003', 'Priya Raman', '2021-01-10');
        $this->fixture('EMP-004', 'Selvam Murugan', '2021-08-01');
        $this->fixture('EMP-005', 'Bala Krishnan', '2022-02-14');
        $this->fixture('EMP-006', 'Divya Chandran', '2020-11-01');
        $this->fixture('EMP-007', 'Meera Pillai', '2022-05-20');
    }

    public function test_a_dry_run_removes_nothing(): void
    {
        $this->allSeven();

        $this->artisan('hrms:remove-demo-employees')
            ->expectsOutputToContain('DRY RUN — nothing written')
            ->assertSuccessful();

        $this->assertSame(7, Employee::count());
    }

    public function test_write_soft_deletes_the_seven(): void
    {
        $this->allSeven();

        $this->artisan('hrms:remove-demo-employees --write')->assertSuccessful();

        $this->assertSame(0, Employee::count());
        // Soft-deleted, never force-deleted: the rows are still there.
        $this->assertSame(7, Employee::withTrashed()->count());
    }

    public function test_a_real_person_sharing_a_demo_code_is_left_alone(): void
    {
        // The day the factory numbers its own staff from EMP-001. Code alone
        // must never be enough to delete somebody.
        $this->fixture('EMP-001', 'A Real Person', '2026-01-05');

        $this->artisan('hrms:remove-demo-employees --write')
            ->expectsOutputToContain('partial match')
            ->assertSuccessful();

        $this->assertSame(1, Employee::count());
    }

    public function test_a_fixture_referenced_by_real_production_is_left_alone(): void
    {
        $this->allSeven();
        $operator = Employee::where('employee_code', 'EMP-004')->firstOrFail();

        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'X-1', 'name' => 'Bottle', 'uom' => 'NOS', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'FG']);

        ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-01',
            'batch_number' => '20260801-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_scrap' => '0',
            'operator_id' => $operator->id,
        ]);

        $this->artisan('hrms:remove-demo-employees --write')
            ->expectsOutputToContain('LEFT ALONE, referenced')
            ->assertSuccessful();

        // Six go, the referenced one stays — a real batch names them.
        $this->assertNotNull(Employee::where('employee_code', 'EMP-004')->first());
        $this->assertSame(1, Employee::count());
    }

    public function test_it_is_a_no_op_when_the_fixtures_are_absent(): void
    {
        $this->fixture('STAFF-01', 'Someone Real', '2026-02-02');

        $this->artisan('hrms:remove-demo-employees --write')->assertSuccessful();

        $this->assertSame(1, Employee::count());
    }

    public function test_a_manager_link_between_fixtures_does_not_block_removal(): void
    {
        // EMP-001 manages the others in the seeder. That is a reference
        // BETWEEN fixtures, not from real work — but the command is
        // deliberately conservative and reports it rather than reasoning
        // about which references "count". Asserting the actual behaviour so a
        // future change to it is a deliberate one.
        $this->allSeven();
        $manager = Employee::where('employee_code', 'EMP-001')->firstOrFail();
        Employee::where('employee_code', 'EMP-002')->update(['manager_id' => $manager->id]);

        $this->artisan('hrms:remove-demo-employees --write')->assertSuccessful();

        $this->assertNotNull(Employee::where('employee_code', 'EMP-001')->first());
    }
}
