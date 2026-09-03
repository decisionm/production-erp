<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE ATTENDANCE PAGE'S TWO READS.
 *
 * ONE PERSON'S MONTH — pick somebody, see their days and what the month
 * came to. HR and supervisors open this to answer "how has this person
 * been"; it needs `hrms.view`.
 *
 * THE FACTORY BY DEPARTMENT — the same period totalled per department,
 * with the people carrying the most absence named. This is the management
 * read and needs `hrms.manage`, so a supervisor can look a person up
 * without being handed the whole factory's numbers.
 *
 * Both are pure reads. Every figure here is synthetic.
 */
class AttendanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anand;

    private Employee $bala;

    private Employee $chitra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anand = Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'Anand', 'date_of_joining' => '2026-01-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
        ]);
        $this->bala = Employee::create([
            'employee_code' => 'SPP-02', 'name' => 'Bala', 'date_of_joining' => '2026-01-01',
            'department' => 'Production Department', 'designation' => 'Machine Operator',
        ]);
        $this->chitra = Employee::create([
            'employee_code' => 'SPP-03', 'name' => 'Chitra', 'date_of_joining' => '2026-01-01',
            'department' => 'Stores Department', 'designation' => 'Store Incharge',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function mark(Employee $employee, string $date, string $status, ?string $in = null, ?string $out = null): void
    {
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => $status,
            'check_in' => $in,
            'check_out' => $out,
        ]);
    }

    /**
     * Anand: three present, one half, one absent, one leave.
     * Bala: two present, three absent.
     * Chitra, in another department: one present.
     */
    private function seedMonth(): void
    {
        $this->mark($this->anand, '2026-09-01', 'present', '2026-09-01 00:30:00', '2026-09-01 08:30:00');
        $this->mark($this->anand, '2026-09-02', 'present');
        $this->mark($this->anand, '2026-09-03', 'present');
        $this->mark($this->anand, '2026-09-04', 'half_day');
        $this->mark($this->anand, '2026-09-05', 'absent');
        $this->mark($this->anand, '2026-09-08', 'on_leave');

        $this->mark($this->bala, '2026-09-01', 'present');
        $this->mark($this->bala, '2026-09-02', 'present');
        $this->mark($this->bala, '2026-09-03', 'absent');
        $this->mark($this->bala, '2026-09-04', 'absent');
        $this->mark($this->bala, '2026-09-05', 'absent');

        $this->mark($this->chitra, '2026-09-01', 'present');
    }

    // ---- one person's month ---------------------------------------------

    public function test_one_persons_month_carries_who_they_are_their_days_and_the_totals(): void
    {
        $this->actAs(['hrms.view']);
        $this->seedMonth();

        $response = $this->getJson(
            "/api/v1/hrms/attendance/person?employee_id={$this->anand->id}&from=2026-09-01&to=2026-09-30"
        )->assertOk();

        $this->assertSame('SPP-01', $response->json('data.employee.employee_code'));
        $this->assertSame('Anand', $response->json('data.employee.name'));
        $this->assertSame('Production Department', $response->json('data.employee.department'));
        $this->assertSame('2026-09-01', $response->json('data.from'));
        $this->assertSame('2026-09-30', $response->json('data.to'));

        // In the master's own order — present, absent, half day, on leave —
        // then the three the upload fallback adds: a week off is not
        // attendance, an unanswered day is not yet anything, and a day read
        // from an unapplied upload is provisional.
        $this->assertSame([
            'present' => 3,
            'absent' => 1,
            'half_day' => 1,
            'on_leave' => 1,
            'recorded' => 6,
            'week_off' => 0,
            'needs_review' => 0,
            'from_import' => 0,
            'mismatches' => 0,
        ], $response->json('data.summary'));

        // The days come back in date order, oldest first, as a month reads.
        $this->assertSame(
            ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-08'],
            $response->json('data.days.*.date'),
        );
        $this->assertSame('present', $response->json('data.days.0.status'));
        $this->assertSame('on_leave', $response->json('data.days.5.status'));
    }

    public function test_a_person_with_nothing_recorded_says_so_rather_than_erroring(): void
    {
        $this->actAs(['hrms.view']);

        $response = $this->getJson(
            "/api/v1/hrms/attendance/person?employee_id={$this->chitra->id}&from=2026-09-01&to=2026-09-30"
        )->assertOk();

        $this->assertSame([], $response->json('data.days'));
        $this->assertSame(0, $response->json('data.summary.recorded'));
    }

    public function test_the_person_read_needs_a_real_employee_and_a_sane_range(): void
    {
        $this->actAs(['hrms.view']);

        $this->getJson('/api/v1/hrms/attendance/person?from=2026-09-01&to=2026-09-30')->assertUnprocessable();
        $this->getJson('/api/v1/hrms/attendance/person?employee_id=99999&from=2026-09-01&to=2026-09-30')
            ->assertUnprocessable();
        $this->getJson("/api/v1/hrms/attendance/person?employee_id={$this->anand->id}&from=2026-09-30&to=2026-09-01")
            ->assertUnprocessable();
    }

    // ---- the factory, by department --------------------------------------

    public function test_the_department_summary_totals_the_period_and_names_who_is_most_absent(): void
    {
        $this->actAs(['hrms.view', 'hrms.manage']);
        $this->seedMonth();

        $response = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-09-01&to=2026-09-30')->assertOk();

        // Departments in their own order — most recorded days first.
        $departments = $response->json('data.departments');
        $this->assertSame('Production Department', $departments[0]['department']);
        $this->assertSame(5, $departments[0]['present']);
        $this->assertSame(1, $departments[0]['half_day']);
        $this->assertSame(4, $departments[0]['absent']);
        $this->assertSame(1, $departments[0]['on_leave']);
        $this->assertSame(11, $departments[0]['recorded']);
        $this->assertSame(2, $departments[0]['employees']);

        $this->assertSame('Stores Department', $departments[1]['department']);
        $this->assertSame(1, $departments[1]['present']);

        // The factory's own line, so the reader is not adding up columns.
        $this->assertSame(6, $response->json('data.totals.present'));
        $this->assertSame(4, $response->json('data.totals.absent'));
        $this->assertSame(12, $response->json('data.totals.recorded'));
        $this->assertSame(3, $response->json('data.totals.employees'));

        // Most absent first, and only people who were actually absent.
        $absent = $response->json('data.most_absent');
        $this->assertSame('SPP-02', $absent[0]['employee_code']);
        $this->assertSame(3, $absent[0]['absent']);
        $this->assertSame('SPP-01', $absent[1]['employee_code']);
        $this->assertSame(1, $absent[1]['absent']);
        $this->assertCount(2, $absent, 'nobody who was never absent belongs on this list');
    }

    public function test_the_percentage_counts_a_half_day_as_half_and_says_so(): void
    {
        $this->actAs(['hrms.view', 'hrms.manage']);
        $this->seedMonth();

        $departments = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-09-01&to=2026-09-30')
            ->assertOk()->json('data.departments');

        // Production: 5 present + 1 half (0.5) out of 11 recorded = 50.0%.
        $this->assertSame(50.0, (float) $departments[0]['present_percent']);
        // Stores: the one day recorded was present.
        $this->assertSame(100.0, (float) $departments[1]['present_percent']);
    }

    public function test_the_range_actually_narrows_the_summary(): void
    {
        $this->actAs(['hrms.view', 'hrms.manage']);
        $this->seedMonth();

        // One day only: three people were marked on the 1st, all present.
        $oneDay = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-09-01&to=2026-09-01')->assertOk();

        $this->assertSame(3, $oneDay->json('data.totals.recorded'));
        $this->assertSame(3, $oneDay->json('data.totals.present'));
        $this->assertSame([], $oneDay->json('data.most_absent'));
    }

    public function test_a_period_with_nothing_in_it_reads_as_empty_not_as_an_error(): void
    {
        $this->actAs(['hrms.view', 'hrms.manage']);
        $this->seedMonth();

        $response = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-10-01&to=2026-10-31')->assertOk();

        $this->assertSame([], $response->json('data.departments'));
        $this->assertSame(0, $response->json('data.totals.recorded'));
        $this->assertSame(0.0, (float) $response->json('data.totals.present_percent'));
    }

    public function test_a_person_with_no_department_is_still_counted_under_a_name(): void
    {
        $this->actAs(['hrms.view', 'hrms.manage']);
        $nameless = Employee::create([
            'employee_code' => 'TMP-99', 'name' => 'Nameless', 'date_of_joining' => '2026-01-01',
        ]);
        $this->mark($nameless, '2026-09-01', 'present');

        $departments = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-09-01&to=2026-09-30')
            ->assertOk()->json('data.departments');

        $this->assertSame('No department', $departments[0]['department']);
        $this->assertSame(1, $departments[0]['recorded']);
    }

    // ---- who may read what ------------------------------------------------

    public function test_the_factory_wide_numbers_need_manage_not_merely_view(): void
    {
        $this->actAs(['hrms.view']);
        $this->seedMonth();

        // A supervisor may look a person up …
        $this->getJson("/api/v1/hrms/attendance/person?employee_id={$this->anand->id}&from=2026-09-01&to=2026-09-30")
            ->assertOk();
        // … but not read the whole factory's attendance.
        $this->getJson('/api/v1/hrms/attendance/summary?from=2026-09-01&to=2026-09-30')->assertForbidden();
    }

    public function test_neither_read_is_open_to_a_login_without_hrms(): void
    {
        $this->actAs(['production.view']);

        $this->getJson("/api/v1/hrms/attendance/person?employee_id={$this->anand->id}&from=2026-09-01&to=2026-09-30")
            ->assertForbidden();
        $this->getJson('/api/v1/hrms/attendance/summary?from=2026-09-01&to=2026-09-30')->assertForbidden();
    }
}
