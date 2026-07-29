<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * MySQL rejects any identifier longer than 64 characters. SQLite does not,
 * so a migration with an over-long generated index or foreign-key name
 * passes the whole test suite locally and then fails on the production
 * server, mid-migration, leaving the database half-applied.
 *
 * That happened once (production_standard_packagings_production_standard_id
 * _mode_unique, 65 chars). This test makes it impossible to happen quietly
 * again: it computes the names Laravel WOULD generate and fails before the
 * migration ever reaches a real server.
 *
 * Laravel's naming is driver-independent — "{table}_{col1}_{col2}_{type}" —
 * so it can be checked statically.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    private const MYSQL_MAX_IDENTIFIER = 64;

    public function test_no_migration_generates_an_identifier_mysql_will_reject(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->tableBlocks($source) as [$table, $body]) {
                foreach ($this->generatedNames($table, $body) as $name) {
                    if (strlen($name) > self::MYSQL_MAX_IDENTIFIER) {
                        $offenders[] = sprintf(
                            '%s: %s (%d chars)',
                            basename($file),
                            $name,
                            strlen($name),
                        );
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode(
            "\n",
            array_merge(
                ['Identifiers longer than 64 characters are rejected by MySQL.',
                    'Give these an explicit short name — $table->unique([...], \'short_name\')',
                    'or ->constrained(table: ..., indexName: \'short_name\'):', ''],
                $offenders,
            ),
        ));
    }

    /**
     * Every Schema::create/table block in a migration, as [table, body].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function tableBlocks(string $source): array
    {
        preg_match_all(
            "/Schema::(?:create|table)\(\s*'([a-z0-9_]+)'\s*,\s*function\s*\(Blueprint\s+\\\$table\)\s*(?:use\s*\([^)]*\)\s*)?\{(.*?)\n        \}\);/s",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn ($m) => [$m[1], $m[2]], $matches);
    }

    /**
     * The identifiers Laravel would generate for one table block, skipping
     * any that already carry an explicit name.
     *
     * @return list<string>
     */
    private function generatedNames(string $table, string $body): array
    {
        $names = [];

        // foreignId('x')->constrained(...) with no indexName argument.
        preg_match_all("/foreignId\(\s*'([a-z0-9_]+)'\s*\)((?:(?!foreignId|\n\s*\\\$table->)[\s\S])*)/", $body, $fks, PREG_SET_ORDER);
        foreach ($fks as $fk) {
            if (! str_contains($fk[2], 'constrained') || str_contains($fk[2], 'indexName')) {
                continue;
            }
            $names[] = "{$table}_{$fk[1]}_foreign";
        }

        // unique()/index() taking a column list and no explicit second arg.
        foreach (['unique', 'index'] as $type) {
            preg_match_all("/->{$type}\(\s*\[([^\]]+)\]\s*\)/", $body, $multi, PREG_SET_ORDER);
            foreach ($multi as $m) {
                preg_match_all("/'([a-z0-9_]+)'/", $m[1], $cols);
                $names[] = $table.'_'.implode('_', $cols[1])."_{$type}";
            }

            preg_match_all("/->{$type}\(\s*'([a-z0-9_]+)'\s*\)/", $body, $single, PREG_SET_ORDER);
            foreach ($single as $m) {
                $names[] = "{$table}_{$m[1]}_{$type}";
            }
        }

        return $names;
    }

    public function test_the_checker_actually_catches_the_bug_that_reached_production(): void
    {
        // Guard against a false green: the detector must flag the exact
        // migration body that broke the deploy.
        $body = "\n            \$table->unique(['production_standard_id', 'mode']);";
        $names = (fn () => $this->generatedNames('production_standard_packagings', $body))
            ->call($this);

        $this->assertContains('production_standard_packagings_production_standard_id_mode_unique', $names);
        $this->assertGreaterThan(self::MYSQL_MAX_IDENTIFIER, strlen($names[0]));
    }
}
