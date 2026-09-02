<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\AttendanceStatus;
use App\Modules\HRMS\Models\Enums\EmployeeStatus;
use App\Modules\HRMS\Models\Enums\LeaveRequestStatus;
use App\Modules\HRMS\Models\LeaveRequest;
use App\Modules\HRMS\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE THREE HRMS LISTS GET A WAY IN — employees, leave requests, attendance.
 *
 * Each page rendered the server's first 20 rows with no pager and no search,
 * so the 21st employee, and every leave request or attendance mark older
 * than the newest screen, could not be reached from the UI at all. This pins
 * the server half of the fix, in the grammar every other list already uses:
 *
 *   - `q` narrows on the employee's code, name, department or designation —
 *     directly on the employee list, THROUGH the employee on the other two
 *     (a leave request or an attendance mark has no number anyone types);
 *   - a typed `%` is a character, not a wildcard;
 *   - the documented filters narrow to exactly the rows they name;
 *   - `meta.total` is the server's count, and the pages add up to it;
 *   - the default order is unchanged and has a tie-breaker;
 *   - a page size outside the range, an unknown status, a non-date or a
 *     reversed range is a 422, never a silently-full or -empty list;
 *   - an empty query string is exactly the list every earlier caller got.
 *
 * Read with `hrms.view` ALONE — a list is a read, and a reader must be able
 * to search it. Every figure and name here is synthetic.
 */
class HrmsListSearchTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anand;

    private Employee $bala;

    private Employee $chitra;

    protected function setUp(): void
    {
        parent::setUp();

        $reader = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('hrms.view', 'web');
        $reader->givePermissionTo('hrms.view');
        Sanctum::actingAs($reader->fresh());

        $this->anand = Employee::create([
            'employee_code' => 'EMP-001', 'name' => 'Anand Kumar', 'date_of_joining' => '2025-01-06',
            'department' => 'Production', 'designation' => 'Operator',
        ]);
        $this->bala = Employee::create([
            'employee_code' => 'EMP-002', 'name' => 'Bala Murugan', 'date_of_joining' => '2025-03-03',
            'department' => 'Stores', 'designation' => 'Storekeeper',
        ]);
        $this->chitra = Employee::create([
            'employee_code' => 'EMP-003', 'name' => 'Chitra Devi', 'date_of_joining' => '2024-11-11',
            'department' => 'Accounts', 'designation' => 'Accountant', 'status' => EmployeeStatus::Inactive,
        ]);

        $casual = LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'default_annual_days' => 12]);

        foreach ([
            [$this->anand, '2026-08-03', LeaveRequestStatus::Approved],
            [$this->anand, '2026-08-17', LeaveRequestStatus::Pending],
            [$this->bala, '2026-08-10', LeaveRequestStatus::Pending],
            [$this->chitra, '2026-08-24', LeaveRequestStatus::Rejected],
        ] as [$employee, $date, $status]) {
            LeaveRequest::create([
                'employee_id' => $employee->id, 'leave_type_id' => $casual->id,
                'start_date' => $date, 'end_date' => $date, 'days' => 1, 'status' => $status,
            ]);
        }

        foreach ([
            [$this->anand, '2026-08-10', AttendanceStatus::Present],
            [$this->anand, '2026-08-11', AttendanceStatus::Absent],
            [$this->bala, '2026-08-10', AttendanceStatus::Present],
            [$this->bala, '2026-08-12', AttendanceStatus::HalfDay],
            [$this->chitra, '2026-08-11', AttendanceStatus::OnLeave],
        ] as [$employee, $date, $status]) {
            Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'status' => $status]);
        }
    }

    /** @param  array<string, mixed>  $query */
    private function list(string $endpoint, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/hrms/'.$endpoint.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @param  array<string, mixed>  $query */
    private function total(string $endpoint, array $query = []): int
    {
        return (int) $this->list($endpoint, $query)->assertOk()->json('meta.total');
    }

    // ---- employees ---------------------------------------------------------

    public function test_employee_search_matches_code_name_department_or_designation(): void
    {
        $byCode = $this->list('employees', ['q' => 'emp-002'])->assertOk();
        $this->assertSame(1, $byCode->json('meta.total'));
        $this->assertSame('Bala Murugan', $byCode->json('data.0.name'));

        $this->assertSame(1, $this->total('employees', ['q' => 'chitra']), 'part of a name, any case');
        $this->assertSame(1, $this->total('employees', ['q' => 'Stores']), 'department');
        $this->assertSame(1, $this->total('employees', ['q' => 'operator']), 'designation');
        $this->assertSame(0, $this->total('employees', ['q' => 'nobody of this name']));
    }

    public function test_a_typed_percent_sign_is_a_character_not_a_wildcard(): void
    {
        // Three employees, none with a % in any field: a bare wildcard would
        // answer with all of them.
        $this->assertSame(0, $this->total('employees', ['q' => '%']));
        $this->assertSame(0, $this->total('employees', ['q' => '_']));
    }

    public function test_an_empty_search_is_no_filter_and_the_order_is_by_name(): void
    {
        $bare = $this->list('employees')->assertOk();
        $empty = $this->list('employees', ['q' => ''])->assertOk();

        $this->assertSame(3, $bare->json('meta.total'));
        $this->assertSame(3, $empty->json('meta.total'));
        $this->assertSame(['Anand Kumar', 'Bala Murugan', 'Chitra Devi'], $bare->json('data.*.name'));
        $this->assertSame(20, $bare->json('meta.per_page'), 'the default page size is unchanged');
    }

    public function test_employee_pages_add_up_to_the_servers_total_and_carry_the_search(): void
    {
        $first = $this->list('employees', ['per_page' => 2])->assertOk();
        $this->assertSame(3, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'));
        $this->assertSame(1, $first->json('meta.current_page'));
        $this->assertCount(2, $first->json('data'));

        $second = $this->list('employees', ['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertSame(2, $second->json('meta.current_page'));
        $this->assertCount(1, $second->json('data'));
        $this->assertSame('Chitra Devi', $second->json('data.0.name'));

        // The next-page link keeps the narrowing, so page 2 is page 2 of
        // the same question.
        $next = $this->list('employees', ['per_page' => 1, 'q' => 'a'])->assertOk()->json('links.next');
        $this->assertNotNull($next);
        $this->assertStringContainsString('q=a', $next);
        $this->assertStringContainsString('page=2', $next);
    }

    public function test_employee_status_filter_narrows_and_an_unknown_status_is_refused(): void
    {
        $inactive = $this->list('employees', ['status' => 'inactive'])->assertOk();
        $this->assertSame(1, $inactive->json('meta.total'));
        $this->assertSame('EMP-003', $inactive->json('data.0.employee_code'));

        $this->assertSame(2, $this->total('employees', ['status' => 'active']));

        $this->list('employees', ['status' => 'retired'])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_employee_per_page_is_one_to_one_thousand_and_anything_else_is_refused(): void
    {
        // The picker's contract — `listAllEmployees` asks for 1000 — kept.
        $this->assertSame(1000, $this->list('employees', ['per_page' => 1000])->assertOk()->json('meta.per_page'));
        $this->assertSame(1, $this->list('employees', ['per_page' => 1])->assertOk()->json('meta.per_page'));

        $this->list('employees', ['per_page' => 0])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->list('employees', ['per_page' => 1001])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->list('employees', ['per_page' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->list('employees', ['q' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    // ---- leave requests ----------------------------------------------------

    public function test_leave_request_search_goes_through_the_employee(): void
    {
        $bala = $this->list('leave-requests', ['q' => 'bala'])->assertOk();
        $this->assertSame(1, $bala->json('meta.total'));
        $this->assertSame('Bala Murugan', $bala->json('data.0.employee.name'));

        $this->assertSame(2, $this->total('leave-requests', ['q' => 'EMP-001']), 'by code');
        $this->assertSame(1, $this->total('leave-requests', ['q' => 'accounts']), 'by department');
        $this->assertSame(0, $this->total('leave-requests', ['q' => 'nobody']));
        $this->assertSame(4, $this->total('leave-requests', ['q' => '']), 'an empty box narrows nothing');
    }

    public function test_leave_request_filters_narrow_and_pages_add_up(): void
    {
        $this->assertSame(2, $this->total('leave-requests', ['status' => 'pending']));
        $this->assertSame(2, $this->total('leave-requests', ['employee_id' => $this->anand->id]));
        $this->assertSame(1, $this->total('leave-requests', ['status' => 'pending', 'employee_id' => $this->anand->id]));

        $first = $this->list('leave-requests', ['per_page' => 3])->assertOk();
        $this->assertSame(4, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'));
        $this->assertCount(3, $first->json('data'));
        $this->assertCount(1, $this->list('leave-requests', ['per_page' => 3, 'page' => 2])->assertOk()->json('data'));

        // Newest first, as it always was.
        $ids = $this->list('leave-requests')->assertOk()->json('data.*.id');
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_leave_request_malformed_values_are_refused(): void
    {
        $this->list('leave-requests', ['status' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->list('leave-requests', ['employee_id' => 0])->assertStatus(422)->assertJsonValidationErrors(['employee_id']);
        $this->list('leave-requests', ['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->list('leave-requests', ['per_page' => 0])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->assertSame(20, $this->list('leave-requests')->assertOk()->json('meta.per_page'));
    }

    // ---- attendance --------------------------------------------------------

    public function test_attendance_search_goes_through_the_employee(): void
    {
        $bala = $this->list('attendance', ['q' => 'EMP-002'])->assertOk();
        $this->assertSame(2, $bala->json('meta.total'));
        $this->assertSame(['Bala Murugan', 'Bala Murugan'], $bala->json('data.*.employee.name'));

        $this->assertSame(2, $this->total('attendance', ['q' => 'storekeeper']), 'by designation');
        $this->assertSame(1, $this->total('attendance', ['q' => 'chitra']));
        $this->assertSame(5, $this->total('attendance', ['q' => '']), 'an empty box narrows nothing');
    }

    public function test_attendance_date_range_is_inclusive_on_the_attendance_date(): void
    {
        $this->assertSame(3, $this->total('attendance', ['from' => '2026-08-11', 'to' => '2026-08-12']));
        $this->assertSame(2, $this->total('attendance', ['from' => '2026-08-10', 'to' => '2026-08-10']));
        $this->assertSame(3, $this->total('attendance', ['from' => '2026-08-11']), 'open-ended forwards');
        $this->assertSame(2, $this->total('attendance', ['to' => '2026-08-10']), 'open-ended backwards');

        $this->list('attendance', ['from' => '2026-08-12', 'to' => '2026-08-10'])
            ->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->list('attendance', ['from' => '11-08-2026'])
            ->assertStatus(422)->assertJsonValidationErrors(['from']);
    }

    public function test_attendance_filters_narrow_pages_add_up_and_the_order_is_stable(): void
    {
        $this->assertSame(2, $this->total('attendance', ['status' => 'present']));
        $this->assertSame(2, $this->total('attendance', ['employee_id' => $this->anand->id]));
        $this->assertSame(1, $this->total('attendance', ['status' => 'present', 'employee_id' => $this->anand->id]));

        $first = $this->list('attendance', ['per_page' => 2])->assertOk();
        $this->assertSame(5, $first->json('meta.total'));
        $this->assertSame(3, $first->json('meta.last_page'));
        $this->assertCount(1, $this->list('attendance', ['per_page' => 2, 'page' => 3])->assertOk()->json('data'));

        // Newest date first; on one date the later-created mark first, so
        // two loads of the same page never swap rows.
        $rows = $this->list('attendance')->assertOk()->json('data');
        $this->assertSame(['2026-08-12', '2026-08-11', '2026-08-11', '2026-08-10', '2026-08-10'], array_column($rows, 'date'));
        $this->assertGreaterThan($rows[2]['id'], $rows[1]['id']);
        $this->assertGreaterThan($rows[4]['id'], $rows[3]['id']);

        $this->list('attendance', ['status' => 'late'])->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->list('attendance', ['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->list('attendance', ['employee_id' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['employee_id']);
    }
}
