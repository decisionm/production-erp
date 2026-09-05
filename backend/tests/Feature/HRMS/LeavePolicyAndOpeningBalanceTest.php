<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\LeaveBalance;
use App\Modules\HRMS\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * LEAVE POLICY AND THE BALANCE SOMEBODY CARRIED IN (Phase 1).
 *
 * Two facts the leave model could not hold before. A type accrues by the
 * MONTH — the factory grants 1 CL and 1 SL a month — where the only number
 * a type carried was an annual one. And a balance can be CARRIED IN: one
 * person opens on 47.5 CL, which is not something the ERP granted and must
 * not read as if it were.
 *
 * So `allocated_days` stays what it always was, the total granted, and
 * `opening_days` records how much of that total was carried in. Accrued is
 * the difference, which is why no third column exists and why the two can
 * never disagree.
 *
 * Every figure here is synthetic.
 */
class LeavePolicyAndOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anand;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anand = Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'ANAND', 'date_of_joining' => '2026-01-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
        ]);

        $this->casual = LeaveType::create([
            'code' => 'CL', 'name' => 'Casual Leave', 'default_annual_days' => 7, 'is_active' => true,
        ]);
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('hrms.manage', 'web');
        $user->givePermissionTo('hrms.manage');
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    // ---- the policy -----------------------------------------------------------------

    public function test_a_leave_type_carries_a_monthly_increment_and_reports_it(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/hrms/leave-types', [
            'code' => 'SL', 'name' => 'Sick Leave', 'default_annual_days' => 12, 'monthly_accrual_days' => 1,
        ])->assertCreated()->assertJsonPath('data.monthly_accrual_days', '1.00');

        $this->getJson('/api/v1/hrms/leave-types')
            ->assertOk()
            ->assertJsonPath('data.0.monthly_accrual_days', '0.00');
    }

    public function test_a_type_that_does_not_accrue_monthly_is_the_default(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/hrms/leave-types', [
            'code' => 'EL', 'name' => 'Earned Leave', 'default_annual_days' => 12,
        ])->assertCreated()->assertJsonPath('data.monthly_accrual_days', '0.00');
    }

    public function test_the_increment_can_be_adjusted_and_refuses_a_negative(): void
    {
        $this->actAs();

        $this->putJson("/api/v1/hrms/leave-types/{$this->casual->id}", [
            'code' => 'CL', 'name' => 'Casual Leave', 'default_annual_days' => 7, 'monthly_accrual_days' => 1,
        ])->assertOk()->assertJsonPath('data.monthly_accrual_days', '1.00');

        $this->putJson("/api/v1/hrms/leave-types/{$this->casual->id}", [
            'code' => 'CL', 'name' => 'Casual Leave', 'default_annual_days' => 7, 'monthly_accrual_days' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['monthly_accrual_days']);
    }

    // ---- the balance somebody carried in ---------------------------------------------

    public function test_an_opening_balance_is_carried_in_and_read_back_apart_from_what_was_accrued(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/hrms/leave-balances', [
            'employee_id' => $this->anand->id,
            'leave_type_id' => $this->casual->id,
            'year' => 2026,
            'opening_days' => 47.5,
        ])->assertCreated()
            ->assertJsonPath('data.opening_days', '47.50')
            ->assertJsonPath('data.allocated_days', '47.50')
            ->assertJsonPath('data.accrued_days', '0.00')
            ->assertJsonPath('data.used_days', '0.00')
            ->assertJsonPath('data.remaining_days', '47.50');
    }

    public function test_accrued_is_whatever_the_allocation_holds_beyond_the_opening_balance(): void
    {
        $this->actAs();

        $balance = LeaveBalance::create([
            'employee_id' => $this->anand->id,
            'leave_type_id' => $this->casual->id,
            'year' => 2026,
            'opening_days' => 5,
            'allocated_days' => 8,
            'used_days' => 2,
        ]);

        $this->getJson('/api/v1/hrms/leave-balances')
            ->assertOk()
            ->assertJsonPath('data.0.opening_days', '5.00')
            ->assertJsonPath('data.0.accrued_days', '3.00')
            ->assertJsonPath('data.0.used_days', '2.00')
            ->assertJsonPath('data.0.remaining_days', '6.00');

        $this->assertSame('5.00', $balance->fresh()->opening_days);
    }

    public function test_an_allocation_with_no_opening_balance_still_falls_back_to_the_annual_default(): void
    {
        $this->actAs();

        // The behaviour before this phase, unchanged: no figure given at all
        // means the type's annual default, and nothing was carried in.
        $this->postJson('/api/v1/hrms/leave-balances', [
            'employee_id' => $this->anand->id,
            'leave_type_id' => $this->casual->id,
            'year' => 2026,
        ])->assertCreated()
            ->assertJsonPath('data.allocated_days', '7.00')
            ->assertJsonPath('data.opening_days', '0.00')
            ->assertJsonPath('data.accrued_days', '7.00');
    }

    public function test_an_opening_balance_may_not_exceed_the_allocation_it_sits_inside(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/hrms/leave-balances', [
            'employee_id' => $this->anand->id,
            'leave_type_id' => $this->casual->id,
            'year' => 2026,
            'opening_days' => 10,
            'allocated_days' => 4,
        ])->assertUnprocessable()->assertJsonValidationErrors(['opening_days']);
    }

    // ---- what the person themselves sees ---------------------------------------------

    public function test_my_attendance_shows_the_person_their_own_leave_balance(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->anand->update(['user_id' => $user->id]);
        Sanctum::actingAs($user->fresh());

        LeaveBalance::create([
            'employee_id' => $this->anand->id,
            'leave_type_id' => $this->casual->id,
            'year' => 2026,
            'opening_days' => 47.5,
            'allocated_days' => 49.5,
            'used_days' => 1.5,
        ]);

        $this->getJson('/api/v1/hrms/attendance/me?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.leave_balances.0.code', 'CL')
            ->assertJsonPath('data.leave_balances.0.opening_days', '47.50')
            ->assertJsonPath('data.leave_balances.0.accrued_days', '2.00')
            ->assertJsonPath('data.leave_balances.0.used_days', '1.50')
            ->assertJsonPath('data.leave_balances.0.remaining_days', '48.00');
    }

    public function test_a_login_with_no_employee_behind_it_sees_no_balances_rather_than_an_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/hrms/attendance/me?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.leave_balances', []);
    }

    public function test_the_balance_shown_is_the_year_the_range_ends_in(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->anand->update(['user_id' => $user->id]);
        Sanctum::actingAs($user->fresh());

        LeaveBalance::create([
            'employee_id' => $this->anand->id, 'leave_type_id' => $this->casual->id,
            'year' => 2025, 'opening_days' => 0, 'allocated_days' => 3, 'used_days' => 0,
        ]);

        $this->getJson('/api/v1/hrms/attendance/me?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.leave_balances', []);
    }

    public function test_an_opening_balance_refuses_a_negative(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/hrms/leave-balances', [
            'employee_id' => $this->anand->id,
            'leave_type_id' => $this->casual->id,
            'year' => 2026,
            'opening_days' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['opening_days']);
    }
}
