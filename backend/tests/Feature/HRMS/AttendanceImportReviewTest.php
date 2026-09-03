<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\AttendanceImport;
use App\Modules\HRMS\Models\AttendanceImportLine;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Models\Enums\AttendanceImportIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE REVIEW SCREEN'S TWO READS AND ITS ONE BULK WRITE (03-Sep UX pass).
 *
 * A month of punches is 1,829 employee-days and 589 of them need an answer.
 * Answering them one modal at a time is not a screen a person finishes, so
 * the review works at two grains the flat line list cannot express: ONE ROW
 * PER EMPLOYEE with the month beside them, and ONE ANSWER FOR ONE KIND OF
 * PROBLEM at a time. Both are reads and writes over the same lines the
 * per-line correction already writes — nothing here is a second way into
 * `attendances`.
 *
 * Every name and figure is synthetic.
 */
class AttendanceImportReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'ANAND', 'date_of_joining' => '2026-09-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
        ]);
        Employee::create([
            'employee_code' => 'SPP-02', 'name' => 'BALA', 'date_of_joining' => '2026-09-01',
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

    /** @return array<string, mixed> */
    /**
     * A day as the report prints it. `worked_minutes` defaults to a full
     * eight-hour shift when both punches are there, because that is what a
     * worked day looks like and the classifier now judges by the clock
     * (DEC-20260903-005).
     */
    private function day(string $date, string $status, ?string $in, ?string $out, ?int $workedMinutes = null): array
    {
        return [
            'date' => $date,
            'status' => $status,
            'first_in' => $in,
            'last_out' => $out,
            'worked_minutes' => $workedMinutes ?? ($in !== null && $out !== null ? 480 : 0),
        ];
    }

    /**
     * Anand: clean full day, in-without-out, week off, two no-punch days.
     * Bala: one clean day and one no-punch day. ZZZ-99 is nobody at all.
     */
    private function upload(): AttendanceImport
    {
        $this->postJson('/api/v1/hrms/attendance-imports', [
            'period_from' => '2026-07-01',
            'period_to' => '2026-07-05',
            'source' => 'pooja',
            'file_name' => 'july.xlsx',
            'employees' => [
                [
                    'employee_code' => 'SPP-01', 'name' => 'ANAND', 'department' => 'Production Department', 'designation' => 'Packing Staff',
                    'days' => [
                        $this->day('2026-07-01', 'FD', '09:00', '18:00'),
                        $this->day('2026-07-02', 'FD', '09:58', null),
                        $this->day('2026-07-03', 'Week Off', null, null),
                        $this->day('2026-07-04', 'Absent', null, null),
                        $this->day('2026-07-05', 'Absent', null, null),
                    ],
                ],
                [
                    'employee_code' => 'SPP-02', 'name' => 'BALA', 'department' => 'Stores Department', 'designation' => 'Store Incharge',
                    'days' => [
                        $this->day('2026-07-01', 'FD', '09:00', '18:00'),
                        $this->day('2026-07-02', 'Absent', null, null),
                    ],
                ],
                [
                    'employee_code' => 'ZZZ-99', 'name' => 'NOBODY', 'department' => null, 'designation' => null,
                    'days' => [$this->day('2026-07-01', 'FD', '09:00', '18:00')],
                ],
            ],
        ])->assertCreated();

        return AttendanceImport::query()->latest('id')->firstOrFail();
    }

    public function test_the_counts_carry_every_issue_kind_the_code_knows(): void
    {
        $this->actAs();
        $import = $this->upload();

        $counts = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}")->assertOk()->json('data.counts');

        // Built from the enum, so a kind added later cannot be left out and
        // silently show as nothing on the screen.
        foreach (AttendanceImportIssue::cases() as $issue) {
            $this->assertArrayHasKey($issue->value, $counts, "counts is missing {$issue->value}");
        }
        $this->assertArrayHasKey('open', $counts);
        $this->assertArrayHasKey('resolved', $counts);
        $this->assertArrayHasKey('clean', $counts);
    }

    // ---- one row per employee ------------------------------------------

    public function test_the_employee_view_carries_one_row_per_person_with_their_month(): void
    {
        $this->actAs();
        $import = $this->upload();

        $response = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/employees")->assertOk();

        // Three people, the one with the most to fix first.
        $this->assertSame(['SPP-01', 'SPP-02', 'ZZZ-99'], $response->json('data.*.employee_code'));
        $this->assertSame(3, $response->json('meta.total'));

        $anand = $response->json('data.0');
        $this->assertSame('ANAND', $anand['employee_name']);
        $this->assertSame('Production Department', $anand['department']);
        $this->assertTrue($anand['known']);
        $this->assertSame(3, $anand['open_count']);
        $this->assertSame(2, $anand['clean_count']);
        $this->assertSame(0, $anand['resolved_count']);

        // The month, in date order, as the strip draws it.
        $this->assertSame(
            [
                ['date' => '2026-07-01', 'state' => 'present'],
                ['date' => '2026-07-02', 'state' => 'needs_fix'],
                ['date' => '2026-07-03', 'state' => 'week_off'],
                ['date' => '2026-07-04', 'state' => 'needs_fix'],
                ['date' => '2026-07-05', 'state' => 'needs_fix'],
            ],
            $anand['days'],
        );
    }

    public function test_the_employee_master_spells_the_name_not_the_punch_file(): void
    {
        $this->actAs();
        // The report shouts every name in capitals; the master does not.
        Employee::query()->where('employee_code', 'SPP-01')->update(['name' => 'Anand Kumar']);
        $import = $this->upload();

        $rows = collect($this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/employees")->json('data'));

        $this->assertSame('Anand Kumar', $rows->firstWhere('employee_code', 'SPP-01')['employee_name']);
        // Somebody with no master record keeps the only name there is.
        $this->assertSame('NOBODY', $rows->firstWhere('employee_code', 'ZZZ-99')['employee_name']);
    }

    public function test_an_employee_missing_from_the_master_is_flagged_rather_than_hidden(): void
    {
        $this->actAs();
        $import = $this->upload();

        $rows = collect($this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/employees")->json('data'));
        $nobody = $rows->firstWhere('employee_code', 'ZZZ-99');

        $this->assertFalse($nobody['known']);
        $this->assertSame(1, $nobody['open_count']);
        $this->assertSame('needs_fix', $nobody['days'][0]['state']);
    }

    public function test_the_employee_view_searches_by_code_and_by_name_on_the_server(): void
    {
        $this->actAs();
        $import = $this->upload();

        $byName = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/employees?q=bala")->assertOk();
        $this->assertSame(['SPP-02'], $byName->json('data.*.employee_code'));

        $byCode = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/employees?q=SPP-01")->assertOk();
        $this->assertSame(['SPP-01'], $byCode->json('data.*.employee_code'));

        $none = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/employees?q=nobody-by-this-name")->assertOk();
        $this->assertSame([], $none->json('data'));
        $this->assertSame(0, $none->json('meta.total'));
    }

    public function test_the_employee_view_pages_on_the_server(): void
    {
        $this->actAs();
        $import = $this->upload();

        $page = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/employees?per_page=2&page=2")->assertOk();

        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.current_page'));
        $this->assertCount(1, $page->json('data'));
    }

    public function test_one_persons_days_are_readable_without_matching_another_code(): void
    {
        $this->actAs();
        $import = $this->upload();

        $lines = $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/lines?employee_code=SPP-01&issue=open")->assertOk();

        $this->assertSame(3, $lines->json('meta.total'));
        $this->assertSame(['SPP-01'], array_values(array_unique($lines->json('data.*.employee_code'))));
    }

    // ---- one answer for one kind of problem -----------------------------

    public function test_confirming_every_no_punch_day_as_absent_answers_them_in_one_request(): void
    {
        $this->actAs();
        $import = $this->upload();

        $response = $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'no_punch',
            'resolution' => 'absent',
        ])->assertOk();

        // Anand's two and Bala's one.
        $this->assertSame(3, $response->json('resolved'));
        $this->assertSame(0, $response->json('skipped'));
        // Anand's missing out-punch and the unknown person are what remain.
        $this->assertSame(2, $response->json('import.open_count'));

        $this->assertSame(3, Attendance::query()->where('status', 'absent')->count());
        $this->assertSame(0, AttendanceImportLine::query()->where('issue', 'no_punch')->whereNull('resolution')->count());
    }

    public function test_a_missing_out_punch_takes_the_shift_end_for_everyone_at_once(): void
    {
        $user = $this->actAs();
        $import = $this->upload();

        $response = $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'in_no_out',
            'resolution' => 'present',
            'check_out' => '18:30',
            'notes' => 'shift end applied in bulk',
        ])->assertOk();

        $this->assertSame(1, $response->json('resolved'));

        $line = AttendanceImportLine::query()->where('issue', 'in_no_out')->firstOrFail();
        $this->assertSame('present', $line->resolution->value);
        $this->assertSame('18:30', substr((string) $line->resolved_check_out, 0, 5));
        $this->assertSame('09:58', substr((string) $line->resolved_check_in, 0, 5));
        $this->assertSame('shift end applied in bulk', $line->notes);
        $this->assertSame($user->id, $line->resolved_by);
        $this->assertNotNull($line->resolved_at);
    }

    public function test_a_line_whose_employee_is_unknown_is_skipped_and_counted_never_guessed(): void
    {
        $this->actAs();
        $import = $this->upload();

        $response = $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'unknown_employee',
            'resolution' => 'absent',
        ])->assertOk();

        $this->assertSame(0, $response->json('resolved'));
        $this->assertSame(1, $response->json('skipped'));
        $this->assertSame(['ZZZ-99'], $response->json('skipped_codes'));
        // Nothing was answered, so every open day is still open.
        $this->assertSame(5, $response->json('import.open_count'));
    }

    public function test_a_bulk_answer_never_touches_a_day_that_was_already_answered(): void
    {
        $this->actAs();
        $import = $this->upload();

        $first = AttendanceImportLine::query()->where('issue', 'no_punch')->orderBy('id')->firstOrFail();
        $this->patchJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/{$first->id}", [
            'resolution' => 'on_leave',
        ])->assertOk();

        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'no_punch',
            'resolution' => 'absent',
        ])->assertOk()->assertJsonPath('resolved', 2);

        $this->assertSame('on_leave', $first->fresh()->resolution->value);
    }

    public function test_the_bulk_answer_refuses_a_kind_or_an_answer_it_does_not_know(): void
    {
        $this->actAs();
        $import = $this->upload();

        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'everything', 'resolution' => 'absent',
        ])->assertUnprocessable();

        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'no_punch', 'resolution' => 'whatever',
        ])->assertUnprocessable();

        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'in_no_out', 'resolution' => 'present', 'check_out' => 'half past six',
        ])->assertUnprocessable();
    }

    public function test_reading_needs_view_and_answering_in_bulk_needs_manage(): void
    {
        $this->actAs(['hrms.manage']);
        $import = $this->upload();

        $this->actAs(['hrms.view']);
        $this->getJson("/api/v1/hrms/attendance-imports/{$import->id}/employees")->assertOk();
        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'no_punch', 'resolution' => 'absent',
        ])->assertForbidden();
    }

    // ---- re-judging a run under the hours rule ---------------------------

    public function test_rechecking_hours_rejudges_the_days_nobody_has_answered(): void
    {
        $this->actAs();

        // A shift that clocked out at 7h50m: the report called it a half
        // day, the clock says it was the shift (DEC-20260903-005).
        $this->postJson('/api/v1/hrms/attendance-imports', [
            'period_from' => '2026-07-01',
            'period_to' => '2026-07-02',
            'source' => 'pooja',
            'employees' => [[
                'employee_code' => 'SPP-01', 'name' => 'ANAND',
                'days' => [
                    $this->day('2026-07-01', 'HD', '06:25', '14:13', 470),
                    $this->day('2026-07-02', 'HD', '10:00', '14:00', 240),
                ],
            ]],
        ])->assertCreated();
        $import = AttendanceImport::query()->latest('id')->firstOrFail();

        // Pretend the month was imported under the old label-reading rule.
        AttendanceImportLine::query()->where('attendance_import_id', $import->id)
            ->update(['resolution' => 'half_day', 'issue' => null]);

        $response = $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/recheck")->assertOk();

        $this->assertSame(1, $response->json('changed'));
        $lines = AttendanceImportLine::query()->where('attendance_import_id', $import->id)->orderBy('date')->get();
        $this->assertSame('present', $lines[0]->resolution->value);
        $this->assertSame('half_day', $lines[1]->resolution->value);
    }

    public function test_rechecking_never_overwrites_a_day_a_person_answered(): void
    {
        $this->actAs();
        $import = $this->upload();

        $line = AttendanceImportLine::query()->where('attendance_import_id', $import->id)
            ->where('issue', 'no_punch')->orderBy('id')->firstOrFail();
        $this->patchJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/{$line->id}", ['resolution' => 'on_leave'])->assertOk();

        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/recheck")->assertOk();

        $this->assertSame('on_leave', $line->fresh()->resolution->value);
    }

    public function test_an_applied_run_is_not_rejudged(): void
    {
        $this->actAs();
        $import = $this->upload();
        $import->update(['status' => 'applied', 'applied_at' => now()]);

        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/recheck")->assertUnprocessable();
    }

    public function test_an_applied_run_takes_no_further_bulk_answer(): void
    {
        $this->actAs();
        $import = $this->upload();

        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'no_punch', 'resolution' => 'absent',
        ])->assertOk();
        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'in_no_out', 'resolution' => 'present', 'check_out' => '18:30',
        ])->assertOk();

        // The unknown-employee line is what still blocks Apply; add the person.
        Employee::create(['employee_code' => 'ZZZ-99', 'name' => 'NOBODY', 'date_of_joining' => '2026-09-01']);
        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/apply")->assertOk();

        $this->postJson("/api/v1/hrms/attendance-imports/{$import->id}/lines/bulk-resolve", [
            'issue' => 'no_punch', 'resolution' => 'absent',
        ])->assertUnprocessable();
    }
}
