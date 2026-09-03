<?php

namespace App\Console\Commands;

use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Services\EmployeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load the employee master from a JSON file — the September 2026 load of
 * the owner's two paper lists, cross-matched to the Pooja punch app
 * (database/data/employees-2026-09.json; Track 1 of the 03-Sep design).
 *
 * One object per person: serial, list_name, employee_code, name,
 * department, designation, status, note. The note is for the person
 * running this — it is printed, not stored: `employees` has no notes
 * column and the file is the record of why a row looks the way it does.
 *
 * DRY RUN BY DEFAULT, exactly like production:import-product-master.
 * Idempotent: matched on employee_code; an existing row is updated only in
 * name / department / designation / status and only where the value
 * differs; nothing is deleted, and a row the file does not name — the
 * seven demo employees included (DEC-20260812-001) — is never touched.
 *
 * `date_of_joining` is NOT NULL and nobody supplied one, so every CREATED
 * row gets PLACEHOLDER_JOINING_DATE for HR to correct on the Employees
 * screen; an existing row's date is never overwritten.
 *
 * Goes through EmployeeService, so the audit trail (RecordsConfigurationAudit)
 * records every create and edit with a null causer, as any console write does.
 */
class ImportEmployeesJson extends Command
{
    public const PLACEHOLDER_JOINING_DATE = '2026-09-01';

    private const UPDATABLE = ['name', 'department', 'designation', 'status'];

    protected $signature = 'hrms:import-employees
        {path : JSON file, one object per person}
        {--write : Actually write (default is a dry run)}';

    protected $description = 'Create or update employees from a JSON file (dry run unless --write)';

    public function handle(EmployeeService $employees): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $people = json_decode((string) file_get_contents($path), true);
        if (! is_array($people) || $people === []) {
            $this->error("Not a non-empty JSON array: {$path}");

            return self::FAILURE;
        }

        if (($problem = $this->validate($people)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $plan = ['create' => [], 'update' => [], 'unchanged' => []];

        $existing = Employee::withTrashed()
            ->whereIn('employee_code', array_column($people, 'employee_code'))
            ->get()
            ->keyBy('employee_code');

        foreach ($people as $person) {
            $row = $existing->get($person['employee_code']);
            if ($row === null) {
                $plan['create'][] = $person;

                continue;
            }

            $changes = [];
            foreach (self::UPDATABLE as $column) {
                $current = $row->{$column} instanceof \BackedEnum ? $row->{$column}->value : $row->{$column};
                if ((string) $current !== (string) $person[$column]) {
                    $changes[$column] = $person[$column];
                }
            }

            if ($changes === []) {
                $plan['unchanged'][] = $person;
            } else {
                $plan['update'][] = ['person' => $person, 'row' => $row, 'changes' => $changes];
            }
        }

        if ($write) {
            DB::transaction(function () use ($employees, $plan) {
                foreach ($plan['create'] as $person) {
                    $employees->create([
                        'employee_code' => $person['employee_code'],
                        'name' => $person['name'],
                        'department' => $person['department'],
                        'designation' => $person['designation'],
                        'status' => $person['status'],
                        'date_of_joining' => self::PLACEHOLDER_JOINING_DATE,
                    ]);
                }
                foreach ($plan['update'] as $update) {
                    $employees->update($update['row'], $update['changes']);
                }
            });
        }

        $this->info($write ? 'IMPORTED' : 'DRY RUN — nothing written');
        $this->newLine();
        $this->table(['count', 'value'], [
            ['people in the file', count($people)],
            [$write ? 'created' : 'would be created', count($plan['create'])],
            [$write ? 'updated' : 'would be updated', count($plan['update'])],
            ['unchanged', count($plan['unchanged'])],
        ]);

        foreach ($plan['update'] as $update) {
            $this->line(sprintf(
                '  %s %s: %s',
                $write ? 'updated' : 'would update',
                $update['person']['employee_code'],
                implode(', ', array_map(
                    fn (string $column) => "{$column} → {$update['changes'][$column]}",
                    array_keys($update['changes']),
                )),
            ));
        }

        $notes = array_filter($people, fn (array $person) => ! empty($person['note']));
        if ($notes !== []) {
            $this->newLine();
            $this->warn('Notes from the file (for HR — not stored):');
            foreach ($notes as $person) {
                $this->line("  · {$person['employee_code']} {$person['name']} — {$person['note']}");
            }
        }

        if (! $write) {
            $this->newLine();
            $this->line('Re-run with --write after reading the plan above.');
        }

        return self::SUCCESS;
    }

    /**
     * The file's own consistency, checked before a single row is touched:
     * every person has a code, a name and a known status, and no code
     * appears twice — a duplicate would make "matched on employee_code"
     * ambiguous, so it is refused rather than resolved by order.
     *
     * @param  list<array<string, mixed>>  $people
     */
    private function validate(array $people): ?string
    {
        $seen = [];
        foreach ($people as $index => $person) {
            $code = trim((string) ($person['employee_code'] ?? ''));
            $name = trim((string) ($person['name'] ?? ''));
            $status = (string) ($person['status'] ?? '');

            if ($code === '' || $name === '') {
                return "Entry {$index} has no employee_code or no name.";
            }
            if (! in_array($status, ['active', 'inactive', 'terminated'], true)) {
                return "Entry {$index} ({$code}) has an unknown status \"{$status}\".";
            }
            if (isset($seen[$code])) {
                return "Duplicate employee_code {$code} in the file (entries {$seen[$code]} and {$index}).";
            }
            $seen[$code] = $index;
        }

        return null;
    }
}
