<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\AttendanceImport;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The punch-report import's four routes (03-Sep design, Track 2): upload
 * the parsed rows, page and search the review list, correct a line, apply
 * the run. What reaches `attendances`, and when, is the contract: nothing
 * on upload; one employee-day per correction; everything else on Apply —
 * and Apply refuses with the count while an issue line is unanswered.
 *
 * Every name and figure here is synthetic.
 */
class AttendanceImportTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anand;

    private Employee $bala;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anand = Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'ANAND', 'date_of_joining' => '2026-09-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
        ]);
        $this->bala = Employee::create([
            'employee_code' => 'SPP-02', 'name' => 'BALA', 'date_of_joining' => '2026-09-01',
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

    /** @return array<string, mixed> */
    private function day(string $date, string $status, ?string $in, ?string $out, array $extra = []): array
    {
        return ['date' => $date, 'status' => $status, 'first_in' => $in, 'last_out' => $out, ...$extra];
    }

    /**
     * Anand: a clean full day, a half day, an in-without-out, a week off
     * and an absence. Bala: one clean day. ZZZ-99 is nobody: one day.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'period_from' => '2026-07-01',
            'period_to' => '2026-07-05',
            'source' => 'pooja',
            'file_name' => 'july.xlsx',
            'employees' => [
                [
                    'employee_code' => 'SPP-01', 'name' => 'ANAND', 'department' => 'Production Department', 'designation' => 'Packing Staff',
                    'days' => [
                        $this->day('2026-07-01', 'FD', '10:10', '20:20', ['ot_minutes' => 129, 'late_minutes' => 40, 'worked_minutes' => 609]),
                        $this->day('2026-07-02', 'HD', '10:00', '14:00', ['worked_minutes' => 240]),
                        $this->day('2026-07-03', 'FD', '09:58', null, ['worked_minutes' => 0]),
                        $this->day('2026-07-04', 'Week Off', null, null),
                        $this->day('2026-07-05', 'Absent', null, null),
                    ],
                ],
                [
                    'employee_code' => 'SPP-02', 'name' => 'BALA', 'department' => 'Stores Department', 'designation' => 'Store Incharge',
                    'days' => [$this->day('2026-07-01', 'FD', '09:00', '18:00', ['worked_minutes' => 540])],
                ],
                [
                    'employee_code' => 'ZZZ-99', 'name' => 'NOBODY', 'department' => null, 'designation' => null,
                    'days' => [$this->day('2026-07-01', 'FD', '09:00', '18:00')],
                ],
            ],
        ];
    }

    private function upload(): TestResponse
    {
        return $this->postJson('/api/v1/hrms/attendance-imports', $this->payload());
    }

    private function line(int $importId, string $code, string $date): AttendanceImportLine
    {
        return AttendanceImportLine::query()
            ->where('attendance_import_id', $importId)
            ->where('employee_code', $code)
            ->whereDate('date', $date)
            ->firstOrFail();
    }

    // ---- upload -------------------------------------------------------------------

    public function test_upload_keeps_every_employee_day_classified_and_writes_no_attendance(): void
    {
        $this->actAs(['hrms.manage']);

        $response = $this->upload()->assertCreated();

        $response->assertJsonPath('data.status', 'review')
            ->assertJsonPath('data.period_from', '2026-07-01')
            ->assertJsonPath('data.period_to', '2026-07-05')
            ->assertJsonPath('data.employee_count', 3)
            ->assertJsonPath('data.day_count', 7)
            ->assertJsonPath('data.issue_count', 3)
            ->assertJsonPath('data.open_count', 3)
            ->assertJsonPath('data.file_name', 'july.xlsx');

        $id = (int) $response->json('data.id');
        $this->assertSame(7, AttendanceImportLine::where('attendance_import_id', $id)->count());
        $this->assertSame(0, Attendance::count(), 'nothing reaches attendances on upload');

        $full = $this->line($id, 'SPP-01', '2026-07-01');
        $this->assertNull($full->issue);
        $this->assertSame('present', $full->resolution->value);
        $this->assertSame('10:10:00', $full->first_in);
        $this->assertSame('20:20:00', $full->last_out);
        $this->assertSame('10:10:00', $full->resolved_check_in);
        $this->assertSame(129, $full->ot_minutes);
        $this->assertSame($this->anand->id, $full->employee_id);

        $this->assertSame('half_day', $this->line($id, 'SPP-01', '2026-07-02')->resolution->value);
        $this->assertSame('in_no_out', $this->line($id, 'SPP-01', '2026-07-03')->issue->value);
        $this->assertSame('week_off', $this->line($id, 'SPP-01', '2026-07-04')->resolution->value);
        $this->assertSame('no_punch', $this->line($id, 'SPP-01', '2026-07-05')->issue->value);

        $nobody = $this->line($id, 'ZZZ-99', '2026-07-01');
        $this->assertSame('unknown_employee', $nobody->issue->value);
        $this->assertNull($nobody->employee_id);
    }

    public function test_upload_refuses_a_malformed_body_and_a_duplicate_employee_day(): void
    {
        $this->actAs(['hrms.manage']);

        $this->postJson('/api/v1/hrms/attendance-imports', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_from', 'period_to', 'source', 'employees']);

        $bad = $this->payload();
        $bad['employees'][0]['days'][0]['first_in'] = '10:10 AM';
        $this->postJson('/api/v1/hrms/attendance-imports', $bad)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employees.0.days.0.first_in']);

        $outside = $this->payload();
        $outside['employees'][0]['days'][0]['date'] = '2026-08-01';
        $this->postJson('/api/v1/hrms/attendance-imports', $outside)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employees.0.days.0.date']);

        $twice = $this->payload();
        $twice['employees'][1]['days'][] = $this->day('2026-07-01', 'FD', '09:00', '18:00');
        $this->postJson('/api/v1/hrms/attendance-imports', $twice)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employees']);

        $this->assertSame(0, AttendanceImport::count());
    }

    // ---- permissions ---------------------------------------------------------------

    public function test_a_viewer_may_read_but_not_write(): void
    {
        $this->actAs(['hrms.manage']);
        $id = (int) $this->upload()->assertCreated()->json('data.id');
        $line = $this->line($id, 'SPP-01', '2026-07-03');

        $this->actAs(['hrms.view']);
        $this->getJson('/api/v1/hrms/attendance-imports')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/hrms/attendance-imports/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson("/api/v1/hrms/attendance-imports/{$id}/lines")->assertOk()->assertJsonPath('meta.total', 7);

        $this->postJson('/api/v1/hrms/attendance-imports', $this->payload())->assertForbidden();
        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$line->id}", ['resolution' => 'absent'])->assertForbidden();
        $this->postJson("/api/v1/hrms/attendance-imports/{$id}/apply")->assertForbidden();

        $this->actAs(['sales.view']);
        $this->getJson('/api/v1/hrms/attendance-imports')->assertForbidden();
    }

    // ---- the review list ---------------------------------------------------------

    public function test_lines_are_open_issues_first_and_the_chips_and_search_narrow_on_the_server(): void
    {
        $this->actAs(['hrms.manage']);
        $id = (int) $this->upload()->assertCreated()->json('data.id');
        $base = "/api/v1/hrms/attendance-imports/{$id}/lines";

        $all = $this->getJson($base)->assertOk();
        $this->assertSame(7, $all->json('meta.total'));
        $this->assertSame(
            ['in_no_out', 'no_punch', 'unknown_employee', null, null, null, null],
            $all->json('data.*.issue'),
            'open issues first, in employee then date order, then the clean lines',
        );
        $this->assertSame('10:10', $all->json('data.3.first_in'), 'wall-clock HH:MM on the wire');

        $this->assertSame(3, $this->getJson("{$base}?issue=open")->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?issue=in_no_out")->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?issue=no_punch")->assertOk()->json('meta.total'));
        $this->assertSame(0, $this->getJson("{$base}?issue=out_no_in")->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?issue=unknown_employee")->assertOk()->json('meta.total'));
        $this->assertSame(0, $this->getJson("{$base}?issue=resolved")->assertOk()->json('meta.total'));
        $this->assertSame(4, $this->getJson("{$base}?issue=clean")->assertOk()->json('meta.total'));

        $this->assertSame(5, $this->getJson("{$base}?q=spp-01")->assertOk()->json('meta.total'), 'by code, any case');
        $this->assertSame(1, $this->getJson("{$base}?q=nobody")->assertOk()->json('meta.total'), 'an unknown employee is found by the name the report printed');
        $this->assertSame(1, $this->getJson("{$base}?q=bala&issue=clean")->assertOk()->json('meta.total'));
        $this->assertSame(0, $this->getJson("{$base}?q=%25")->assertOk()->json('meta.total'), 'a typed % is a character');

        $page = $this->getJson("{$base}?per_page=3&page=3")->assertOk();
        $this->assertSame(3, $page->json('meta.last_page'));
        $this->assertCount(1, $page->json('data'));

        $this->getJson("{$base}?issue=late")->assertUnprocessable()->assertJsonValidationErrors(['issue']);
        $this->getJson("{$base}?per_page=101")->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/hrms/attendance-imports/999/lines')->assertNotFound();
    }

    // ---- correcting a line ---------------------------------------------------------

    public function test_a_correction_is_stored_on_the_line_and_written_to_attendances_as_a_factory_time_instant(): void
    {
        $reviewer = $this->actAs(['hrms.manage']);
        $id = (int) $this->upload()->assertCreated()->json('data.id');
        $line = $this->line($id, 'SPP-01', '2026-07-03');

        $response = $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$line->id}", [
            'resolution' => 'present',
            'check_out' => '19:30',
            'notes' => 'forgot to punch out',
        ])->assertOk();

        $response->assertJsonPath('data.resolution', 'present')
            ->assertJsonPath('data.resolved_check_in', '09:58')
            ->assertJsonPath('data.resolved_check_out', '19:30')
            ->assertJsonPath('data.issue', 'in_no_out')
            ->assertJsonPath('data.notes', 'forgot to punch out')
            ->assertJsonPath('data.resolved_by.id', $reviewer->id);
        $this->assertNotNull($response->json('data.resolved_at'));
        $this->assertNotNull($response->json('data.applied_at'));

        $mark = Attendance::where('employee_id', $this->anand->id)->whereDate('date', '2026-07-03')->firstOrFail();
        $this->assertSame('present', $mark->status->value);
        $this->assertSame('forgot to punch out', $mark->notes);
        // 09:58 IST is 04:28 UTC — the instant, not the wall-clock copied
        // into a UTC column.
        $this->assertSame('2026-07-03 04:28:00', $mark->check_in->toDateTimeString());
        $this->assertSame('2026-07-03 14:00:00', $mark->check_out->toDateTimeString());
        $this->assertSame(1, Attendance::count(), 'only the corrected day was written');

        $this->getJson("/api/v1/hrms/attendance-imports/{$id}")->assertOk()->assertJsonPath('data.open_count', 2);
        $this->assertSame(1, $this->getJson("/api/v1/hrms/attendance-imports/{$id}/lines?issue=resolved")->json('meta.total'));

        // Corrected again: the same attendance row, updated in place.
        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$line->id}", ['resolution' => 'absent'])->assertOk();
        $this->assertSame(1, Attendance::count());
        $this->assertSame('absent', $mark->fresh()->status->value);
        $this->assertNull($mark->fresh()->check_in, 'an absence carries no punch');
    }

    public function test_a_week_off_correction_writes_nothing_and_an_unknown_employee_is_refused_until_they_exist(): void
    {
        $this->actAs(['hrms.manage']);
        $id = (int) $this->upload()->assertCreated()->json('data.id');

        $absent = $this->line($id, 'SPP-01', '2026-07-05');
        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$absent->id}", ['resolution' => 'week_off'])
            ->assertOk()
            ->assertJsonPath('data.resolution', 'week_off');
        $this->assertSame(0, Attendance::count());

        $nobody = $this->line($id, 'ZZZ-99', '2026-07-01');
        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$nobody->id}", ['resolution' => 'present'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resolution']);

        Employee::create(['employee_code' => 'ZZZ-99', 'name' => 'NOBODY', 'date_of_joining' => '2026-09-01']);
        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$nobody->id}", ['resolution' => 'present'])
            ->assertOk()
            ->assertJsonPath('data.issue', null)
            ->assertJsonPath('data.resolution', 'present');
        $this->assertSame(1, Attendance::count());

        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$nobody->id}", ['resolution' => 'late'])
            ->assertUnprocessable()->assertJsonValidationErrors(['resolution']);
        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$nobody->id}", ['resolution' => 'present', 'check_in' => '9am'])
            ->assertUnprocessable()->assertJsonValidationErrors(['check_in']);

        // A line of another run is not this run's.
        $other = (int) $this->upload()->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/hrms/attendance-imports/{$other}/lines/{$nobody->id}", ['resolution' => 'present'])->assertNotFound();
    }

    // ---- apply ----------------------------------------------------------------------

    public function test_apply_refuses_with_the_count_while_issue_lines_are_open(): void
    {
        $this->actAs(['hrms.manage']);
        $id = (int) $this->upload()->assertCreated()->json('data.id');

        $refused = $this->postJson("/api/v1/hrms/attendance-imports/{$id}/apply")->assertUnprocessable();
        $this->assertSame('3 lines still need a correction.', $refused->json('errors.lines.0'));
        $this->assertSame(0, Attendance::count(), 'a refused apply writes nothing');
        $this->assertSame('review', AttendanceImport::findOrFail($id)->status->value);
    }

    public function test_apply_writes_every_clean_and_resolved_line_and_marks_the_run_applied(): void
    {
        $this->actAs(['hrms.manage']);
        $id = (int) $this->upload()->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$this->line($id, 'SPP-01', '2026-07-03')->id}", ['resolution' => 'present', 'check_out' => '19:00'])->assertOk();
        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$this->line($id, 'SPP-01', '2026-07-05')->id}", ['resolution' => 'on_leave'])->assertOk();
        // The unknown employee is added to the master; the line was clean
        // on its punches, so Apply re-links it without a correction.
        $nobody = Employee::create(['employee_code' => 'ZZZ-99', 'name' => 'NOBODY', 'date_of_joining' => '2026-09-01']);

        $applied = $this->postJson("/api/v1/hrms/attendance-imports/{$id}/apply")->assertOk();
        $applied->assertJsonPath('data.status', 'applied')->assertJsonPath('data.open_count', 0);
        $this->assertNotNull($applied->json('data.applied_at'));

        // Anand: 01 present, 02 half day, 03 present (corrected), 04 week
        // off (NOT written), 05 on leave. Bala: 01. Nobody: 01.
        $this->assertSame(6, Attendance::count());
        $anand = Attendance::where('employee_id', $this->anand->id)->orderBy('date')->get();
        $this->assertSame(['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-05'], $anand->map(fn ($a) => $a->date->toDateString())->all());
        $this->assertSame(['present', 'half_day', 'present', 'on_leave'], $anand->map(fn ($a) => $a->status->value)->all());
        $this->assertSame('2026-07-01 04:40:00', $anand[0]->check_in->toDateTimeString(), '10:10 IST as UTC');
        $this->assertSame('2026-07-01 14:50:00', $anand[0]->check_out->toDateTimeString());
        $this->assertSame(1, Attendance::where('employee_id', $nobody->id)->count());
        $this->assertSame(0, AttendanceImportLine::where('attendance_import_id', $id)->whereNull('applied_at')->count());

        $relinked = $this->line($id, 'ZZZ-99', '2026-07-01');
        $this->assertSame($nobody->id, $relinked->employee_id);
        $this->assertNull($relinked->issue);

        // Idempotent: a second apply (or a re-import of the same month)
        // updates the same rows, never duplicates them.
        $this->postJson("/api/v1/hrms/attendance-imports/{$id}/apply")->assertOk();
        $this->assertSame(6, Attendance::count());
    }

    public function test_runs_list_newest_first_with_counts(): void
    {
        $this->actAs(['hrms.manage']);
        $first = (int) $this->upload()->assertCreated()->json('data.id');
        $second = (int) $this->upload()->assertCreated()->json('data.id');

        $list = $this->getJson('/api/v1/hrms/attendance-imports?per_page=1')->assertOk();
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame($second, $list->json('data.0.id'));
        $this->assertSame(3, $list->json('data.0.open_count'));
        $this->assertSame($first, $this->getJson('/api/v1/hrms/attendance-imports?per_page=1&page=2')->assertOk()->json('data.0.id'));
    }
}
