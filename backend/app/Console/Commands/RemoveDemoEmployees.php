<?php

namespace App\Console\Commands;

use App\Modules\HRMS\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove the seven demo employees the demo seeder created, and ONLY those —
 * and only the ones no real record depends on.
 *
 * WHY THIS EXISTS. BottleManufacturingDemoSeeder creates EMP-001..EMP-007 as
 * fixtures. It is not in the deploy's seeder list and never has been, but a
 * commented-'optional' line in DEPLOY.md's one-time server bootstrap offered
 * it, and on 2026-08-12 a read of the live instance found all seven present.
 * They are fabricated people sitting in a real factory's HRMS.
 *
 * WHY THE DETECTION IS A FULL TUPLE, NOT A CODE. These fixtures carry no
 * synthetic marker at all — no prefix, no "(DEMO)" suffix, nothing. EMP-001
 * is a perfectly plausible code for a real first employee, and the names are
 * ordinary names. Matching on the code alone would delete a real person the
 * day the factory numbers its own staff from EMP-001. So a row must match
 * employee_code AND name AND date_of_joining before it is even a candidate,
 * and anything that matches partially is reported and left alone — a partial
 * match means the assumption is already wrong.
 *
 * WHY IT SOFT-DELETES AND NEVER FORCE-DELETES. Employee uses SoftDeletes, so
 * delete() issues an UPDATE and no cascade fires. That is the safe behaviour
 * and it is deliberate: a hard delete would trigger cascadeOnDelete on
 * attendances / leave_requests / leave_balances / salary_structures
 * (destroying rows permanently, none of those models being soft-deleting) and
 * nullOnDelete on shift_production_entries.operator_id,
 * supervisor_signed_by, plant_manager_signed_by, shift_summaries.supervisor_id,
 * maintenance_work_orders.assigned_to and capas.owner — silently blanking the
 * signatory on real production records. This command never force-deletes.
 *
 * The referential scan below therefore is not there to protect the database
 * from a cascade. It is there to answer a different question: if a demo
 * person is referenced by REAL work, that reference is a fact about the
 * factory's records, and hiding the person would leave a dangling name on a
 * real batch. Those are left alone and reported.
 */
class RemoveDemoEmployees extends Command
{
    /**
     * The exact fixtures, transcribed from BottleManufacturingDemoSeeder.
     * All three fields must match for a row to be a candidate.
     *
     * @var list<array{code: string, name: string, joined: string}>
     */
    private const FIXTURES = [
        ['code' => 'EMP-001', 'name' => 'Karthik Subramaniam', 'joined' => '2019-04-01'],
        ['code' => 'EMP-002', 'name' => 'Lakshmi Narayanan', 'joined' => '2020-06-15'],
        ['code' => 'EMP-003', 'name' => 'Priya Raman', 'joined' => '2021-01-10'],
        ['code' => 'EMP-004', 'name' => 'Selvam Murugan', 'joined' => '2021-08-01'],
        ['code' => 'EMP-005', 'name' => 'Bala Krishnan', 'joined' => '2022-02-14'],
        ['code' => 'EMP-006', 'name' => 'Divya Chandran', 'joined' => '2020-11-01'],
        ['code' => 'EMP-007', 'name' => 'Meera Pillai', 'joined' => '2022-05-20'],
    ];

    /**
     * Every table that points at an employee, with the column to check.
     * Enumerated explicitly rather than derived, so a table added later
     * without updating this list shows up as an omission in review rather
     * than silently widening what this command is willing to remove.
     *
     * @var list<array{table: string, column: string, what: string}>
     */
    private const REFERENCES = [
        ['table' => 'shift_production_entries', 'column' => 'operator_id', 'what' => 'ran a batch'],
        ['table' => 'shift_production_entries', 'column' => 'supervisor_signed_by', 'what' => 'signed a batch as supervisor'],
        // NOT plant_manager_signed_by: migration 2026_07_25_000001 dropped and
        // re-added that column constrained('users'), so it holds a USER id.
        // Checking it here compared an employee id against a user id — and
        // both sequences start at 1, so it reported a demo employee as
        // "signed a batch as plant manager" on the strength of an unrelated
        // user's signature. Eleven columns reference employees, not twelve.
        ['table' => 'shift_summaries', 'column' => 'supervisor_id', 'what' => 'is a shift summary supervisor'],
        ['table' => 'maintenance_work_orders', 'column' => 'assigned_to', 'what' => 'is assigned a work order'],
        ['table' => 'capas', 'column' => 'owner', 'what' => 'owns a CAPA'],
        ['table' => 'attendances', 'column' => 'employee_id', 'what' => 'has attendance recorded'],
        ['table' => 'leave_requests', 'column' => 'employee_id', 'what' => 'has a leave request'],
        ['table' => 'leave_balances', 'column' => 'employee_id', 'what' => 'has a leave balance'],
        ['table' => 'salary_structures', 'column' => 'employee_id', 'what' => 'has a salary structure'],
        ['table' => 'payslips', 'column' => 'employee_id', 'what' => 'has a payslip'],
        ['table' => 'employees', 'column' => 'manager_id', 'what' => 'manages another employee'],
        ['table' => 'attendance_import_lines', 'column' => 'employee_id', 'what' => 'is on a punch-report import'],
    ];

    protected $signature = 'hrms:remove-demo-employees
        {--write : Actually write (default is a dry run)}';

    protected $description = 'Soft-delete the seven demo-seeder employees, skipping any that real records reference';

    public function handle(): int
    {
        $removable = [];
        $referenced = [];
        $partial = [];
        $absent = [];
        $alreadyRemoved = [];
        $candidates = [];

        foreach (self::FIXTURES as $fixture) {
            // withTrashed so a fixture this command already removed reports as
            // "already removed" rather than "not present" — those mean very
            // different things when someone is checking whether the cleanup
            // ran, and a soft-deleted row is still a row.
            $byCode = Employee::withTrashed()->where('employee_code', $fixture['code'])->first();

            if ($byCode === null) {
                $absent[] = $fixture['code'];

                continue;
            }

            if ($byCode->trashed()) {
                $alreadyRemoved[] = $fixture['code'];

                continue;
            }

            // A partial match is the signal that this database is not what
            // the fixture list assumes. Never resolve it by guessing.
            $joined = $byCode->date_of_joining instanceof \DateTimeInterface
                ? $byCode->date_of_joining->format('Y-m-d')
                : (string) $byCode->date_of_joining;

            if (trim((string) $byCode->name) !== $fixture['name'] || $joined !== $fixture['joined']) {
                $partial[] = sprintf(
                    '%s — code matches but name/joining does not (found "%s" joined %s, fixture is "%s" joined %s)',
                    $fixture['code'], $byCode->name, $joined, $fixture['name'], $fixture['joined'],
                );

                continue;
            }

            $candidates[] = ['employee' => $byCode, 'fixture' => $fixture];
        }

        // TWO PASSES, and the second one needs the first to be complete.
        //
        // The demo seeder wires the fixtures to EACH OTHER: EMP-002 and -003
        // report to EMP-001, EMP-004 and -005 to EMP-002. Checked one at a
        // time, EMP-001 is "referenced by" EMP-002 — so it was reported as
        // referenced by real records and left in the live HRMS forever, on
        // the strength of a reference the same demo seeder created. Worse,
        // soft-deleting a subordinate does not clear its manager_id, so the
        // count never fell and a second run behaved identically to the first.
        //
        // A reference from another row that is ITSELF being removed is not a
        // reason to keep anything. Only references from outside the candidate
        // set count.
        $candidateIds = array_map(fn (array $c) => (int) $c['employee']->id, $candidates);

        foreach ($candidates as $candidate) {
            $refs = $this->referencesTo((int) $candidate['employee']->id, $candidateIds);

            if ($refs !== []) {
                $referenced[] = sprintf(
                    '%s (%s) — %s',
                    $candidate['fixture']['code'],
                    $candidate['fixture']['name'],
                    implode('; ', $refs),
                );

                continue;
            }

            $removable[] = $candidate['employee'];
        }

        $this->info('Demo employees — plan');
        $this->table(['count', 'value'], [
            ['fixtures looked for', count(self::FIXTURES)],
            ['not present (nothing to do)', count($absent)],
            ['already removed by a previous run', count($alreadyRemoved)],
            [$this->option('write') ? 'removed' : 'would be removed', count($removable)],
            ['LEFT ALONE — referenced by real records', count($referenced)],
            ['LEFT ALONE — partial match, assumption is wrong', count($partial)],
        ]);

        foreach ($removable as $employee) {
            $this->line(sprintf('  %s: %s (%s)', $this->option('write') ? 'removed' : 'would remove', $employee->employee_code, $employee->name));
        }
        foreach ($referenced as $line) {
            $this->warn('  LEFT ALONE, referenced: '.$line);
        }
        foreach ($partial as $line) {
            $this->error('  LEFT ALONE, partial match: '.$line);
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->line('DRY RUN — nothing written. Re-run with --write after reading the plan above.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($removable) {
            foreach ($removable as $employee) {
                // Soft delete only. See the class docblock: a force delete
                // would cascade real rows away and blank signatories on real
                // production records.
                $employee->delete();
            }
        });

        $this->newLine();
        $this->info(sprintf('REMOVED %d demo employee(s), soft-deleted.', count($removable)));

        if ($referenced !== [] || $partial !== []) {
            $this->warn('Some were left alone — see above. They need a person, not a re-run.');
        }

        return self::SUCCESS;
    }

    /**
     * Which real records point at this employee. Tables that do not exist on
     * this instance are skipped rather than fataling — the command must be
     * runnable on an instance that never adopted payroll or maintenance.
     *
     * @return list<string>
     */
    private function referencesTo(int $employeeId, array $candidateIds = []): array
    {
        $found = [];
        $schema = DB::getSchemaBuilder();

        foreach (self::REFERENCES as $ref) {
            if (! $schema->hasTable($ref['table']) || ! $schema->hasColumn($ref['table'], $ref['column'])) {
                continue;
            }

            $query = DB::table($ref['table'])->where($ref['column'], $employeeId);

            // A row somebody already deleted is not a live reference. Without
            // this, removing one fixture permanently pinned another.
            if ($schema->hasColumn($ref['table'], 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            // A reference from a row that is itself on the removal list is not
            // a reason to keep anything — that is the demo seeder pointing at
            // itself, not the factory's real work.
            if ($ref['table'] === 'employees' && $candidateIds !== []) {
                $query->whereNotIn('id', $candidateIds);
            }

            $count = $query->count();

            if ($count > 0) {
                $found[] = sprintf('%s (%d in %s.%s)', $ref['what'], $count, $ref['table'], $ref['column']);
            }
        }

        return $found;
    }
}
