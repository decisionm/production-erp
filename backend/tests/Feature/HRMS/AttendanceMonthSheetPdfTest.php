<?php

namespace Tests\Feature\HRMS;

use App\Models\User;
use App\Modules\HRMS\Models\Attendance;
use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ONE PERSON'S MONTH, ON PAPER.
 *
 * The floor runs on paper. A supervisor prints this, hands it over, and a
 * correction comes back the way corrections already come back — written on
 * the sheet. So the sheet carries what somebody would need to check their
 * own month (every day, its in and out, what it counted as) and room to
 * write on, and it is a PDF because it has to survive a printer.
 */
class AttendanceMonthSheetPdfTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anand = Employee::create([
            'employee_code' => 'SPP-01', 'name' => 'Anand', 'date_of_joining' => '2026-01-01',
            'department' => 'Production Department', 'designation' => 'Packing Staff',
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

    private function seedDays(): void
    {
        Attendance::create([
            'employee_id' => $this->anand->id, 'date' => '2026-09-01', 'status' => 'present',
            'check_in' => '2026-09-01 00:30:00', 'check_out' => '2026-09-01 08:30:00',
        ]);
        Attendance::create(['employee_id' => $this->anand->id, 'date' => '2026-09-02', 'status' => 'absent']);
        Attendance::create(['employee_id' => $this->anand->id, 'date' => '2026-09-03', 'status' => 'half_day']);
    }

    private function url(string $from = '2026-09-01', string $to = '2026-09-30'): string
    {
        return "/api/v1/hrms/attendance/person/sheet?employee_id={$this->anand->id}&from={$from}&to={$to}";
    }

    public function test_the_sheet_comes_back_as_a_pdf_named_for_the_person_and_the_period(): void
    {
        $this->actAs(['hrms.view']);
        $this->seedDays();

        $response = $this->get($this->url())->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'attendance-SPP-01-2026-09-01-to-2026-09-30.pdf',
            (string) $response->headers->get('content-disposition'),
        );
        // A real PDF, not an error page with the wrong header on it.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_month_with_nothing_in_it_still_prints(): void
    {
        $this->actAs(['hrms.view']);

        // No attendance at all — the sheet is what a supervisor hands out to
        // ASK about a month, so an empty one must still print.
        $response = $this->get($this->url())->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_sheet_refuses_a_stranger_and_a_login_without_hrms(): void
    {
        $this->get($this->url())->assertUnauthorized();

        $this->actAs(['production.view']);
        $this->get($this->url())->assertForbidden();
    }

    public function test_the_sheet_wants_a_real_employee_and_a_sane_range(): void
    {
        $this->actAs(['hrms.view']);

        $this->getJson('/api/v1/hrms/attendance/person/sheet?from=2026-09-01&to=2026-09-30')->assertUnprocessable();
        $this->getJson('/api/v1/hrms/attendance/person/sheet?employee_id=99999&from=2026-09-01&to=2026-09-30')
            ->assertUnprocessable();
        $this->getJson($this->url('2026-09-30', '2026-09-01'))->assertUnprocessable();
    }

    /**
     * The sheet's own content, checked on the HTML the PDF is rendered
     * from — a PDF's bytes are not a thing to assert words against.
     */
    public function test_the_sheet_carries_the_person_the_period_the_days_and_the_totals(): void
    {
        $this->actAs(['hrms.view']);
        $this->seedDays();

        $html = view('pdf.attendance-month', app(AttendanceService::class)
            ->monthSheet($this->anand, '2026-09-01', '2026-09-30'))->render();

        // The FACTORY's name heads it, not the application's — a sheet
        // handed to somebody on the floor headed "Production ERP" would be
        // the software's name where the company's belongs.
        $this->assertStringContainsString(config('company.name'), $html);
        $this->assertStringNotContainsString('Production ERP', $html);

        // Who it is about.
        $this->assertStringContainsString('SPP-01', $html);
        $this->assertStringContainsString('Anand', $html);
        $this->assertStringContainsString('Production Department', $html);
        $this->assertStringContainsString('Packing Staff', $html);

        // What period, in words a person reads rather than as ISO dates.
        $this->assertStringContainsString('1 Sep 2026', $html);
        $this->assertStringContainsString('30 Sep 2026', $html);

        // Every day of the range, not merely the days that were recorded —
        // a gap is what somebody queries, so it has to be visible.
        $this->assertStringContainsString('Tue 1', $html);
        $this->assertStringContainsString('Wed 30', $html);
        $this->assertSame(30, substr_count($html, '<tr class="day'));

        // What each day counted as, and the clock behind it.
        $this->assertStringContainsString('Present', $html);
        $this->assertStringContainsString('Absent', $html);
        $this->assertStringContainsString('Half Day', $html);
        $this->assertStringContainsString('06:00', $html, 'the IST wall clock, not the stored UTC');
        $this->assertStringContainsString('14:00', $html);

        // The totals, so nobody counts rows.
        $this->assertStringContainsString('Days recorded', $html);

        // And room to disagree with it.
        $this->assertStringContainsString('Corrections', $html);
        $this->assertStringContainsString('Signature', $html);
    }

    public function test_a_day_nobody_recorded_is_blank_rather_than_absent(): void
    {
        $this->actAs(['hrms.view']);
        $this->seedDays();

        $html = view('pdf.attendance-month', app(AttendanceService::class)
            ->monthSheet($this->anand, '2026-09-01', '2026-09-05'))->render();

        // Three days were recorded; the 4th and 5th were not, and calling
        // them absent on a sheet somebody is paid against would be a lie.
        $this->assertSame(5, substr_count($html, '<tr class="day'));
        $this->assertStringContainsString('not recorded', $html);
    }
}
