<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\AttendanceStatus;
use App\Modules\HRMS\Models\Enums\LeaveRequestStatus;
use App\Modules\HRMS\Models\LeaveBalance;
use App\Modules\HRMS\Models\LeaveRequest;
use App\Modules\HRMS\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE FIVE HRMS LISTS SORT ON THE SERVER (ListSort, 03-Sep-2026) — employees,
 * attendance, leave requests, leave types, leave balances. For each:
 *
 *   - a `sort` naming no column the list shows is a 422, never a silently
 *     re-ordered or default-ordered list;
 *   - `-column` orders descending with `id desc` as the tiebreak, so two
 *     rows sharing a value never swap between two loads of one page;
 *   - `per_page` is honoured and `meta.total` is the whole collection.
 *
 * The two lists that drew the server's first 20 with no pager (leave types,
 * leave balances) take `per_page` and `page` through a FormRequest now, and
 * the leave-type picker's `per_page=1000` contract is kept.
 *
 * Read with `hrms.view` alone. Every figure and name here is synthetic.
 */
class HrmsListSortTest extends TestCase
{
    use RefreshDatabase;

    private Employee $first;

    private Employee $second;

    private Employee $third;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();

        $reader = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('hrms.view', 'web');
        $reader->givePermissionTo('hrms.view');
        Sanctum::actingAs($reader->fresh());

        // Two employees of one name and one of another: the tiebreak case.
        $this->first = Employee::create(['employee_code' => 'SRT-A', 'name' => 'Same Name', 'date_of_joining' => '2025-01-06', 'department' => 'Stores']);
        $this->second = Employee::create(['employee_code' => 'SRT-B', 'name' => 'Other Name', 'date_of_joining' => '2025-03-03', 'department' => 'Accounts']);
        $this->third = Employee::create(['employee_code' => 'SRT-C', 'name' => 'Same Name', 'date_of_joining' => '2024-11-11', 'department' => 'Production']);

        $this->casual = LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'default_annual_days' => 12]);
    }

    /** @param  array<string, mixed>  $query */
    private function list(string $endpoint, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/hrms/'.$endpoint.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * The page's ids, in order — and the page-size contract every list
     * shares: two rows on a page of two, the whole collection in the total.
     *
     * @return list<int>
     */
    private function idsOf(string $endpoint, string $sort): array
    {
        return $this->list($endpoint, ['sort' => $sort])->assertOk()->json('data.*.id');
    }

    private function assertPagesHonestly(string $endpoint, int $total): void
    {
        $page = $this->list($endpoint, ['per_page' => 2])->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame($total, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.per_page'));
    }

    // ---- employees ---------------------------------------------------------

    public function test_employees_refuse_an_unknown_sort_and_order_descending_with_id_as_the_tiebreak(): void
    {
        $this->list('employees', ['sort' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('employees', ['sort' => 'manager'])->assertStatus(422)->assertJsonValidationErrors(['sort']);

        // "Same Name" twice, the newer id first; then "Other Name".
        $this->assertSame([$this->third->id, $this->first->id, $this->second->id], $this->idsOf('employees', '-name'));
        $this->assertSame([$this->second->id, $this->third->id, $this->first->id], $this->idsOf('employees', 'name'));

        $this->assertSame(['SRT-C', 'SRT-B', 'SRT-A'], $this->list('employees', ['sort' => '-employee_code'])->assertOk()->json('data.*.employee_code'));
        $this->assertSame(['2025-03-03', '2025-01-06', '2024-11-11'], $this->list('employees', ['sort' => '-date_of_joining'])->assertOk()->json('data.*.date_of_joining'));
        $this->assertSame(['Accounts', 'Production', 'Stores'], $this->list('employees', ['sort' => 'department'])->assertOk()->json('data.*.department'));

        // The bare list is still by name, as it always was.
        $this->assertSame(['Other Name', 'Same Name', 'Same Name'], $this->list('employees')->assertOk()->json('data.*.name'));

        $this->assertPagesHonestly('employees', 3);
    }

    // ---- attendance --------------------------------------------------------

    public function test_attendance_sorts_by_date_or_status_with_id_as_the_tiebreak(): void
    {
        $older = Attendance::create(['employee_id' => $this->first->id, 'date' => '2026-08-10', 'status' => AttendanceStatus::Present]);
        $newerFirst = Attendance::create(['employee_id' => $this->second->id, 'date' => '2026-08-12', 'status' => AttendanceStatus::Absent]);
        $newerSecond = Attendance::create(['employee_id' => $this->third->id, 'date' => '2026-08-12', 'status' => AttendanceStatus::Present]);

        $this->list('attendance', ['sort' => 'employee'])->assertStatus(422)->assertJsonValidationErrors(['sort']);

        $this->assertSame([$newerSecond->id, $newerFirst->id, $older->id], $this->idsOf('attendance', '-date'));
        $this->assertSame([$older->id, $newerSecond->id, $newerFirst->id], $this->idsOf('attendance', 'date'));
        $this->assertSame([$newerSecond->id, $older->id, $newerFirst->id], $this->idsOf('attendance', '-status'), 'present, present (newer first), absent');

        $this->assertPagesHonestly('attendance', 3);
    }

    // ---- leave requests ----------------------------------------------------

    public function test_leave_requests_sort_by_their_own_dates_days_and_status(): void
    {
        $rows = [];
        foreach ([
            ['2026-08-03', '2026-08-04', '2.00', LeaveRequestStatus::Approved],
            ['2026-08-17', '2026-08-17', '1.00', LeaveRequestStatus::Pending],
            ['2026-08-10', '2026-08-11', '2.00', LeaveRequestStatus::Pending],
        ] as [$start, $end, $days, $status]) {
            $rows[] = LeaveRequest::create([
                'employee_id' => $this->first->id, 'leave_type_id' => $this->casual->id,
                'start_date' => $start, 'end_date' => $end, 'days' => $days, 'status' => $status,
            ]);
        }

        $this->list('leave-requests', ['sort' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('leave-requests', ['sort' => 'leave_type'])->assertStatus(422)->assertJsonValidationErrors(['sort']);

        // Two of two days — the newer first — then the one-day request.
        $this->assertSame([$rows[2]->id, $rows[0]->id, $rows[1]->id], $this->idsOf('leave-requests', '-days'));
        $this->assertSame([$rows[1]->id, $rows[2]->id, $rows[0]->id], $this->idsOf('leave-requests', '-start_date'));
        $this->assertSame([$rows[0]->id, $rows[2]->id, $rows[1]->id], $this->idsOf('leave-requests', 'end_date'));
        $this->assertSame([$rows[2]->id, $rows[1]->id, $rows[0]->id], $this->idsOf('leave-requests', '-status'), 'pending, pending (newer first), approved');

        $this->assertPagesHonestly('leave-requests', 3);
    }

    // ---- leave types -------------------------------------------------------

    public function test_leave_types_sort_page_and_keep_the_pickers_ceiling(): void
    {
        $sick = LeaveType::create(['code' => 'SL', 'name' => 'Sick leave', 'default_annual_days' => 12]);
        $earned = LeaveType::create(['code' => 'EL', 'name' => 'Earned leave', 'default_annual_days' => 15, 'is_active' => false]);

        $this->list('leave-types', ['sort' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('leave-types', ['per_page' => 0])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->list('leave-types', ['per_page' => 1001])->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        // Fifteen first, then the two twelves with the newer id first.
        $this->assertSame([$earned->id, $sick->id, $this->casual->id], $this->idsOf('leave-types', '-default_annual_days'));
        $this->assertSame(['SL', 'EL', 'CL'], $this->list('leave-types', ['sort' => '-code'])->assertOk()->json('data.*.code'));
        $this->assertSame([$earned->id, $sick->id, $this->casual->id], $this->idsOf('leave-types', 'is_active'), 'withdrawn first, then active newest first');

        // By name when nothing is asked, as it always was.
        $this->assertSame(['Casual leave', 'Earned leave', 'Sick leave'], $this->list('leave-types')->assertOk()->json('data.*.name'));

        $this->assertPagesHonestly('leave-types', 3);
        $this->assertSame(1000, $this->list('leave-types', ['per_page' => 1000])->assertOk()->json('meta.per_page'), 'the picker asks at 1000');
    }

    // ---- leave balances ----------------------------------------------------

    public function test_leave_balances_sort_by_year_and_the_two_stored_figures(): void
    {
        $rows = [];
        foreach ([
            [$this->first, 2025, '12.00', '3.00'],
            [$this->second, 2026, '10.00', '0.00'],
            [$this->third, 2026, '12.00', '5.00'],
        ] as [$employee, $year, $allocated, $used]) {
            $rows[] = LeaveBalance::create([
                'employee_id' => $employee->id, 'leave_type_id' => $this->casual->id,
                'year' => $year, 'allocated_days' => $allocated, 'used_days' => $used,
            ]);
        }

        $this->list('leave-balances', ['sort' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('leave-balances', ['sort' => 'remaining_days'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->list('leave-balances', ['per_page' => 101])->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        // 2026 twice, the newer first; then 2025 — and that is also the bare order.
        $this->assertSame([$rows[2]->id, $rows[1]->id, $rows[0]->id], $this->idsOf('leave-balances', '-year'));
        $this->assertSame([$rows[2]->id, $rows[1]->id, $rows[0]->id], $this->list('leave-balances')->assertOk()->json('data.*.id'));
        $this->assertSame([$rows[2]->id, $rows[0]->id, $rows[1]->id], $this->idsOf('leave-balances', '-allocated_days'));
        $this->assertSame([$rows[1]->id, $rows[0]->id, $rows[2]->id], $this->idsOf('leave-balances', 'used_days'));

        $this->assertPagesHonestly('leave-balances', 3);
    }
}
