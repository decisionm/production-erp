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
 * THE THREE QUESTIONS A TALLY CANNOT ANSWER.
 *
 * Which DAYS the factory ran short, how LONG the days actually were, and
 * who the punch report keeps failing on. A month that is 80% present can be
 * a steady month or a fortnight with half the floor missing, and the
 * totals on the cards above cannot tell those apart.
 *
 * The hours are read off the CLOCK, never off the punch app's own OT, Late
 * In or Early Out columns. Those are the app's arithmetic against the shift
 * window it was configured with — the window that called 232 full shifts
 * half days in July 2026 — so they are evidence of the app's opinion and
 * not of the factory's day.
 */
class AttendanceInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anand;

    private Employee $bala;

    private AttendanceImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anand = Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'Anand', 'date_of_joining' => '2026-01-01',
            'department' => 'Production Department', 'designation' => 'Operator',
        ]);
        $this->bala = Employee::create([
            'employee_code' => 'SPP-02', 'name' => 'Bala', 'date_of_joining' => '2026-01-01',
            'department' => 'Stores Department', 'designation' => 'Store Incharge',
        ]);
        $this->import = AttendanceImport::create([
            'source' => 'pooja', 'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
            'file_name' => 'july.xlsx', 'uploaded_by' => User::factory()->create()->id, 'status' => 'review',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions = ['hrms.view', 'hrms.manage']): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user->fresh());
    }

    private function line(Employee $employee, string $date, ?string $resolution, int $minutes, ?string $issue = null): void
    {
        AttendanceImportLine::create([
            'attendance_import_id' => $this->import->id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->name,
            'date' => $date,
            'raw_status' => 'FD',
            'first_in' => '06:00',
            'last_out' => '14:00',
            'worked_minutes' => $minutes,
            'issue' => $issue,
            'resolution' => $resolution,
        ]);
    }

    /** @return array<string, mixed> */
    private function insights(): array
    {
        return $this->getJson('/api/v1/hrms/attendance/insights?from=2026-07-01&to=2026-07-31')
            ->assertOk()->json('data');
    }

    // ---- turnout ----------------------------------------------------------

    public function test_turnout_is_day_by_day_and_counts_the_upload_with_the_record(): void
    {
        $this->actAs();
        $this->line($this->anand, '2026-07-01', 'present', 480);
        $this->line($this->bala, '2026-07-01', 'absent', 0);
        $this->line($this->anand, '2026-07-02', 'half_day', 260);
        // An applied day on the 2nd counts beside the uploaded one.
        Attendance::create(['employee_id' => $this->bala->id, 'date' => '2026-07-02', 'status' => 'present']);

        $turnout = collect($this->insights()['turnout'])->keyBy('date');

        $this->assertSame(['2026-07-01', '2026-07-02'], $turnout->keys()->all());
        $this->assertSame(1, $turnout['2026-07-01']['present']);
        $this->assertSame(1, $turnout['2026-07-01']['absent']);
        $this->assertSame(1, $turnout['2026-07-02']['present'], 'the applied day');
        $this->assertSame(1, $turnout['2026-07-02']['half_day'], 'the uploaded one');
    }

    public function test_an_unanswered_day_is_not_counted_as_an_absence(): void
    {
        $this->actAs();
        $this->line($this->anand, '2026-07-01', null, 0, 'no_punch');

        $day = $this->insights()['turnout'][0];

        // A day nobody has reviewed is not a day nobody worked. Folding it
        // into the absences would invent a bad day for the factory.
        $this->assertSame(0, $day['absent']);
        $this->assertSame(1, $day['needs_review']);
    }

    // ---- how long the days ran --------------------------------------------

    public function test_the_hours_are_read_off_the_clock(): void
    {
        $this->actAs();
        $this->line($this->anand, '2026-07-01', 'present', 480);   // a shift
        $this->line($this->anand, '2026-07-02', 'present', 615);   // long
        $this->line($this->anand, '2026-07-03', 'present', 745);   // very long
        $this->line($this->bala, '2026-07-01', 'half_day', 200);   // short
        $this->line($this->bala, '2026-07-02', 'present', 1100);   // implausible

        $hours = $this->insights()['hours'];

        $this->assertSame(5, $hours['days']);
        $this->assertSame(3, $hours['long_days'], '615, 745 and the implausible 1100 are all past ten hours');
        $this->assertSame(2, $hours['very_long_days']);
        $this->assertSame(1, $hours['short_days']);
        // Kept apart rather than counted as somebody's day: the app pairs an
        // in-punch with the wrong day's out-punch and credits twenty hours.
        $this->assertSame(1, $hours['implausible_days']);
        $this->assertSame((480 + 615 + 745 + 200 + 1100), $hours['total_minutes']);
        $this->assertSame((int) round((480 + 615 + 745 + 200 + 1100) / 5), $hours['average_minutes']);
    }

    public function test_the_longest_days_name_people_and_leave_the_impossible_ones_out(): void
    {
        $this->actAs();
        $this->line($this->anand, '2026-07-01', 'present', 700);
        $this->line($this->anand, '2026-07-02', 'present', 660);
        $this->line($this->bala, '2026-07-01', 'present', 1200);

        $longest = $this->insights()['longest_days'];

        $this->assertCount(1, $longest, 'a twenty-hour day is a data fault, not a long shift');
        $this->assertSame('Anand', $longest[0]['name']);
        $this->assertSame(2, $longest[0]['long_days']);
        $this->assertSame(1360, $longest[0]['minutes']);
    }

    // ---- who the report keeps failing on ----------------------------------

    public function test_the_mismatch_list_counts_answered_days_too_and_says_what_is_still_open(): void
    {
        $this->actAs();
        $this->line($this->anand, '2026-07-01', 'present', 480, 'in_no_out');
        $this->line($this->anand, '2026-07-02', null, 0, 'no_punch');
        $this->line($this->anand, '2026-07-03', 'present', 480);
        $this->line($this->bala, '2026-07-01', null, 0, 'no_punch');

        $people = collect($this->insights()['most_mismatched'])->keyBy('name');

        // Answering a day settles it; it does not unmake the fact that the
        // report came in wrong on that badge.
        $this->assertSame(2, $people['Anand']['mismatches']);
        $this->assertSame(1, $people['Anand']['unanswered']);
        $this->assertSame(1, $people['Bala']['mismatches']);
    }

    // ---- who may look -----------------------------------------------------

    public function test_the_whole_factorys_numbers_need_the_manage_permission(): void
    {
        $this->actAs(['hrms.view']);

        $this->getJson('/api/v1/hrms/attendance/insights?from=2026-07-01&to=2026-07-31')->assertForbidden();
    }

    public function test_the_range_is_validated(): void
    {
        $this->actAs();

        $this->getJson('/api/v1/hrms/attendance/insights?from=2026-07-31&to=2026-07-01')
            ->assertStatus(422)->assertJsonValidationErrors(['to']);
    }

    public function test_an_empty_period_answers_with_zeroes_rather_than_nothing(): void
    {
        $this->actAs();

        $insights = $this->insights();

        $this->assertSame([], $insights['turnout']);
        $this->assertSame(0, $insights['hours']['days']);
        $this->assertSame(0, $insights['hours']['average_minutes']);
        $this->assertSame([], $insights['longest_days']);
    }
}
