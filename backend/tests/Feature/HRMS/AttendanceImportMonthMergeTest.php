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
 * THE MONTH IS THE UNIT, NOT THE UPLOAD.
 *
 * The office uploads the month as it goes: the 1st to the 2nd today, the
 * 1st to the 3rd tomorrow. Every upload carries the punch app's ORIGINAL
 * figures, because corrections are made here and the app never learns
 * about them. So a second upload must not be a second month, and it must
 * never hand the app's original back over somebody's answer.
 *
 * The rules this pins:
 *   · an upload overlapping a month still under review JOINS it, and the
 *     month's period grows to cover both;
 *   · a day nobody has answered is refreshed and re-judged;
 *   · a day a PERSON answered keeps that answer, and if the app's own
 *     figures for it have changed the day is stamped so the screen can
 *     offer it for a second look;
 *   · an APPLIED month is never reopened by an upload.
 */
class AttendanceImportMonthMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'Anand', 'date_of_joining' => '2026-09-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
        ]);
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['hrms.view', 'hrms.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    /** @param  list<array<string, mixed>>  $days */
    private function upload(string $from, string $to, array $days, string $file = 'sept.xlsx'): TestResponse
    {
        return $this->postJson('/api/v1/hrms/attendance-imports', [
            'period_from' => $from,
            'period_to' => $to,
            'source' => 'pooja',
            'file_name' => $file,
            'employees' => [[
                'employee_code' => 'SPP-01', 'name' => 'ANAND',
                'department' => 'Production Department', 'designation' => 'Packing Staff',
                'days' => $days,
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function day(string $date, string $status, ?string $in, ?string $out, int $worked): array
    {
        return ['date' => $date, 'status' => $status, 'first_in' => $in, 'last_out' => $out, 'worked_minutes' => $worked];
    }

    public function test_a_second_upload_of_the_same_month_joins_it_rather_than_starting_another(): void
    {
        $this->actAs();

        $first = $this->upload('2026-09-01', '2026-09-02', [
            $this->day('2026-09-01', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-02', 'FD', '06:00', '14:00', 480),
        ])->assertCreated();
        $id = $first->json('data.id');

        $second = $this->upload('2026-09-01', '2026-09-03', [
            $this->day('2026-09-01', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-02', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-03', 'FD', '06:00', '14:00', 480),
        ])->assertCreated();

        $this->assertSame($id, $second->json('data.id'), 'the second upload should have joined the first month');
        $this->assertSame(1, AttendanceImport::query()->count());
        $this->assertSame(3, $second->json('data.day_count'));
        $this->assertSame('2026-09-01', $second->json('data.period_from'));
        $this->assertSame('2026-09-03', $second->json('data.period_to'));
        $this->assertSame(3, AttendanceImportLine::query()->count());
    }

    public function test_the_month_grows_backwards_too(): void
    {
        $this->actAs();

        $this->upload('2026-09-10', '2026-09-11', [
            $this->day('2026-09-10', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-11', 'FD', '06:00', '14:00', 480),
        ])->assertCreated();

        $second = $this->upload('2026-09-08', '2026-09-11', [
            $this->day('2026-09-08', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-09', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-10', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-11', 'FD', '06:00', '14:00', 480),
        ])->assertCreated();

        $this->assertSame('2026-09-08', $second->json('data.period_from'));
        $this->assertSame('2026-09-11', $second->json('data.period_to'));
        $this->assertSame(4, $second->json('data.day_count'));
    }

    public function test_a_day_nobody_answered_is_refreshed_and_judged_again(): void
    {
        $this->actAs();

        // Day one came in with no out-punch, so it waits for somebody.
        $this->upload('2026-09-01', '2026-09-01', [
            $this->day('2026-09-01', 'FD', '06:00', null, 0),
        ])->assertCreated();
        $this->assertSame('in_no_out', AttendanceImportLine::query()->first()->issue->value);

        // The app has since paired the out-punch. Nobody had answered, so
        // the day takes the better data and is judged again.
        $this->upload('2026-09-01', '2026-09-02', [
            $this->day('2026-09-01', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-02', 'Absent', null, null, 0),
        ])->assertCreated();

        $line = AttendanceImportLine::query()->where('date', '2026-09-01')->firstOrFail();
        $this->assertNull($line->issue);
        $this->assertSame('present', $line->resolution->value);
        $this->assertSame('14:00', substr((string) $line->last_out, 0, 5));
        $this->assertNull($line->report_changed_at);
    }

    public function test_a_day_a_person_answered_keeps_that_answer_and_is_stamped_when_the_report_moves(): void
    {
        $this->actAs();

        $this->upload('2026-09-01', '2026-09-01', [
            $this->day('2026-09-01', 'FD', '06:00', null, 0),
        ])->assertCreated();
        $import = AttendanceImport::query()->firstOrFail();
        $line = AttendanceImportLine::query()->firstOrFail();

        // A person says it was a full day ending at 14:30.
        $this->patchJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/{$line->id}", [
            'resolution' => 'present',
            'check_out' => '14:30',
        ])->assertOk();

        // The app now claims the same day was a half day ending at 10:00.
        $this->upload('2026-09-01', '2026-09-02', [
            $this->day('2026-09-01', 'HD', '06:00', '10:00', 240),
            $this->day('2026-09-02', 'FD', '06:00', '14:00', 480),
        ])->assertCreated();

        $line->refresh();
        $this->assertSame('present', $line->resolution->value, 'the answer must survive the re-upload');
        $this->assertSame('14:30', substr((string) $line->resolved_check_out, 0, 5));
        $this->assertNotNull($line->report_changed_at, 'the day should be offered for a second look');
        // The report's own figures are still recorded, so the screen can
        // show what the app now says beside what was decided.
        $this->assertSame('10:00', substr((string) $line->last_out, 0, 5));
        $this->assertSame(240, $line->worked_minutes);

        // And what was written to attendance is still the person's answer.
        $attendance = Attendance::query()->firstOrFail();
        $this->assertSame('present', $attendance->status->value);
    }

    public function test_an_answered_day_the_report_repeats_unchanged_is_left_quiet(): void
    {
        $this->actAs();

        $this->upload('2026-09-01', '2026-09-01', [
            $this->day('2026-09-01', 'Absent', null, null, 0),
        ])->assertCreated();
        $import = AttendanceImport::query()->firstOrFail();
        $line = AttendanceImportLine::query()->firstOrFail();
        $this->patchJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/{$line->id}", ['resolution' => 'on_leave'])->assertOk();

        $this->upload('2026-09-01', '2026-09-02', [
            $this->day('2026-09-01', 'Absent', null, null, 0),
            $this->day('2026-09-02', 'FD', '06:00', '14:00', 480),
        ])->assertCreated();

        $line->refresh();
        $this->assertSame('on_leave', $line->resolution->value);
        $this->assertNull($line->report_changed_at, 'nothing changed, so nothing to look at again');
    }

    public function test_the_days_the_report_moved_under_are_a_filter_of_their_own(): void
    {
        $this->actAs();

        $this->upload('2026-09-01', '2026-09-01', [$this->day('2026-09-01', 'FD', '06:00', null, 0)])->assertCreated();
        $import = AttendanceImport::query()->firstOrFail();
        $line = AttendanceImportLine::query()->firstOrFail();
        $this->patchJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/{$line->id}", ['resolution' => 'present'])->assertOk();

        $this->upload('2026-09-01', '2026-09-01', [$this->day('2026-09-01', 'HD', '06:00', '10:00', 240)])->assertCreated();

        $listed = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/lines?issue=report_changed")->assertOk();
        $this->assertSame(1, $listed->json('meta.total'));

        $counts = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}")->json('data.counts');
        $this->assertSame(1, $counts['report_changed']);
    }

    public function test_an_applied_month_is_not_reopened_by_another_upload(): void
    {
        $this->actAs();

        $this->upload('2026-09-01', '2026-09-01', [$this->day('2026-09-01', 'FD', '06:00', '14:00', 480)])->assertCreated();
        $import = AttendanceImport::query()->firstOrFail();
        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/apply")->assertOk();

        $this->upload('2026-09-01', '2026-09-02', [
            $this->day('2026-09-01', 'FD', '06:00', '14:00', 480),
            $this->day('2026-09-02', 'FD', '06:00', '14:00', 480),
        ])->assertUnprocessable();

        $this->assertSame(1, AttendanceImport::query()->count());
        $this->assertSame(1, AttendanceImportLine::query()->count());
    }

    public function test_a_month_that_does_not_overlap_is_its_own_run(): void
    {
        $this->actAs();

        $this->upload('2026-09-01', '2026-09-30', [$this->day('2026-09-01', 'FD', '06:00', '14:00', 480)])->assertCreated();
        $october = $this->upload('2026-10-01', '2026-10-31', [$this->day('2026-10-01', 'FD', '06:00', '14:00', 480)])->assertCreated();

        $this->assertSame(2, AttendanceImport::query()->count());
        $this->assertSame('2026-10-01', $october->json('data.period_from'));
    }
}
