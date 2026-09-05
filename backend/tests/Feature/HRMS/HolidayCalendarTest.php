<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Holiday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE CALENDAR, AND THE 396 DAYS IT ANSWERS.
 *
 * The punch report names its own week offs and never names a public
 * holiday, so every holiday nobody worked arrives as a "no punch" issue
 * for a person to answer by hand — 396 of them in August 2026, of which
 * 130 are Sundays. Given the calendar the ERP answers them itself, and
 * answers HOLIDAY: not leave, not an absence, and nothing written to
 * `attendances` (the same treatment week_off already gets, and for the
 * same reason — payroll counts its days off AttendanceStatus).
 *
 * The re-check is the half that matters on a month already uploaded: the
 * calendar almost never arrives before the report does.
 *
 * Every name and figure here is synthetic.
 */
class HolidayCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'ANAND', 'date_of_joining' => '2026-01-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
        ]);
    }

    private function actAs(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('hrms.manage', 'web');
        $user->givePermissionTo('hrms.manage');
        Sanctum::actingAs($user->fresh());
    }

    /** Three days: one worked, one holiday nobody punched, one plain absence. */
    private function upload(): int
    {
        $day = fn (string $date, string $status, ?string $in, ?string $out, int $worked) => [
            'date' => $date, 'status' => $status, 'first_in' => $in, 'last_out' => $out,
            'ot_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'worked_minutes' => $worked,
        ];

        return (int) $this->postJson('/api/v1/hrms/attendance-imports', [
            'period_from' => '2026-08-14',
            'period_to' => '2026-08-16',
            'source' => 'pooja',
            'file_name' => 'august.xlsx',
            'employees' => [[
                'employee_code' => 'SPP-01', 'name' => 'ANAND',
                'department' => 'Production Department', 'designation' => 'Packing Staff',
                'days' => [
                    $day('2026-08-14', 'FD', '09:00', '18:00', 480),
                    $day('2026-08-15', 'Absent', null, null, 0),
                    $day('2026-08-16', 'Absent', null, null, 0),
                ],
            ]],
        ])->assertCreated()->json('data.id');
    }

    private function line(int $import, string $date): AttendanceImportLine
    {
        return AttendanceImportLine::query()
            ->where('attendance_import_id', $import)
            ->whereDate('date', $date)
            ->firstOrFail();
    }

    public function test_without_a_calendar_a_holiday_is_just_another_day_nobody_punched(): void
    {
        $this->actAs();
        $import = $this->upload();

        $this->assertSame('no_punch', $this->line($import, '2026-08-15')->issue->value);
        $this->assertSame('no_punch', $this->line($import, '2026-08-16')->issue->value);
    }

    public function test_a_holiday_known_before_the_upload_answers_itself(): void
    {
        $this->actAs();
        Holiday::create(['date' => '2026-08-15', 'name' => 'Independence Day']);

        $import = $this->upload();

        $holiday = $this->line($import, '2026-08-15');
        $this->assertNull($holiday->issue, 'a holiday is not a question');
        $this->assertSame('holiday', $holiday->resolution->value);

        // The day beside it is untouched: the calendar answers holidays, not absences.
        $this->assertSame('no_punch', $this->line($import, '2026-08-16')->issue->value);
    }

    public function test_a_calendar_loaded_afterwards_clears_the_days_already_waiting(): void
    {
        $this->actAs();
        $import = $this->upload();
        $this->assertSame(2, $this->openCount($import), 'both no-punch days are waiting');

        // The calendar almost never arrives before the report does.
        Holiday::create(['date' => '2026-08-15', 'name' => 'Independence Day']);

        $this->postJson("/api/v1/hrms/attendance-imports/{$import}/recheck")->assertOk();

        $this->assertSame('holiday', $this->line($import, '2026-08-15')->resolution->value);
        $this->assertSame(1, $this->openCount($import), 'and only the real absence is left to answer');
    }

    public function test_a_day_a_person_already_answered_is_not_overruled_by_the_calendar(): void
    {
        $this->actAs();
        $import = $this->upload();
        $line = $this->line($import, '2026-08-15');

        $this->patchJson("/api/v1/hrms/attendance-imports/{$import}/lines/{$line->id}", ['resolution' => 'absent'])
            ->assertOk();

        Holiday::create(['date' => '2026-08-15', 'name' => 'Independence Day']);
        $this->postJson("/api/v1/hrms/attendance-imports/{$import}/recheck")->assertOk();

        $this->assertSame('absent', $this->line($import, '2026-08-15')->resolution->value);
    }

    public function test_somebody_who_worked_the_holiday_is_present_on_it(): void
    {
        $this->actAs();
        Holiday::create(['date' => '2026-08-14', 'name' => 'A day the line ran anyway']);

        $import = $this->upload();

        $worked = $this->line($import, '2026-08-14');
        $this->assertNull($worked->issue);
        $this->assertSame('present', $worked->resolution->value);
    }

    public function test_a_holiday_writes_nothing_to_attendances_when_the_run_is_applied(): void
    {
        $this->actAs();
        Holiday::create(['date' => '2026-08-15', 'name' => 'Independence Day']);
        $import = $this->upload();

        // Answer the one real absence so the run can be applied.
        $absent = $this->line($import, '2026-08-16');
        $this->patchJson("/api/v1/hrms/attendance-imports/{$import}/lines/{$absent->id}", ['resolution' => 'absent'])->assertOk();
        $this->postJson("/api/v1/hrms/attendance-imports/{$import}/apply")->assertOk();

        $dates = Attendance::query()->pluck('date')
            ->map(fn ($d) => $d->toDateString())->all();

        $this->assertContains('2026-08-14', $dates, 'the worked day is attendance');
        $this->assertContains('2026-08-16', $dates, 'so is the absence');
        $this->assertNotContains('2026-08-15', $dates, 'the holiday is not, exactly as a week off is not');
    }

    // ---- managing the calendar --------------------------------------------------------

    public function test_the_calendar_is_read_a_year_at_a_time_and_names_the_weekday(): void
    {
        $this->actAs();
        Holiday::create(['date' => '2026-08-15', 'name' => 'Independence Day']);
        Holiday::create(['date' => '2026-01-26', 'name' => 'Republic Day']);
        Holiday::create(['date' => '2025-10-02', 'name' => 'Last year']);

        $this->getJson('/api/v1/hrms/holidays?year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-01-26')
            ->assertJsonPath('data.0.weekday', 'Monday')
            ->assertJsonPath('data.1.name', 'Independence Day');

        $this->getJson('/api/v1/hrms/holidays?year=2025')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_holiday_on_the_last_day_of_the_year_is_in_that_year(): void
    {
        $this->actAs();
        Holiday::create(['date' => '2026-12-31', 'name' => 'Year end']);
        Holiday::create(['date' => '2026-01-01', 'name' => 'New Year']);

        // The date column stores a datetime, so "2026-12-31 00:00:00" sorts
        // above the string "2026-12-31" and a BETWEEN over strings drops it.
        $this->getJson('/api/v1/hrms/holidays?year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.name', 'Year end');
    }

    public function test_the_same_date_cannot_be_a_holiday_twice(): void
    {
        $this->actAs();
        $this->postJson('/api/v1/hrms/holidays', ['date' => '2026-08-15', 'name' => 'Independence Day'])->assertCreated();

        $this->postJson('/api/v1/hrms/holidays', ['date' => '2026-08-15', 'name' => 'Independence Day (again)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    public function test_an_uploaded_calendar_says_what_it_changed(): void
    {
        $this->actAs();
        Holiday::create(['date' => '2026-08-15', 'name' => 'Indpendence Day']);

        $this->postJson('/api/v1/hrms/holidays/replace', ['holidays' => [
            ['date' => '2026-01-26', 'name' => 'Republic Day'],
            ['date' => '2026-08-15', 'name' => 'Independence Day'],
            ['date' => '2026-10-02', 'name' => 'Gandhi Jayanti'],
        ]])->assertOk()
            ->assertJsonPath('data.added', 2)
            ->assertJsonPath('data.renamed', 1)
            ->assertJsonPath('data.unchanged', 0);

        // Uploading the same list again changes nothing — it is not additive.
        $this->postJson('/api/v1/hrms/holidays/replace', ['holidays' => [
            ['date' => '2026-01-26', 'name' => 'Republic Day'],
        ]])->assertOk()->assertJsonPath('data.unchanged', 1);

        $this->assertSame(3, Holiday::count());
    }

    public function test_an_upload_that_lists_a_date_twice_is_refused(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/hrms/holidays/replace', ['holidays' => [
            ['date' => '2026-08-15', 'name' => 'Independence Day'],
            ['date' => '2026-08-15', 'name' => 'Independence Day'],
        ]])->assertUnprocessable();
    }

    public function test_a_withdrawn_holiday_is_soft_deleted_so_a_past_sheet_still_reads(): void
    {
        $this->actAs();
        $holiday = Holiday::create(['date' => '2026-08-15', 'name' => 'Independence Day']);

        $this->deleteJson("/api/v1/hrms/holidays/{$holiday->id}")->assertNoContent();

        $this->assertSame(0, Holiday::count());
        $this->assertSame(1, Holiday::withTrashed()->count());
    }

    public function test_the_calendar_is_behind_the_hrms_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/hrms/holidays')->assertForbidden();
    }

    private function openCount(int $import): int
    {
        return (int) $this->getJson("/api/v1/hrms/attendance-imports/{$import}")->json('data.open_count');
    }
}
