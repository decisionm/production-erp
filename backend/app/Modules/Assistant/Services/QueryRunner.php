<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Runs what SqlGuard let through, on the configured read connection, with a
 * best-effort statement timeout and a hard row cap on every driver.
 *
 * TWO THINGS THIS GOT WRONG ON LIVE, both found by asking the deployed page a
 * real question rather than by any test:
 *
 * 1. MariaDB does not have MAX_EXECUTION_TIME. It spells the same idea
 *    `max_statement_time`, and in SECONDS rather than milliseconds. Laravel
 *    reports MariaDB's driver as `mysql`, so the old check took the MySQL
 *    branch and every question died on "Unknown system variable". The live
 *    factory runs MariaDB 11.8; the test suites run SQLite and MySQL 8, which
 *    is why nothing caught it.
 *
 * 2. A failed timeout ABORTED THE QUESTION. The timeout is a safety net
 *    against one runaway query, not a correctness requirement — a server that
 *    will not accept it should still answer. Setting it is now attempted
 *    separately and its failure is logged, never raised.
 */
class QueryRunner
{
    /** Seconds a single question may spend in the database. */
    private const TIMEOUT_SECONDS = 10;

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool} */
    public function run(string $sql, int $rowLimit): array
    {
        $connection = DB::connection(config('ask-erp.connection') ?: null);

        $this->applyTimeout($connection);

        try {
            $result = $connection->select($sql);
        } catch (QueryException $e) {
            throw new AskErpException('The query failed: '.$e->getMessage(), 422);
        }

        $rows = array_map(static fn ($row) => (array) $row, $result);
        $truncated = count($rows) > $rowLimit;
        $rows = array_slice($rows, 0, $rowLimit);
        $columns = $rows === [] ? [] : array_keys($rows[0]);

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Best effort, and deliberately swallowing everything: a server that
     * refuses the variable still gets to answer the question. The row cap and
     * the guard are the protections that must hold; this one is a courtesy.
     */
    private function applyTimeout(ConnectionInterface $connection): void
    {
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        try {
            $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            $connection->statement(self::timeoutStatement($version, self::TIMEOUT_SECONDS));
        } catch (Throwable $e) {
            Log::warning('Ask ERP could not set a statement timeout; the query still runs.', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The right spelling for the server in front of us.
     *
     * MariaDB: `max_statement_time`, in SECONDS, and it accepts a decimal.
     * MySQL:   `max_execution_time`, in MILLISECONDS, integer.
     *
     * Split out and public so both branches are covered by a test without
     * needing either server present — which is exactly the gap that let the
     * original bug reach the factory.
     */
    public static function timeoutStatement(string $serverVersion, int $seconds): string
    {
        return str_contains(strtolower($serverVersion), 'mariadb')
            ? 'SET SESSION max_statement_time = '.$seconds
            : 'SET SESSION max_execution_time = '.($seconds * 1000);
    }
}
