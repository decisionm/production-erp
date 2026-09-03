<?php

namespace Tests\Feature\HRMS;

use App\Console\Commands\ImportEmployeesJson;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `hrms:import-employees` — the September employee load (Track 1 of the
 * 03-Sep design). Dry-run by default, idempotent on employee_code, updates
 * only the four columns the file carries and only when they differ, never
 * deletes, and never touches a row the file does not name (the seven demo
 * employees included — DEC-20260812-001 says rename or archive, never
 * delete, and this command does neither).
 *
 * The committed data file is what goes to live, so its counts are pinned
 * here too: 65 people, 2 inactive, 6 TMP codes, no duplicate code.
 */
class ImportEmployeesJsonTest extends TestCase
{
    use RefreshDatabase;

    private const DATA_FILE = 'database/data/employees-2026-09.json';

    private function fixture(array $people): string
    {
        $path = tempnam(sys_get_temp_dir(), 'employees-');
        file_put_contents($path, json_encode($people));

        return $path;
    }

    /** @return array<string, mixed> */
    private function person(string $code, string $name, array $overrides = []): array
    {
        return [
            'serial' => 1,
            'list_name' => $name,
            'employee_code' => $code,
            'name' => $name,
            'department' => 'Production Department',
            'designation' => 'Packing Staff',
            'status' => 'active',
            'note' => null,
            ...$overrides,
        ];
    }

    public function test_a_dry_run_reports_the_plan_and_writes_nothing(): void
    {
        $path = $this->fixture([$this->person('SPP-01', 'MAYAVATHI'), $this->person('SPP-02', 'ETTIYAMMAL')]);

        $this->artisan('hrms:import-employees', ['path' => $path])
            ->expectsOutputToContain('DRY RUN — nothing written')
            ->expectsTable(['count', 'value'], [
                ['people in the file', 2],
                ['would be created', 2],
                ['would be updated', 0],
                ['unchanged', 0],
            ])
            ->assertSuccessful();

        $this->assertSame(0, Employee::count());
    }

    public function test_the_committed_data_file_creates_sixty_five_rows_and_is_a_no_op_on_the_second_run(): void
    {
        $path = base_path(self::DATA_FILE);
        $this->assertFileExists($path);

        $this->artisan('hrms:import-employees', ['path' => $path, '--write' => true])
            ->expectsOutputToContain('IMPORTED')
            ->assertSuccessful();

        $this->assertSame(65, Employee::count());
        $this->assertSame(2, Employee::where('status', 'inactive')->count());
        $this->assertSame(6, Employee::where('employee_code', 'like', 'TMP-%')->count());
        $this->assertSame(57, Employee::where('employee_code', 'like', 'SPP-%')->where('status', 'active')->count());

        // The joining date nobody supplied: the placeholder, for everyone.
        $this->assertSame(65, Employee::whereDate('date_of_joining', ImportEmployeesJson::PLACEHOLDER_JOINING_DATE)->count());

        // App wins over paper for B. Suresh's designation. Names are stored
        // as people write them, not as the punch report shouts them.
        $suresh = Employee::where('employee_code', 'SPP-105')->firstOrFail();
        $this->assertSame('Suresh', $suresh->name);
        $this->assertSame('Production Supervisor', $suresh->designation);

        $velvizhi = Employee::where('employee_code', 'SPP-05')->firstOrFail();
        $this->assertSame('inactive', $velvizhi->status->value);

        // Nobody is left in capitals, and an initial keeps its letter.
        $this->assertSame([], Employee::query()->get(['employee_code', 'name'])
            ->filter(fn ($e) => $e->name === mb_strtoupper($e->name) && mb_strlen($e->name) > 2)
            ->pluck('name')->all());
        $this->assertSame('K. Soniya', Employee::where('employee_code', 'TMP-58')->value('name'));
        $this->assertSame('Balaji V', Employee::where('employee_code', 'SPP-101')->value('name'));

        $this->artisan('hrms:import-employees', ['path' => $path, '--write' => true])
            ->expectsTable(['count', 'value'], [
                ['people in the file', 65],
                ['created', 0],
                ['updated', 0],
                ['unchanged', 65],
            ])
            ->assertSuccessful();

        $this->assertSame(65, Employee::count());
    }

    public function test_an_existing_row_is_updated_only_where_the_file_differs_and_only_in_the_four_columns(): void
    {
        $existing = Employee::create([
            'employee_code' => 'SPP-20',
            'name' => 'S. Vani',
            'email' => 'vani@example.test',
            'phone' => '9000000000',
            'date_of_joining' => '2021-04-01',
            'department' => 'Accounts Department',
            'designation' => 'Junior Accountant',
            'status' => 'active',
        ]);
        $path = $this->fixture([
            $this->person('SPP-20', 'VANI', ['department' => 'Accounts Department', 'designation' => 'Accountant']),
        ]);

        $this->artisan('hrms:import-employees', ['path' => $path, '--write' => true])
            ->expectsTable(['count', 'value'], [
                ['people in the file', 1],
                ['created', 0],
                ['updated', 1],
                ['unchanged', 0],
            ])
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame('VANI', $existing->name);
        $this->assertSame('Accountant', $existing->designation);
        // Everything the file does not carry is left exactly as it was.
        $this->assertSame('vani@example.test', $existing->email);
        $this->assertSame('9000000000', $existing->phone);
        $this->assertSame('2021-04-01', $existing->date_of_joining->toDateString());
    }

    public function test_rows_the_file_does_not_name_are_never_touched(): void
    {
        $demo = Employee::create([
            'employee_code' => 'EMP-001', 'name' => 'Karthik Subramaniam', 'date_of_joining' => '2019-04-01',
        ]);
        $path = $this->fixture([$this->person('SPP-01', 'MAYAVATHI')]);

        $this->artisan('hrms:import-employees', ['path' => $path, '--write' => true])->assertSuccessful();

        $this->assertSame(2, Employee::count());
        $this->assertSame('Karthik Subramaniam', $demo->fresh()->name);
        $this->assertNull($demo->fresh()->deleted_at);
    }

    public function test_a_duplicate_code_inside_the_file_is_refused_before_anything_is_written(): void
    {
        $path = $this->fixture([$this->person('SPP-01', 'MAYAVATHI'), $this->person('SPP-01', 'SOMEONE ELSE')]);

        $this->artisan('hrms:import-employees', ['path' => $path, '--write' => true])
            ->expectsOutputToContain('SPP-01')
            ->assertFailed();

        $this->assertSame(0, Employee::count());
    }

    public function test_a_missing_or_malformed_file_is_refused(): void
    {
        $this->artisan('hrms:import-employees', ['path' => '/no/such/file.json'])->assertFailed();

        $path = $this->fixture([['employee_code' => '', 'name' => 'NOBODY']]);
        $this->artisan('hrms:import-employees', ['path' => $path])->assertFailed();
    }
}
