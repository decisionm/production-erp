<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\HardDeleteAuthority;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Shared scaffolding for the WS-3 product-definition masters' lifecycle
 * tests — ProductionStandard, ProductionStandardPackaging,
 * ProductionConfiguration, Employee.
 *
 * TWO THINGS LIVE HERE AND NOTHING ELSE.
 *
 * 1 · The two users every test needs. The Configuration Lifecycle Contract
 *     splits authority in two (DEC-20260817-002 §3): a module-manage user
 *     may create, edit, activate and deactivate; ONLY an Owner-level user
 *     carrying the configuration-delete grant may hard-delete. The
 *     permission is created here rather than read from a seeder so these
 *     tests prove the TIER regardless of which catalogue key it eventually
 *     lands under (HardDeleteAuthority::PERMISSION, WS-1's catalogue entry).
 *
 * 2 · THE DECLARATION-COVERAGE ASSERTION, which is the reason this file
 *     exists at all. The shipped schema backstop (SchemaCascades) reads
 *     CASCADE foreign keys ONLY — correctly, because a cascade destroys a
 *     child. But for these four entities MOST references are SET NULL, and
 *     one is RESTRICT:
 *
 *       SET NULL  · the child row SURVIVES with the reference silently
 *                   blanked. `shift_production_entries.
 *                   supervisor_signed_by` is a SIGNATURE on a posted
 *                   production document. Nothing in the mechanism can see
 *                   this; only the module's declaration can.
 *       RESTRICT  · the database refuses, but as a raw driver error — a 500
 *                   nobody can act on instead of the contract's 422 with a
 *                   count and an Archive offer.
 *
 *     So `assertEveryUndefendedReferenceIsDeclared()` reads the SCHEMA for
 *     every non-cascading foreign key pointing at the entity's table and
 *     asserts each one is covered by a declared check. It is the same idea
 *     as the shipped backstop, applied to the half the backstop is silent
 *     about — and it lives in the tests, not in the mechanism, because it
 *     proves a DECLARATION rather than changing a policy.
 */
abstract class ProductDefinitionLifecycleTestCase extends TestCase
{
    /** A user who may run the module but may NOT hard-delete anything. */
    protected function moduleUser(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** The Owner-level user of DEC-20260817-002 §3 — the only one who may destroy a master. */
    protected function ownerUser(string ...$permissions): User
    {
        $user = $this->moduleUser(...$permissions);

        Permission::findOrCreate(HardDeleteAuthority::PERMISSION, 'web');
        $user->givePermissionTo(HardDeleteAuthority::PERMISSION);

        return $user->fresh();
    }

    /**
     * Every foreign key pointing at $table that the shipped cascade backstop
     * CANNOT see — SET NULL and RESTRICT — must be covered by a declared
     * check on $service.
     *
     * @param  object  $service  a service using ManagesConfigurationLifecycle
     */
    protected function assertEveryUndefendedReferenceIsDeclared(object $service, string $table): void
    {
        $this->assertContains(
            ManagesConfigurationLifecycle::class,
            class_uses_recursive($service::class),
            $service::class.' does not use the shared configuration lifecycle at all.',
        );

        $method = new ReflectionMethod($service, 'dependencyChecks');
        $method->setAccessible(true);

        /** @var list<DependencyCheck> $checks */
        $checks = $method->invoke($service);

        $declared = [];

        foreach ($checks as $check) {
            foreach ($check->coveredCascades() as $child => $columns) {
                foreach ($columns as $column) {
                    $declared[mb_strtolower($child)][mb_strtolower($column)] = true;
                }
            }
        }

        $undefended = $this->undefendedReferencesTo($table);

        $this->assertNotSame([], $undefended, "no non-cascading foreign key points at {$table} — this assertion would prove nothing");

        foreach ($undefended as [$child, $column, $rule]) {
            $this->assertTrue(
                isset($declared[$child][$column]),
                "{$child}.{$column} references {$table} ON DELETE {$rule}. The schema backstop only sees CASCADE, "
                .'so nothing would stop a hard delete from rewriting it — declare a DependencyCheck covering it.',
            );
        }
    }

    /**
     * SET NULL / RESTRICT foreign keys pointing at $table, read from the
     * live test schema rather than from a list anybody maintains.
     *
     * BOTH DRIVERS, because CI runs both and only one is exercised locally
     * (ci.yml: in-memory sqlite as the fast leg, MySQL 8 as the parity leg —
     * and MySQL is what the factory's live instance is). An assertion that
     * skipped the parity leg would be silent on the driver that matters.
     * The MySQL query is `SchemaCascades::fromMysql()` with its predicate
     * inverted: everything that is NOT a cascade.
     *
     * @return list<array{0: string, 1: string, 2: string}> child table, column, delete rule
     */
    protected function undefendedReferencesTo(string $table): array
    {
        $connection = DB::connection();

        $found = match ($connection->getDriverName()) {
            'sqlite' => $this->undefendedFromSqlite($table),
            'mysql', 'mariadb' => $this->undefendedFromMysql($table),
            default => $this->fail('this assertion knows sqlite and MySQL only; '.$connection->getDriverName().' would fail open'),
        };

        sort($found);

        return $found;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function undefendedFromSqlite(string $table): array
    {
        $connection = DB::connection();
        $found = [];

        foreach ($connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $child) {
            $childTable = (string) $child->name;

            foreach ($connection->select('pragma foreign_key_list("'.str_replace('"', '""', $childTable).'")') as $fk) {
                if (mb_strtolower((string) $fk->table) !== mb_strtolower($table)) {
                    continue;
                }

                $rule = mb_strtoupper((string) $fk->on_delete);

                if ($rule === 'CASCADE') {
                    continue; // the shipped backstop already sees this one
                }

                $found[] = [mb_strtolower($childTable), mb_strtolower((string) $fk->from), $rule];
            }
        }

        return $found;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function undefendedFromMysql(string $table): array
    {
        $rows = DB::connection()->select(
            'select kcu.TABLE_NAME as child_table, kcu.COLUMN_NAME as child_column, rc.DELETE_RULE as rule
               from information_schema.REFERENTIAL_CONSTRAINTS rc
               join information_schema.KEY_COLUMN_USAGE kcu
                 on kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                and kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                and kcu.TABLE_NAME = rc.TABLE_NAME
              where rc.CONSTRAINT_SCHEMA = database()
                and kcu.REFERENCED_TABLE_SCHEMA = database()
                and upper(rc.DELETE_RULE) <> ?
                and lower(kcu.REFERENCED_TABLE_NAME) = ?',
            ['CASCADE', mb_strtolower($table)],
        );

        return array_map(
            static fn (object $row): array => [
                mb_strtolower((string) $row->child_table),
                mb_strtolower((string) $row->child_column),
                mb_strtoupper((string) $row->rule),
            ],
            $rows,
        );
    }

    /** The `configuration` activity-log rows written for one record. */
    protected function auditTrailFor(Model $model): Collection
    {
        return DB::table('activity_log')
            ->where('log_name', 'configuration')
            ->where('subject_type', $model::class)
            ->where('subject_id', $model->getKey())
            ->orderBy('id')
            ->get();
    }
}
