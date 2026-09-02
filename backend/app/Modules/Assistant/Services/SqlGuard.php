<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\SqlRefusedException;

/**
 * The only door to QueryRunner. One SELECT, on tables this reader may see,
 * naming no column they may not, with a row cap. Everything it cannot prove
 * safe it refuses — a refusal costs the user a rephrase, a miss costs a
 * leak. The scan is textual on purpose: a table hidden inside a subquery or
 * a CTE body is plain text to it, and is found.
 */
class SqlGuard
{
    private const array FORBIDDEN = [
        '/\bINTO\b/i' => 'INTO is not allowed',
        '/\bFOR\s+UPDATE\b/i' => 'FOR UPDATE is not allowed',
        '/\bLOCK\b/i' => 'LOCK is not allowed',
        '/\bLOAD_FILE\b/i' => 'LOAD_FILE is not allowed',
        '/\bSLEEP\s*\(/i' => 'SLEEP is not allowed',
        '/\bBENCHMARK\s*\(/i' => 'BENCHMARK is not allowed',
        '/\binformation_schema\b/i' => 'information_schema is not available',
        '/\bperformance_schema\b/i' => 'performance_schema is not available',
        '/\bmysql\s*\./i' => 'the mysql schema is not available',
        '/\bsqlite_master\b/i' => 'sqlite_master is not available',
    ];

    /**
     * @param  list<string>  $allowedTables
     * @param  array<string, list<string>>  $hiddenColumnsByTable
     */
    public function check(string $sql, array $allowedTables, array $hiddenColumnsByTable, int $rowLimit): string
    {
        $sql = rtrim(trim($sql), "; \n\r\t");

        if (str_contains($sql, ';')) {
            throw new SqlRefusedException('Only one statement may run.');
        }
        if (preg_match('/--|#|\/\*/', $sql)) {
            throw new SqlRefusedException('SQL comments are not allowed.');
        }
        if (! preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new SqlRefusedException('Only a SELECT may run.');
        }
        foreach (self::FORBIDDEN as $pattern => $reason) {
            if (preg_match($pattern, $sql)) {
                throw new SqlRefusedException($reason.'.');
            }
        }

        $tables = $this->tablesIn($sql);
        foreach ($tables as $table) {
            if (! in_array($table, $allowedTables, true)) {
                throw new SqlRefusedException("The table {$table} is not available to you.");
            }
        }

        $this->refuseHiddenColumns($sql, $tables, $hiddenColumnsByTable);

        return $this->applyLimit($sql, $rowLimit);
    }

    /**
     * Every real table named after FROM or JOIN — CTE names and derived
     * tables excluded — lower-cased, unique, sorted.
     *
     * @return list<string>
     */
    public function tablesIn(string $sql): array
    {
        $ctes = [];
        if (preg_match_all('/(?:\bWITH\b|,)\s*`?([a-z_][a-z0-9_]*)`?\s+AS\s*\(/i', $sql, $matches)) {
            $ctes = array_map('strtolower', $matches[1]);
        }

        preg_match_all('/\b(?:FROM|JOIN)\s+`?(?:[a-z_][a-z0-9_]*`?\s*\.\s*`?)?([a-z_][a-z0-9_]*)`?/i', $sql, $matches);

        $tables = [];
        foreach ($matches[1] as $name) {
            $name = strtolower($name);
            if ($name === 'select' || in_array($name, $ctes, true)) {
                continue;
            }
            $tables[] = $name;
        }
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, list<string>>  $hiddenColumnsByTable
     */
    private function refuseHiddenColumns(string $sql, array $tables, array $hiddenColumnsByTable): void
    {
        $hidden = [];
        foreach ($tables as $table) {
            foreach ($hiddenColumnsByTable[$table] ?? [] as $column) {
                $hidden[$column] = $table;
            }
        }
        if ($hidden === []) {
            return;
        }

        if (preg_match('/(^|[\s,(])(`?[a-z_][a-z0-9_]*`?\s*\.\s*)?\*/i', $sql)) {
            $names = implode(', ', array_keys($hidden));

            throw new SqlRefusedException("SELECT * is not allowed here: {$names} is not available to you. Name the columns.");
        }

        foreach ($hidden as $column => $table) {
            if (preg_match('/\b'.preg_quote($column, '/').'\b/i', $sql)) {
                throw new SqlRefusedException("The column {$table}.{$column} is not available to you.");
            }
        }
    }

    private function applyLimit(string $sql, int $rowLimit): string
    {
        if (preg_match('/\bLIMIT\s+(\d+)(\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', $sql, $matches)) {
            if ((int) $matches[1] <= $rowLimit) {
                return $sql;
            }

            return (string) preg_replace('/\bLIMIT\s+\d+/i', 'LIMIT '.$rowLimit, $sql);
        }

        return $sql.' LIMIT '.$rowLimit;
    }
}
