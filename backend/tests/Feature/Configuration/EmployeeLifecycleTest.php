<?php

namespace Tests\Feature\Configuration;

use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\EmployeeStatus;
use App\Modules\HRMS\Models\LeaveBalance;
use App\Modules\HRMS\Models\LeaveRequest;
use App\Modules\HRMS\Models\LeaveType;
use App\Modules\HRMS\Services\EmployeeService;
use App\Modules\Payroll\Models\SalaryStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * THE EMPLOYEE MASTER under the Configuration Lifecycle Contract
 * (DEC-20260817-002) — Create · View · Edit · Activate/Deactivate ·
 * Safe Delete · Audit.
 *
 * This is the entity the audit called the most dangerous parent in the
 * schema: four tables cascade from `employees` with NO database backstop
 * (attendance and statutory payroll history), and six more are SET NULL,
 * which the shipped cascade backstop cannot see at all. So the delete tests
 * here do two things rather than one — they assert the REFUSAL, and they
 * assert every cascade-side child is STILL THERE afterwards.
 */
class EmployeeLifecycleTest extends ProductDefinitionLifecycleTestCase
{
    use RefreshDatabase;

    private const MODULE = ['hrms.view', 'hrms.manage'];

    private function employee(string $code = 'EMP-1', array $overrides = []): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'name' => 'Operator '.$code,
            'date_of_joining' => '2026-01-01',
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function service(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    // ---- Create --------------------------------------------------------

    public function test_an_employee_is_created_viewed_and_edited_through_the_module_grant(): void
    {
        $user = $this->moduleUser(...self::MODULE);

        $created = $this->actingAs($user)->postJson('/api/v1/hrms/employees', [
            'employee_code' => 'EMP-100',
            'name' => 'Vincent',
            'date_of_joining' => '2026-02-01',
        ]);

        $created->assertStatus(201);
        $id = $created->json('data.id');

        $shown = $this->actingAs($user)->getJson("/api/v1/hrms/employees/{$id}");
        $shown->assertOk();
        $this->assertSame('EMP-100', $shown->json('data.employee_code'));
        $this->assertFalse($shown->json('data.is_archived'));

        $edited = $this->actingAs($user)->putJson("/api/v1/hrms/employees/{$id}", ['designation' => 'Supervisor']);
        $edited->assertOk();
        $this->assertSame('Supervisor', $edited->json('data.designation'));
    }

    public function test_a_duplicate_employee_code_is_refused_even_when_the_holder_is_archived(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $employee = $this->employee('EMP-DUP');

        // Archived by soft delete — the strongest form of "gone" this master
        // has short of a hard delete. Its code stays taken (§2).
        $employee->delete();

        $this->actingAs($user)
            ->postJson('/api/v1/hrms/employees', [
                'employee_code' => 'EMP-DUP',
                'name' => 'Someone else',
                'date_of_joining' => '2026-03-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_code');
    }

    // ---- Activate / Deactivate ----------------------------------------

    public function test_archive_takes_an_employee_out_of_service_and_activate_puts_them_back(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $employee = $this->employee('EMP-AD');

        $archived = $this->actingAs($user)->postJson("/api/v1/hrms/employees/{$employee->id}/archive", [
            'reason' => 'On long leave',
        ]);

        $archived->assertOk();
        $this->assertSame('inactive', $archived->json('data.status'));
        $this->assertFalse($archived->json('data.can.archive'), 'nothing left to archive');
        $this->assertTrue($archived->json('data.can.activate'));

        // Reversible, and it destroys nothing: the row is still there, and
        // still the same row.
        $this->assertSame(1, Employee::query()->whereKey($employee->id)->count());

        $back = $this->actingAs($user)->postJson("/api/v1/hrms/employees/{$employee->id}/activate");
        $back->assertOk();
        $this->assertSame('active', $back->json('data.status'));
    }

    public function test_a_terminated_employee_is_never_offered_deactivate(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $employee = $this->employee('EMP-TERM', ['status' => EmployeeStatus::Terminated]);

        $shown = $this->actingAs($user)->getJson("/api/v1/hrms/employees/{$employee->id}");

        $shown->assertOk();
        $this->assertFalse(
            $shown->json('data.can.archive'),
            'archiving a terminated employee would silently downgrade the termination to "inactive"',
        );
        $this->assertTrue($shown->json('data.can.activate'), 'a re-hire stays possible');

        // And the write refuses too, not just the button — the server
        // enforces what it decided.
        $this->actingAs($user)
            ->postJson("/api/v1/hrms/employees/{$employee->id}/archive")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_an_archived_employee_leaves_new_selection_while_history_still_renders(): void
    {
        $user = $this->moduleUser('hrms.view', 'hrms.manage', 'production.view', 'production.manage');
        $employee = $this->employee('EMP-SEL');
        $leaveType = LeaveType::create(['code' => 'CL', 'name' => 'Casual', 'default_annual_days' => 12, 'is_active' => true]);

        // History first, while they are in service.
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-02',
            'days' => 2,
            'status' => 'pending',
        ]);

        $this->service()->archive($employee->fresh());

        // NEW selection is closed.
        $this->actingAs($user)
            ->postJson('/api/v1/hrms/leave-requests', [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-01',
                'days' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');

        // The history is untouched and still readable.
        $this->assertNotNull(LeaveRequest::query()->find($request->id));
        $this->actingAs($user)
            ->getJson("/api/v1/hrms/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_code', 'EMP-SEL');
    }

    public function test_a_read_only_user_is_offered_nothing_and_a_stale_button_is_a_422_not_a_500(): void
    {
        $reader = $this->moduleUser('hrms.view');
        $employee = $this->employee('EMP-READ');

        // What the RECORD allows and what the ROLE allows are different
        // questions; `can` is their intersection, so a view-only user is not
        // handed four buttons that would each 403.
        $this->actingAs($reader)
            ->getJson("/api/v1/hrms/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.can', ['edit' => false, 'activate' => false, 'archive' => false, 'delete' => false]);

        // And a second Archive from a stale tab is a business refusal, not a
        // crash.
        $writer = $this->moduleUser(...self::MODULE);
        $this->actingAs($writer)->postJson("/api/v1/hrms/employees/{$employee->id}/archive")->assertOk();
        $this->actingAs($writer)
            ->postJson("/api/v1/hrms/employees/{$employee->id}/archive")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // ---- Safe delete ---------------------------------------------------

    public function test_the_hard_delete_is_refused_for_a_module_user_without_the_owner_tier(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $employee = $this->employee('EMP-403');

        $this->actingAs($user)
            ->deleteJson("/api/v1/hrms/employees/{$employee->id}")
            ->assertStatus(403);

        $this->assertNotNull(Employee::withTrashed()->find($employee->id));

        // The same user may still archive — the tier narrows ONE verb.
        $this->actingAs($user)
            ->postJson("/api/v1/hrms/employees/{$employee->id}/archive")
            ->assertOk();
    }

    public function test_a_referenced_employee_is_refused_with_counts_and_every_cascade_child_survives(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $employee = $this->employee('EMP-USED');
        $leaveType = LeaveType::create(['code' => 'EL', 'name' => 'Earned', 'default_annual_days' => 12, 'is_active' => true]);

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-04-01',
            'status' => 'present',
        ]);
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'allocated_days' => '12',
            'used_days' => '0',
        ]);
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-04-05',
            'end_date' => '2026-04-06',
            'days' => 2,
            'status' => 'pending',
        ]);
        $structure = SalaryStructure::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
        ]);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/hrms/employees/{$employee->id}");

        $response->assertStatus(422);
        $this->assertSame('configuration_in_use', $response->json('code'));
        $this->assertSame('archive', $response->json('alternative'));

        $blocking = collect($response->json('blocking'))->keyBy('code');
        foreach (['attendances', 'leave_balances', 'leave_requests', 'salary_structures'] as $code) {
            $this->assertTrue($blocking->has($code), "{$code} must be named in the refusal");
            $this->assertIsInt($blocking[$code]['count']);
            $this->assertSame(1, $blocking[$code]['count']);
        }

        // THE POINT OF THIS TEST. Every cascade-side child is still there —
        // refusing must never have been implemented as "delete the parent and
        // let the database tidy up".
        $this->assertNotNull(Attendance::query()->find($attendance->id), 'attendance history destroyed');
        $this->assertNotNull(LeaveBalance::query()->find($balance->id), 'leave balance destroyed');
        $this->assertNotNull(LeaveRequest::query()->find($request->id), 'leave request destroyed');
        $this->assertNotNull(SalaryStructure::query()->find($structure->id), 'salary structure destroyed');
        $this->assertNotNull(Employee::withTrashed()->find($employee->id), 'the employee itself was destroyed');
    }

    public function test_a_set_null_reference_blocks_the_delete_too(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $manager = $this->employee('EMP-MGR');
        $this->employee('EMP-REPORT', ['manager_id' => $manager->id]);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/hrms/employees/{$manager->id}");

        // `employees.manager_id` is ON DELETE SET NULL: the database would
        // let the delete through and quietly blank the report's manager.
        // Only the declaration stops it.
        $response->assertStatus(422);
        $this->assertSame(
            ['employees'],
            collect($response->json('blocking'))->pluck('code')->all(),
        );
        $this->assertSame(1, $response->json('blocking.0.count'));
    }

    public function test_an_unused_employee_is_really_deleted_and_the_code_is_freed(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $employee = $this->employee('EMP-FREE');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/hrms/employees/{$employee->id}")
            ->assertNoContent();

        // Really gone — not soft-deleted, which would keep the code reserved.
        $this->assertNull(Employee::withTrashed()->find($employee->id));

        $this->actingAs($owner)
            ->postJson('/api/v1/hrms/employees', [
                'employee_code' => 'EMP-FREE',
                'name' => 'The next holder',
                'date_of_joining' => '2026-06-01',
            ])
            ->assertStatus(201);
    }

    public function test_an_archived_employee_may_still_be_hard_deleted_when_provably_unused(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $employee = $this->employee('EMP-ARCH');
        $employee->delete();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/hrms/employees/{$employee->id}")
            ->assertNoContent();

        $this->assertNull(Employee::withTrashed()->find($employee->id));
    }

    // ---- The declaration itself ---------------------------------------

    public function test_every_undefended_reference_to_an_employee_is_declared(): void
    {
        $this->assertEveryUndefendedReferenceIsDeclared($this->service(), 'employees');
    }

    // ---- Audit ---------------------------------------------------------

    public function test_the_lifecycle_is_recorded_in_the_configuration_audit_trail(): void
    {
        $user = $this->moduleUser(...self::MODULE);

        $created = $this->actingAs($user)->postJson('/api/v1/hrms/employees', [
            'employee_code' => 'EMP-AUDIT',
            'name' => 'Audited',
            'date_of_joining' => '2026-02-01',
        ])->json('data.id');

        $employee = Employee::query()->findOrFail($created);

        $this->actingAs($user)->putJson("/api/v1/hrms/employees/{$created}", ['designation' => 'Fitter'])->assertOk();
        $this->actingAs($user)->postJson("/api/v1/hrms/employees/{$created}/archive")->assertOk();

        $trail = $this->auditTrailFor($employee);

        $this->assertSame(
            ['employee.created', 'employee.updated', 'employee.updated'],
            $trail->pluck('description')->all(),
        );
        $this->assertSame([$user->id, $user->id, $user->id], $trail->pluck('causer_id')->map(fn ($id) => (int) $id)->all());

        // …and the row itself carries who last touched it, without a join.
        $this->assertSame($user->id, (int) DB::table('employees')->where('id', $created)->value('updated_by'));
    }
}
