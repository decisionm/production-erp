<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\Core\Exports\CsvStreamer;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * POST /exports/attendance_month_sheet — one row per employee of one
 * punch-report import, produced from the IMPORT LINES: the counts, the
 * hours, and one column per day of the period with the resolved code
 * (P / H / A / L / W), blank while a line is still open. Gated like the
 * screen: hrms.view may run it, nobody else may see it.
 */
class AttendanceMonthSheetExportTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anand = Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'ANAND', 'date_of_joining' => '2026-09-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
        ]);
        Employee::create([
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

    private function upload(): int
    {
        $day = fn (string $date, string $status, ?string $in, ?string $out, array $extra = []) => ['date' => $date, 'status' => $status, 'first_in' => $in, 'last_out' => $out, ...$extra];

        return (int) $this->postJson('/api/v1/hrms/attendance-imports', [
            'period_from' => '2026-07-01',
            'period_to' => '2026-07-04',
            'source' => 'pooja',
            'employees' => [
                [
                    'employee_code' => 'SPP-01', 'name' => 'ANAND',
                    'days' => [
                        $day('2026-07-01', 'FD', '10:10', '20:20', ['ot_minutes' => 129, 'late_minutes' => 40, 'worked_minutes' => 609]),
                        $day('2026-07-02', 'HD', '10:00', '14:00', ['worked_minutes' => 240, 'early_minutes' => 15]),
                        $day('2026-07-03', 'FD', '09:58', null),
                        $day('2026-07-04', 'Week Off', null, null),
                    ],
                ],
                [
                    'employee_code' => 'SPP-02', 'name' => 'BALA',
                    'days' => [$day('2026-07-02', 'FD', '09:00', '18:00', ['worked_minutes' => 540, 'ot_minutes' => 30])],
                ],
            ],
        ])->assertCreated()->json('data.id');
    }

    /** @return array{headers: list<string>, rows: list<array<string, string>>} */
    private function csv(TestResponse $response): array
    {
        $raw = $response->streamedContent();
        $this->assertStringStartsWith(CsvStreamer::BOM, $raw);
        $lines = explode("\r\n", rtrim(substr($raw, strlen(CsvStreamer::BOM)), "\r\n"));
        $headers = str_getcsv(array_shift($lines), ',', '"', '');
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = array_combine($headers, str_getcsv($line, ',', '"', ''));
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    public function test_the_sheet_is_one_row_per_employee_with_a_column_per_day_of_the_period(): void
    {
        $this->actAs(['hrms.manage']);
        $id = $this->upload();

        $csv = $this->csv($this->postJson('/api/v1/exports/attendance_month_sheet', ['attendance_import_id' => $id])->assertOk());

        $this->assertSame(
            ['employee_code', 'name', 'department', 'designation', 'days_in_period', 'present', 'half_day', 'absent', 'on_leave', 'week_off', 'worked_hours', 'ot_hours', 'late_minutes', 'early_out_minutes', '2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'],
            $csv['headers'],
        );
        $this->assertCount(2, $csv['rows']);

        $anand = $csv['rows'][0];
        $this->assertSame('SPP-01', $anand['employee_code']);
        $this->assertSame('ANAND', $anand['name']);
        $this->assertSame('Production Department', $anand['department']);
        $this->assertSame('Packing Staff', $anand['designation']);
        $this->assertSame('4', $anand['days_in_period']);
        $this->assertSame('1', $anand['present']);
        $this->assertSame('1', $anand['half_day']);
        $this->assertSame('0', $anand['absent']);
        $this->assertSame('1', $anand['week_off']);
        $this->assertSame('14.15', $anand['worked_hours'], '849 minutes, exact to two places');
        $this->assertSame('2.15', $anand['ot_hours']);
        $this->assertSame('40', $anand['late_minutes']);
        $this->assertSame('15', $anand['early_out_minutes']);
        $this->assertSame(['P', 'H', '', 'W'], [$anand['2026-07-01'], $anand['2026-07-02'], $anand['2026-07-03'], $anand['2026-07-04']], 'the open line is blank');

        $bala = $csv['rows'][1];
        $this->assertSame('SPP-02', $bala['employee_code']);
        $this->assertSame(['', 'P', '', ''], [$bala['2026-07-01'], $bala['2026-07-02'], $bala['2026-07-03'], $bala['2026-07-04']], 'a day not in the file is blank');
        $this->assertSame('9.00', $bala['worked_hours']);
        $this->assertSame('0.50', $bala['ot_hours']);
    }

    public function test_a_correction_shows_in_the_sheet(): void
    {
        $this->actAs(['hrms.manage']);
        $id = $this->upload();
        $line = $this->getJson("/api/v1/hrms/attendance-imports/{$id}/lines?issue=in_no_out")->json('data.0.id');
        $this->patchJson("/api/v1/hrms/attendance-imports/{$id}/lines/{$line}", ['resolution' => 'on_leave'])->assertOk();

        $csv = $this->csv($this->postJson('/api/v1/exports/attendance_month_sheet', ['attendance_import_id' => $id])->assertOk());
        $this->assertSame('L', $csv['rows'][0]['2026-07-03']);
        $this->assertSame('1', $csv['rows'][0]['on_leave']);
    }

    public function test_gating_and_the_filter(): void
    {
        $this->actAs(['hrms.manage']);
        $id = $this->upload();

        $this->actAs(['hrms.view']);
        $catalogue = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->keyBy('key');
        $this->assertTrue($catalogue->has('attendance_month_sheet'));
        $this->assertSame('hrms', $catalogue['attendance_month_sheet']['module']);
        $this->assertSame('attendance_import_id', $catalogue['attendance_month_sheet']['filters'][0]['name']);
        $this->postJson('/api/v1/exports/attendance_month_sheet', ['attendance_import_id' => $id])->assertOk();
        $this->postJson('/api/v1/exports/attendance_month_sheet', [])->assertUnprocessable()->assertJsonValidationErrors(['attendance_import_id']);
        $this->postJson('/api/v1/exports/attendance_month_sheet', ['attendance_import_id' => 999])->assertUnprocessable()->assertJsonValidationErrors(['attendance_import_id']);

        $this->actAs(['sales.view']);
        $this->assertFalse(collect($this->getJson('/api/v1/exports')->json('data'))->keyBy('key')->has('attendance_month_sheet'));
        $this->postJson('/api/v1/exports/attendance_month_sheet', ['attendance_import_id' => $id])->assertForbidden();
    }
}
