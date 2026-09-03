<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Runs what SqlGuard let through, on the configured read connection, with
 * a statement timeout on MySQL and a hard row cap on every driver.
 */
class QueryRunner
{
    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool} */
    public function run(string $sql, int $rowLimit): array
    {
        $connection = DB::connection(config('ask-erp.connection') ?: null);

        try {
            if ($connection->getDriverName() === 'mysql') {
                $connection->statement('SET SESSION MAX_EXECUTION_TIME = 10000');
            }
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
}
