<?php

namespace Tests\Feature\Configuration;

use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Support\Configuration\ConfigurationInUseException;
use App\Support\Configuration\ConfigurationLifecycle;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\DependencyReport;
use App\Support\Configuration\SchemaCascades;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE SCHEMA IS THE BACKSTOP — the P1 all three Phase 7.6 reviewers found.
 *
 * ConfigurationLifecycle::delete() destroys a row when the module's
 * hand-written DependencyCheck list comes back clear. A reviewer proved
 * what that costs when the list is wrong: `new ConfigurationLifecycle(
 * label: 'employee', checks: [])` against an employee who HAD an
 * attendance row deleted green, and `ON DELETE CASCADE` took the
 * attendance with it — no database backstop, no refusal, no trace.
 *
 * The declaration is a human list, so one day it WILL be incomplete. The
 * schema the delete runs against will not be. So every report now asks the
 * schema which foreign keys cascade into this table and refuses, naming the
 * table, for any cascading child the checks do not account for.
 *
 * BOTH DRIVERS. This suite runs on sqlite locally and on MySQL 8 in CI's
 * `app-mysql` leg, and the two answer the question completely differently
 * (sqlite has no cross-table FK catalogue at all, so every table is asked
 * what it points at; MySQL is one information_schema query). Every test here
 * is driver-agnostic and therefore runs, unguarded, on both legs — the
 * proof is that the SAME assertions hold, not that a sqlite-shaped
 * expectation was skipped on MySQL.
 */
class ConfigurationCascadeBackstopTest extends TestCase
{
    use RefreshDatabase;

    /** Everything `employees` cascades to, per the migrations. */
    private const EMPLOYEE_CASCADES = ['attendances', 'leave_balances', 'leave_requests', 'salary_structures'];

    protected function setUp(): void
    {
        parent::setUp();

        SchemaCascades::flush();
    }

    private function employee(string $code = 'EMP-1'): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'name' => 'Operator '.$code,
            'date_of_joining' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    private function attendanceFor(Employee $employee): int
    {
        return DB::table('attendances')->insertGetId([
            'employee_id' => $employee->id,
            'date' => '2026-08-10',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The reviewer's lifecycle, verbatim: a label, and nothing declared. */
    private function undeclaredLifecycle(): ConfigurationLifecycle
    {
        return new ConfigurationLifecycle(
            label: 'employee',
            checks: [],
            activeColumn: null,
            canHardDelete: fn (?Authenticatable $user): bool => true,
        );
    }

    /** The same lifecycle with every cascading child of `employees` declared. */
    private function declaredLifecycle(): ConfigurationLifecycle
    {
        return new ConfigurationLifecycle(
            label: 'employee',
            checks: array_map(
                fn (string $table): DependencyCheck => DependencyCheck::table($table, 'employee_id')
                    ->label(str_replace('_', ' ', rtrim($table, 's')))
                    ->cascadeSide(),
                self::EMPLOYEE_CASCADES,
            ),
            activeColumn: null,
            canHardDelete: fn (?Authenticatable $user): bool => true,
        );
    }

    public function test_the_reviewers_scenario_is_refused_and_the_attendance_row_survives(): void
    {
        $employee = $this->employee();
        $attendanceId = $this->attendanceFor($employee);

        try {
            $this->undeclaredLifecycle()->delete($employee);
            $this->fail('An undeclared cascading child must never be deleted past.');
        } catch (ConfigurationInUseException) {
            // expected
        }

        $this->assertNotNull(Employee::withTrashed()->find($employee->id), 'the employee survives');
        $this->assertSame(1, DB::table('attendances')->where('id', $attendanceId)->count(), 'and so does the attendance the database would have cascaded');
    }

    public function test_the_refusal_names_the_undeclared_table(): void
    {
        $employee = $this->employee();

        try {
            $this->undeclaredLifecycle()->delete($employee);
            $this->fail('Expected a refusal.');
        } catch (ConfigurationInUseException $e) {
            $this->assertStringContainsString(
                'the schema cascades attendances.employee_id and no check declares it',
                $e->getMessage(),
            );

            $gaps = $e->payload()['cascade_gaps'];
            $this->assertSame(self::EMPLOYEE_CASCADES, array_column($gaps, 'table'));
            $this->assertSame('undeclared', $gaps[0]['reason']);

            // Nothing is invented: there is no count for a gap, because the
            // gap is that nobody counted.
            $this->assertSame([], $e->payload()['blocking']);
        }
    }

    /**
     * The refusal does NOT depend on a child existing. The employee here has
     * no attendance at all — it is the DECLARATION that is unproven, and a
     * mechanism that only refused when it happened to spot a row would be
     * back to trusting the list it cannot trust.
     */
    public function test_an_undeclared_cascade_refuses_even_with_no_child_row(): void
    {
        $employee = $this->employee();

        $this->assertSame(0, DB::table('attendances')->where('employee_id', $employee->id)->count());

        $this->expectException(ConfigurationInUseException::class);
        $this->undeclaredLifecycle()->delete($employee);
    }

    public function test_a_complete_declaration_still_deletes_a_genuinely_unused_record(): void
    {
        $employee = $this->employee('EMP-UNUSED');
        $code = $employee->employee_code;

        $this->assertTrue($this->declaredLifecycle()->report($employee)->isClear());

        $this->declaredLifecycle()->delete($employee);

        $this->assertNull(Employee::withTrashed()->find($employee->id), 'a proven-unused record is really gone');
        $this->assertNotNull(Employee::create([
            'employee_code' => $code,
            'name' => 'Somebody else at the freed code',
            'date_of_joining' => '2026-02-01',
            'status' => 'active',
        ])->id);
    }

    public function test_a_complete_declaration_still_refuses_a_record_that_is_used(): void
    {
        $employee = $this->employee();
        $attendanceId = $this->attendanceFor($employee);

        try {
            $this->declaredLifecycle()->delete($employee);
            $this->fail('Expected the declared check to refuse.');
        } catch (ConfigurationInUseException $e) {
            $this->assertSame(
                [['code' => 'attendances', 'label' => 'attendance', 'count' => 1]],
                $e->payload()['blocking'],
            );
            $this->assertSame([], $e->payload()['cascade_gaps'], 'a declared cascade is not also a gap');
        }

        $this->assertSame(1, DB::table('attendances')->where('id', $attendanceId)->count());
    }

    public function test_the_advisory_answer_and_the_enforced_one_cannot_disagree(): void
    {
        $employee = $this->employee();

        // The backstop lives in the report, so the button is never offered
        // for a delete the mechanism would refuse.
        $this->assertFalse($this->undeclaredLifecycle()->report($employee)->isClear());
        $this->assertFalse($this->undeclaredLifecycle()->abilities($employee)['delete']);
        $this->assertTrue($this->declaredLifecycle()->abilities($employee)['delete']);
    }

    /**
     * A check that names the cascading table but skips its soft-deleted rows
     * does not cover the cascade: a trashed child is still a physical row and
     * `ON DELETE CASCADE` destroys it just the same.
     */
    public function test_a_declaration_that_ignores_archived_children_does_not_cover_the_cascade(): void
    {
        $item = Item::create(['sku' => 'ITM-CASCADE', 'name' => 'Cascade item', 'uom' => 'Nos', 'is_active' => true]);

        $dosings = function (bool $countTrashed): DependencyCheck {
            $check = DependencyCheck::table('masterbatch_dosings', ['masterbatch_item_id', 'product_item_id'])
                ->label('masterbatch dosing');

            return $countTrashed ? $check->cascadeSide() : $check;
        };

        $checks = fn (bool $countTrashed): array => [
            $dosings($countTrashed),
            DependencyCheck::table('stock_balances', 'item_id')->label('stock balance')->cascadeSide(),
            DependencyCheck::table('packing_material_mappings', 'item_id')->label('packing mapping')->cascadeSide(),
            DependencyCheck::table('batch_resin_allocations', 'item_id')->label('resin allocation')->cascadeSide(),
            DependencyCheck::table('resin_pool_balances', 'item_id')->label('resin pool balance')->cascadeSide(),
            DependencyCheck::table('production_configurations', 'item_id')->label('production configuration')->cascadeSide(),
        ];

        $ignoresTrashed = new ConfigurationLifecycle(
            label: 'item',
            checks: $checks(false),
            canHardDelete: fn (?Authenticatable $user): bool => true,
        );
        $complete = new ConfigurationLifecycle(
            label: 'item',
            checks: $checks(true),
            canHardDelete: fn (?Authenticatable $user): bool => true,
        );

        $this->assertSame(
            [
                // Sorted by column, so the refusal reads identically on
                // sqlite and on MySQL.
                [
                    'table' => 'masterbatch_dosings',
                    'column' => 'masterbatch_item_id',
                    'reason' => 'archived_rows_uncounted',
                    'message' => 'the schema cascades masterbatch_dosings.masterbatch_item_id and the check that declares it does not count archived rows',
                ],
                [
                    'table' => 'masterbatch_dosings',
                    'column' => 'product_item_id',
                    'reason' => 'archived_rows_uncounted',
                    'message' => 'the schema cascades masterbatch_dosings.product_item_id and the check that declares it does not count archived rows',
                ],
            ],
            $ignoresTrashed->report($item)->cascadeGaps(),
        );
        $this->assertSame([], $complete->report($item)->cascadeGaps());

        $this->expectException(ConfigurationInUseException::class);
        $ignoresTrashed->delete($item);
    }

    /**
     * Coverage is per FOREIGN-KEY COLUMN. `masterbatch_dosings` cascades
     * into `items` through two of them, and a check that counts only
     * `masterbatch_item_id` proves NOTHING about an item referenced solely
     * as `product_item_id` — its count comes back zero while the dosing row
     * is one DELETE away from being destroyed. A per-table match would have
     * called that declaration complete.
     */
    public function test_one_declared_column_does_not_cover_a_second_cascading_column(): void
    {
        $masterbatch = Item::create(['sku' => 'MB-1', 'name' => 'Amber masterbatch', 'uom' => 'Kg', 'is_active' => true]);
        $product = Item::create(['sku' => 'PRD-1', 'name' => 'Amber bottle', 'uom' => 'Nos', 'is_active' => true]);

        $dosingId = DB::table('masterbatch_dosings')->insertGetId([
            'masterbatch_item_id' => $masterbatch->id,
            'product_item_id' => $product->id,
            'grams_per_bottle' => '0.2500',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $halfDeclared = new ConfigurationLifecycle(
            label: 'item',
            checks: [
                // Only ONE of the two cascading columns.
                DependencyCheck::table('masterbatch_dosings', 'masterbatch_item_id')->label('masterbatch dosing')->cascadeSide(),
                DependencyCheck::table('stock_balances', 'item_id')->label('stock balance')->cascadeSide(),
                DependencyCheck::table('packing_material_mappings', 'item_id')->label('packing mapping')->cascadeSide(),
                DependencyCheck::table('batch_resin_allocations', 'item_id')->label('resin allocation')->cascadeSide(),
                DependencyCheck::table('resin_pool_balances', 'item_id')->label('resin pool balance')->cascadeSide(),
                DependencyCheck::table('production_configurations', 'item_id')->label('production configuration')->cascadeSide(),
            ],
            canHardDelete: fn (?Authenticatable $user): bool => true,
        );

        // The declared column counts nothing for this item — the dosing
        // names it as the PRODUCT — so without column granularity the report
        // would be clear and the dosing would be gone.
        $this->assertSame([], $halfDeclared->report($product)->blocking());
        $this->assertSame(
            [['table' => 'masterbatch_dosings', 'column' => 'product_item_id', 'reason' => 'undeclared']],
            array_map(
                fn (array $gap): array => ['table' => $gap['table'], 'column' => $gap['column'], 'reason' => $gap['reason']],
                $halfDeclared->report($product)->cascadeGaps(),
            ),
        );

        try {
            $halfDeclared->delete($product);
            $this->fail('An uncovered cascading COLUMN must refuse the delete.');
        } catch (ConfigurationInUseException) {
            // expected
        }

        $this->assertNotNull(Item::withTrashed()->find($product->id));
        $this->assertSame(1, DB::table('masterbatch_dosings')->where('id', $dosingId)->count());
    }

    /**
     * The inspector itself, on whichever driver is running — the same
     * assertions on sqlite and on MySQL.
     */
    public function test_the_inspector_reads_this_drivers_cascades(): void
    {
        $connection = DB::connection();

        $this->assertContains(
            $connection->getDriverName(),
            ['sqlite', 'mysql', 'mariadb'],
            'the suite runs on sqlite and on MySQL; a third driver needs its own reader before it can be trusted.',
        );

        $cascades = SchemaCascades::referencing($connection, 'employees');

        $this->assertNotNull($cascades);
        $this->assertSame(self::EMPLOYEE_CASCADES, array_column($cascades, 'table'));
        $this->assertSame(['employee_id'], $cascades[0]['columns']);

        // RESTRICT and SET NULL are not cascades: `payslips.employee_id` is
        // RESTRICT, so the database itself refuses — this backstop is only
        // for the children nothing else guards.
        $this->assertNotContains('payslips', array_column($cascades, 'table'));

        // A master with no cascading child at all answers an empty list —
        // "asked, and there are none", which is not the same as null.
        $this->assertSame([], SchemaCascades::referencing($connection, 'molds'));
    }

    /** Cached per parent table: one read, however many checks ask. */
    public function test_the_schema_is_read_once_per_table(): void
    {
        $employee = $this->employee();
        SchemaCascades::flush();

        DB::enableQueryLog();
        $this->declaredLifecycle()->report($employee);
        $first = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->declaredLifecycle()->report($employee);
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(count(self::EMPLOYEE_CASCADES), $second, 'the second report pays only its four COUNTs');
        $this->assertGreaterThan($second, $first, 'the first one also read the schema');
    }

    /**
     * A driver this class cannot introspect is not silently treated as
     * "no cascades" — that would fail OPEN, the one direction a backstop may
     * never fail in. It refuses everything instead.
     */
    public function test_an_unreadable_driver_refuses_every_delete(): void
    {
        config(['database.connections.__unreadable' => ['driver' => 'pgsql', 'database' => 'nowhere']]);

        $employee = $this->employee();
        $employee->setConnection('__unreadable');

        $report = DependencyReport::for($employee, []);

        $this->assertFalse($report->isClear());
        $this->assertSame([[
            'table' => '*',
            'column' => '*',
            'reason' => 'schema_unreadable',
            'message' => "the schema's cascades cannot be read on a pgsql connection, so no delete can be proven safe",
        ]], $report->cascadeGaps());
        $this->assertNull(SchemaCascades::referencing(DB::connection('__unreadable'), 'employees'));
    }
}
