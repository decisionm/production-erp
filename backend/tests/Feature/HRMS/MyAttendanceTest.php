<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\AttendanceImport;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * YOUR OWN ATTENDANCE IS YOURS.
 *
 * The factory's attendance is a management read and stays behind the HRMS
 * permission. A person's own month is not: a packer who may not open the
 * Employees page still has a right to see what has been recorded against
 * their name, and to say so before payroll is run off it.
 *
 * The whole of the authorisation is that the read takes NO EMPLOYEE — it
 * answers for whoever is asking, so there is no parameter to point at
 * somebody else.
 */
class MyAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $permissions */
    private function login(array $permissions = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function employeeFor(?User $user, string $code = 'SPP-01', string $name = 'Anand'): Employee
    {
        return Employee::create([
            'employee_code' => $code, 'name' => $name, 'date_of_joining' => '2026-01-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
            'user_id' => $user?->id,
        ]);
    }

    public function test_a_login_with_no_hrms_permission_still_sees_its_own_month(): void
    {
        $user = $this->login();
        $employee = $this->employeeFor($user);
        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-07-01', 'status' => 'present']);

        $response = $this->getJson('/api/v1/hrms/attendance/me?from=2026-07-01&to=2026-07-31')->assertOk();

        $this->assertSame('Anand', $response->json('data.employee.name'));
        $this->assertSame(['present'], $response->json('data.days.*.status'));
        $this->assertSame(1, $response->json('data.summary.present'));
    }

    public function test_the_same_login_is_still_refused_everybody_elses_attendance(): void
    {
        $user = $this->login();
        $this->employeeFor($user);

        // The permission gate on the factory's reads is untouched by the
        // one door opened above.
        $this->getJson('/api/v1/hrms/attendance/summary?from=2026-07-01&to=2026-07-31')->assertForbidden();
        $this->getJson('/api/v1/hrms/attendance?from=2026-07-01&to=2026-07-31')->assertForbidden();
    }

    public function test_a_login_with_no_employee_behind_it_gets_an_empty_month_not_an_error(): void
    {
        $this->login();
        // An employee row linked to nobody is not mine by proximity.
        $this->employeeFor(null);

        $response = $this->getJson('/api/v1/hrms/attendance/me?from=2026-07-01&to=2026-07-31')->assertOk();

        $this->assertNull($response->json('data.employee'));
        $this->assertSame([], $response->json('data.days'));
        $this->assertSame(0, $response->json('data.summary.recorded'));
    }

    public function test_my_month_reads_the_upload_and_counts_its_mismatches(): void
    {
        $user = $this->login();
        $employee = $this->employeeFor($user);
        $import = AttendanceImport::create([
            'source' => 'pooja', 'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
            'file_name' => 'july.xlsx', 'uploaded_by' => $user->id, 'status' => 'review',
        ]);
        foreach ([
            ['2026-07-01', 'present', null],
            ['2026-07-02', 'present', 'in_no_out'],
            ['2026-07-03', null, 'no_punch'],
        ] as [$date, $resolution, $issue]) {
            AttendanceImportLine::create([
                'attendance_import_id' => $import->id, 'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code, 'employee_name' => $employee->name,
                'date' => $date, 'raw_status' => 'FD', 'first_in' => '06:00',
                'last_out' => $resolution === null ? null : '14:00',
                'worked_minutes' => $resolution === null ? 0 : 480,
                'issue' => $issue, 'resolution' => $resolution,
            ]);
        }

        $summary = $this->getJson('/api/v1/hrms/attendance/me?from=2026-07-01&to=2026-07-31')
            ->assertOk()->json('data.summary');

        $this->assertSame(2, $summary['present']);
        $this->assertSame(1, $summary['needs_review']);
        $this->assertSame(3, $summary['from_import'], 'nobody has applied this month');
        // TWO mismatches, not one: answering the 2nd settled the day, it did
        // not unmake the fact that the report came in wrong.
        $this->assertSame(2, $summary['mismatches']);
    }

    public function test_the_range_is_validated_like_every_other_attendance_read(): void
    {
        $this->login();

        $this->getJson('/api/v1/hrms/attendance/me?from=2026-07-31&to=2026-07-01')
            ->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/hrms/attendance/me?from=01-07-2026&to=2026-07-31')
            ->assertStatus(422)->assertJsonValidationErrors(['from']);
    }

    public function test_a_stranger_is_not_answered_at_all(): void
    {
        $this->getJson('/api/v1/hrms/attendance/me?from=2026-07-01&to=2026-07-31')->assertUnauthorized();
    }
}
