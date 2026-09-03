<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\AttendanceImport;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE ATTENDANCE PAGE READS THE UPLOAD FOR A PERIOD NOBODY HAS APPLIED YET.
 *
 * A month is uploaded, reviewed over days, and applied at the end — and
 * until that last step `attendances` holds nothing for it. The page was
 * therefore blank for the very month the office was working on, which is
 * the month they most want to look at.
 *
 * So the reads fall back, PER DAY:
 *   · an APPLIED day is the record and always wins;
 *   · a day with no attendance row takes the uploaded line's answer, marked
 *     as coming from an upload nobody has applied;
 *   · a day the reviewer has not answered shows as NEEDS REVIEW and is
 *     counted apart — it is not present, absent, or anything else yet, and
 *     the software does not get to pick.
 *
 * WEEK OFF falls out of the same rule and is why the fallback is per day
 * rather than per period: `attendances` has no week-off status, so even an
 * APPLIED month has no row for those days, and the upload is the only place
 * that knows.
 */
class AttendanceReadsTheUploadTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anand;

    private Employee $bala;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anand = Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'Anand', 'date_of_joining' => '2026-01-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
        ]);
        $this->bala = Employee::create([
            'employee_code' => 'SPP-02', 'name' => 'Bala', 'date_of_joining' => '2026-01-01',
            'department' => 'Stores Department', 'designation' => 'Store Incharge',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions = ['hrms.view', 'hrms.manage']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function uploadRun(): AttendanceImport
    {
        return AttendanceImport::create([
            'source' => 'pooja',
            'period_from' => '2026-07-01',
            'period_to' => '2026-07-31',
            'file_name' => 'july.xlsx',
            'uploaded_by' => User::factory()->create()->id,
            'status' => 'review',
        ]);
    }

    private function line(AttendanceImport $import, Employee $employee, string $date, ?string $resolution, ?string $issue = null): AttendanceImportLine
    {
        return AttendanceImportLine::create([
            'attendance_import_id' => $import->id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->name,
            'date' => $date,
            'raw_status' => 'FD',
            'first_in' => $resolution === null ? '06:00' : '06:00',
            'last_out' => $resolution === null ? null : '14:00',
            'worked_minutes' => $resolution === null ? 0 : 480,
            'issue' => $issue,
            'resolution' => $resolution,
            'resolved_check_in' => $resolution === null ? null : '06:00',
            'resolved_check_out' => $resolution === null ? null : '14:00',
        ]);
    }

    // ---- one person -------------------------------------------------------

    public function test_a_month_nobody_applied_still_shows_the_days_the_upload_answered(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'present');
        $this->line($import, $this->anand, '2026-07-02', 'half_day');
        $this->line($import, $this->anand, '2026-07-03', 'week_off');
        $this->line($import, $this->anand, '2026-07-04', null, 'in_no_out');

        $response = $this->getJson(
            "/api/v1/hrms/attendance/person?employee_id={$this->anand->id}&from=2026-07-01&to=2026-07-31"
        )->assertOk();

        $this->assertSame(
            ['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'],
            $response->json('data.days.*.date'),
        );
        $this->assertSame(['present', 'half_day', 'week_off', null], $response->json('data.days.*.status'));

        // Every one of them says where it came from, so nothing provisional
        // can be mistaken for the record.
        $this->assertSame(['import', 'import', 'import', 'import'], $response->json('data.days.*.source'));
        $this->assertSame(true, $response->json('data.days.3.needs_review'));
        $this->assertSame(false, $response->json('data.days.0.needs_review'));

        $summary = $response->json('data.summary');
        $this->assertSame(1, $summary['present']);
        $this->assertSame(1, $summary['half_day']);
        $this->assertSame(2, $summary['recorded'], 'a week off and an unanswered day are not attendance');
        $this->assertSame(1, $summary['week_off']);
        $this->assertSame(1, $summary['needs_review']);
        $this->assertSame(4, $summary['from_import']);
    }

    public function test_an_applied_day_wins_over_what_the_upload_said(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        // The upload still carries the punch app's original …
        $this->line($import, $this->anand, '2026-07-01', 'half_day');
        // … and somebody has since applied a full day for it.
        Attendance::create([
            'employee_id' => $this->anand->id, 'date' => '2026-07-01', 'status' => 'present', 'notes' => 'corrected',
        ]);

        $response = $this->getJson(
            "/api/v1/hrms/attendance/person?employee_id={$this->anand->id}&from=2026-07-01&to=2026-07-31"
        )->assertOk();

        $this->assertSame(['present'], $response->json('data.days.*.status'));
        $this->assertSame(['attendance'], $response->json('data.days.*.source'));
        $this->assertSame('corrected', $response->json('data.days.0.notes'));
        $this->assertSame(0, $response->json('data.summary.from_import'));
    }

    public function test_a_week_off_shows_even_on_an_applied_month(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $import->update(['status' => 'applied', 'applied_at' => now()]);
        // Applying wrote attendance for the working day and, by design,
        // nothing at all for the week off — attendances has no such status.
        $this->line($import, $this->anand, '2026-07-01', 'present');
        $this->line($import, $this->anand, '2026-07-05', 'week_off');
        Attendance::create(['employee_id' => $this->anand->id, 'date' => '2026-07-01', 'status' => 'present']);

        $response = $this->getJson(
            "/api/v1/hrms/attendance/person?employee_id={$this->anand->id}&from=2026-07-01&to=2026-07-31"
        )->assertOk();

        $this->assertSame(['attendance', 'import'], $response->json('data.days.*.source'));
        $this->assertSame(['present', 'week_off'], $response->json('data.days.*.status'));
        $this->assertSame(1, $response->json('data.summary.week_off'));
        // And NOTHING here is provisional. The week off came from the upload
        // because applying deliberately writes no row for one — the month is
        // finished, and a screen must not call it half-done for ever after.
        $this->assertSame(0, $response->json('data.summary.from_import'));
        $this->assertSame([false, false], $response->json('data.days.*.provisional'));
    }

    public function test_an_applied_month_is_never_called_provisional_by_department_either(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $import->update(['status' => 'applied', 'applied_at' => now()]);
        $this->line($import, $this->anand, '2026-07-01', 'week_off');
        $this->line($import, $this->anand, '2026-07-02', 'present');
        Attendance::create(['employee_id' => $this->anand->id, 'date' => '2026-07-02', 'status' => 'present']);

        $response = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-07-01&to=2026-07-31')->assertOk();

        $this->assertSame(0, $response->json('data.totals.from_import'));
        $this->assertSame(1, $response->json('data.totals.week_off'), 'the week off is still read from the upload');
        $departments = collect($response->json('data.departments'))->keyBy('department');
        $this->assertSame(0, $departments['Production Department']['from_import']);
    }

    public function test_a_period_the_upload_does_not_cover_is_still_empty(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'present');

        $response = $this->getJson(
            "/api/v1/hrms/attendance/person?employee_id={$this->anand->id}&from=2026-08-01&to=2026-08-31"
        )->assertOk();

        $this->assertSame([], $response->json('data.days'));
        $this->assertSame(0, $response->json('data.summary.recorded'));
    }

    // ---- the factory ------------------------------------------------------

    public function test_the_department_summary_counts_the_upload_too(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'present');
        $this->line($import, $this->anand, '2026-07-02', 'absent');
        $this->line($import, $this->anand, '2026-07-03', null, 'no_punch');
        $this->line($import, $this->bala, '2026-07-01', 'present');
        // One day already applied, in Production.
        Attendance::create(['employee_id' => $this->anand->id, 'date' => '2026-07-04', 'status' => 'present']);

        $response = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-07-01&to=2026-07-31')->assertOk();

        $departments = collect($response->json('data.departments'))->keyBy('department');
        $this->assertSame(2, $departments['Production Department']['present'], 'one applied, one from the upload');
        $this->assertSame(1, $departments['Production Department']['absent']);
        $this->assertSame(1, $departments['Production Department']['needs_review']);
        $this->assertSame(1, $departments['Stores Department']['present']);

        $totals = $response->json('data.totals');
        $this->assertSame(3, $totals['present']);
        $this->assertSame(4, $totals['recorded']);
        $this->assertSame(1, $totals['needs_review']);
        // All four uploaded days are provisional — three of Anand's and one
        // of Bala's — and the screen has to be able to say so. The fifth day
        // is the applied one.
        $this->assertSame(4, $totals['from_import']);
        $this->assertSame(2, $totals['employees']);
    }

    public function test_the_summary_says_which_uploads_it_is_reading_and_whether_they_are_applied(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'present');

        $sources = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-07-01&to=2026-07-31')
            ->assertOk()->json('data.imports');

        // Never a literal id: CI's MySQL keeps auto-increment across tests,
        // so this row is id 1 locally and id 45 there.
        $this->assertSame($import->id, $sources[0]['id']);
        $this->assertSame('july.xlsx', $sources[0]['file_name']);
        $this->assertSame('review', $sources[0]['status']);
        $this->assertSame('2026-07-01', $sources[0]['period_from']);
    }

    public function test_an_unknown_employee_line_belongs_to_nobody_and_is_not_counted(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        AttendanceImportLine::create([
            'attendance_import_id' => $import->id,
            'employee_id' => null,
            'employee_code' => 'ZZZ-99',
            'employee_name' => 'NOBODY',
            'date' => '2026-07-01',
            'raw_status' => 'FD',
            'worked_minutes' => 480,
            'issue' => 'unknown_employee',
            'resolution' => null,
        ]);

        $totals = $this->getJson('/api/v1/hrms/attendance/summary?from=2026-07-01&to=2026-07-31')
            ->assertOk()->json('data.totals');

        $this->assertSame(0, $totals['recorded']);
        $this->assertSame(0, $totals['needs_review'], 'a line with no employee cannot be counted under a department');
    }

    // ---- the list of all marks ---------------------------------------------

    public function test_the_list_of_all_marks_shows_the_uploaded_days_too(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'present');
        $this->line($import, $this->anand, '2026-07-02', null, 'no_punch');
        $this->line($import, $this->bala, '2026-07-01', 'absent');
        Attendance::create(['employee_id' => $this->anand->id, 'date' => '2026-07-03', 'status' => 'present']);

        $response = $this->getJson('/api/v1/hrms/attendance?from=2026-07-01&to=2026-07-31')->assertOk();

        $this->assertSame(4, $response->json('meta.total'));
        // The applied day first — newest date — then the uploaded ones.
        $this->assertSame(['attendance', 'import', 'import', 'import'], $response->json('data.*.source'));
        // Newest date first: the applied 3rd, then the unanswered 2nd, then
        // the two people on the 1st.
        $this->assertSame([false, true, false, false], $response->json('data.*.needs_review'));
        // An unanswered day has NO status rather than being left out.
        $this->assertNull($response->json('data.1.status'), 'the unanswered day is listed with no status at all');
        $this->assertSame('Anand', $response->json('data.1.employee.name'));
        // Nothing applied is provisional; everything from this run is.
        $this->assertSame([false, true, true, true], $response->json('data.*.provisional'));
    }

    public function test_an_applied_day_appears_once_and_as_the_record(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'half_day');
        Attendance::create([
            'employee_id' => $this->anand->id, 'date' => '2026-07-01', 'status' => 'present', 'notes' => 'corrected',
        ]);

        $response = $this->getJson('/api/v1/hrms/attendance?from=2026-07-01&to=2026-07-31')->assertOk();

        $this->assertSame(1, $response->json('meta.total'), 'one day is one row, not two');
        $this->assertSame('present', $response->json('data.0.status'));
        $this->assertSame('corrected', $response->json('data.0.notes'));
        $this->assertSame('attendance', $response->json('data.0.source'));
    }

    public function test_a_day_uploaded_twice_is_listed_once_as_the_newer_reading(): void
    {
        $this->actAs();
        $first = $this->uploadRun();
        $this->line($first, $this->anand, '2026-07-01', 'absent');
        $second = $this->uploadRun();
        $this->line($second, $this->anand, '2026-07-01', 'present');

        $response = $this->getJson('/api/v1/hrms/attendance?from=2026-07-01&to=2026-07-31')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('present', $response->json('data.0.status'));
    }

    public function test_the_list_filters_reach_the_uploaded_days_as_well(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'present');
        $this->line($import, $this->anand, '2026-07-02', 'absent');
        $this->line($import, $this->bala, '2026-07-01', 'present');

        $this->assertSame(2, $this->getJson('/api/v1/hrms/attendance?status=present')->assertOk()->json('meta.total'));
        $this->assertSame(
            2,
            $this->getJson("/api/v1/hrms/attendance?employee_id={$this->anand->id}")->assertOk()->json('meta.total'),
        );
        $this->assertSame(1, $this->getJson('/api/v1/hrms/attendance?q=SPP-02')->assertOk()->json('meta.total'), 'by code');
        $this->assertSame(1, $this->getJson('/api/v1/hrms/attendance?q=Stores')->assertOk()->json('meta.total'), 'by department');
        $this->assertSame(
            2,
            $this->getJson('/api/v1/hrms/attendance?from=2026-07-01&to=2026-07-01')->assertOk()->json('meta.total'),
        );
    }

    public function test_a_listed_uploaded_day_carries_the_factory_clock(): void
    {
        $this->actAs();
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'present');

        // 06:00 IST is 00:30 UTC, and the API has always spelled an instant
        // in UTC — an uploaded wall clock has to be converted, not copied.
        $this->assertSame(
            '2026-07-01T00:30:00+00:00',
            $this->getJson('/api/v1/hrms/attendance?from=2026-07-01&to=2026-07-31')->assertOk()->json('data.0.check_in'),
        );
    }

    // ---- the printed sheet ------------------------------------------------

    public function test_the_printed_sheet_reads_the_upload_as_well(): void
    {
        $this->actAs(['hrms.view']);
        $import = $this->uploadRun();
        $this->line($import, $this->anand, '2026-07-01', 'present');
        $this->line($import, $this->anand, '2026-07-02', null, 'no_punch');

        $html = view('pdf.attendance-month', app(AttendanceService::class)
            ->monthSheet($this->anand, '2026-07-01', '2026-07-03'))->render();

        $this->assertStringContainsString('Present', $html);
        // An unanswered day is not blank and is not absent — it says so.
        $this->assertStringContainsString('needs review', $html);
        // And the sheet says the month has not been applied, so nobody
        // treats a provisional print as the final word.
        $this->assertStringContainsString('not yet applied', $html);
    }
}
