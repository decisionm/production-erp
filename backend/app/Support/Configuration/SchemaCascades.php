<?php

namespace App\Support\Configuration;

use Illuminate\Database\Connection;

/**
 * What the DATABASE would destroy if a configuration row were really
 * deleted — read from the schema, never from a hand-written list.
 *
 * WHY THIS EXISTS. `ConfigurationLifecycle::delete()` destroys a row on the
 * strength of the module's declared DependencyChecks. If a module forgets
 * one cascading child, the report is clear, the locked re-check is clear
 * too, and `ON DELETE CASCADE` silently takes the child with it — an
 * attendance, a leave balance, a stock balance — with no database backstop
 * and no trace. A declaration is a human list, so it WILL be incomplete one
 * day; the schema is not, and it is the same schema the delete will run
 * against. So the schema is asked first, and a cascade nobody declared
 * REFUSES the delete (DependencyReport::cascadeGaps()).
 *
 * FAIL-CLOSED IN EVERY DIRECTION. `referencing()` returns null — not an
 * empty list — when the driver is one this class cannot introspect, and the
 * report treats null as "the schema could not be read", which blocks. An
 * empty list means "asked, and there are genuinely no cascades".
 *
 * Both drivers the suite runs on are implemented, because they answer
 * differently and only one of them is exercised locally:
 *   - sqlite: `PRAGMA foreign_key_list(<table>)` per table, walking
 *     sqlite_master, because sqlite has no cross-table FK catalogue;
 *   - MySQL/MariaDB: ONE `information_schema` query joining
 *     REFERENTIAL_CONSTRAINTS to KEY_COLUMN_USAGE on DELETE_RULE='CASCADE'.
 *
 * Cached per (connection, PARENT table) — the brief's "one query, not one
 * per check". Deliberately NOT a single whole-schema map: a table created
 * after that map was built would be missing from it, and a missing cascade
 * fails OPEN, which is the one direction this class may never fail in.
 */
class SchemaCascades
{
    /** @var array<string, list<array{table: string, columns: list<string>}>|null> */
    private static array $cascades = [];

    /**
     * Every foreign key that references $parentTable with ON DELETE CASCADE,
     * grouped by child table. NULL when this driver's schema cannot be read.
     *
     * @return list<array{table: string, columns: list<string>}>|null
     */
    public static function referencing(Connection $connection, string $parentTable): ?array
    {
        $key = ($connection->getName() ?? 'default').':'.mb_strtolower($parentTable);

        if (array_key_exists($key, self::$cascades)) {
            return self::$cascades[$key];
        }

        return self::$cascades[$key] = self::read($connection, $parentTable);
    }

    /** Forget everything read so far — for tests that change the schema. */
    public static function flush(): void
    {
        self::$cascades = [];
    }

    /** @return list<array{table: string, columns: list<string>}>|null */
    private static function read(Connection $connection, string $parentTable): ?array
    {
        return match ($connection->getDriverName()) {
            'sqlite' => self::fromSqlite($connection, $parentTable),
            'mysql', 'mariadb' => self::fromMysql($connection, $parentTable),
            default => null,
        };
    }

    /**
     * sqlite keeps no catalogue of who points at whom, so every table is
     * asked what IT points at and the answers are filtered to this parent.
     *
     * @return list<array{table: string, columns: list<string>}>
     */
    private static function fromSqlite(Connection $connection, string $parentTable): array
    {
        $found = [];

        $tables = $connection->select(
            "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"
        );

        foreach ($tables as $table) {
            $child = (string) $table->name;

            foreach ($connection->select('pragma foreign_key_list("'.str_replace('"', '""', $child).'")') as $fk) {
                if (mb_strtoupper((string) $fk->on_delete) !== 'CASCADE') {
                    continue;
                }

                // PRAGMA prints the referenced table with the DDL's own
                // casing, which need not match the model's $table.
                if (mb_strtolower((string) $fk->table) !== mb_strtolower($parentTable)) {
                    continue;
                }

                $found[mb_strtolower($child)][] = (string) $fk->from;
            }
        }

        return self::shape($found);
    }

    /**
     * MySQL knows the whole graph, so this is one query for this parent.
     *
     * @return list<array{table: string, columns: list<string>}>
     */
    private static function fromMysql(Connection $connection, string $parentTable): array
    {
        $rows = $connection->select(
            'select kcu.TABLE_NAME as child_table, kcu.COLUMN_NAME as child_column
               from information_schema.REFERENTIAL_CONSTRAINTS rc
               join information_schema.KEY_COLUMN_USAGE kcu
                 on kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                and kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                and kcu.TABLE_NAME = rc.TABLE_NAME
              where rc.CONSTRAINT_SCHEMA = database()
                and kcu.REFERENCED_TABLE_SCHEMA = database()
                and upper(rc.DELETE_RULE) = ?
                and lower(kcu.REFERENCED_TABLE_NAME) = ?',
            ['CASCADE', mb_strtolower($parentTable)],
        );

        $found = [];

        foreach ($rows as $row) {
            $found[mb_strtolower((string) $row->child_table)][] = (string) $row->child_column;
        }

        return self::shape($found);
    }

    /**
     * @param  array<string, list<string>>  $found
     * @return list<array{table: string, columns: list<string>}>
     */
    private static function shape(array $found): array
    {
        ksort($found);

        $cascades = [];

        foreach ($found as $table => $columns) {
            $columns = array_values(array_unique($columns));

            // Sorted, because the two drivers do not agree on the order they
            // hand back a table's foreign keys and a refusal that reads
            // differently on sqlite and on MySQL is a refusal nobody can pin.
            sort($columns);

            $cascades[] = ['table' => $table, 'columns' => $columns];
        }

        return $cascades;
    }
}
