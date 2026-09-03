<?php

namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Services\QueryRunner;
use Tests\TestCase;

/**
 * The statement timeout, which broke every question on the live factory.
 *
 * The live server is MariaDB; the suites run SQLite and MySQL 8. No test
 * could have caught the original bug because neither of those servers is the
 * one that rejects the variable. Splitting the SQL out lets both spellings be
 * pinned from any machine.
 */
class QueryRunnerTimeoutTest extends TestCase
{
    public function test_mariadb_gets_max_statement_time_in_seconds(): void
    {
        // What the live factory actually reports.
        $sql = QueryRunner::timeoutStatement('11.8.8-MariaDB-log', 10);

        $this->assertSame('SET SESSION max_statement_time = 10', $sql);
    }

    public function test_mysql_gets_max_execution_time_in_milliseconds(): void
    {
        $sql = QueryRunner::timeoutStatement('8.0.36', 10);

        $this->assertSame('SET SESSION max_execution_time = 10000', $sql);
    }

    public function test_the_mariadb_check_is_not_case_sensitive(): void
    {
        // Server strings vary by build: "MariaDB", "mariadb", "-MARIADB-log".
        foreach (['10.11.2-MARIADB', '10.6.16-mariadb-log', '11.4.2-MariaDB'] as $version) {
            $this->assertStringContainsString(
                'max_statement_time',
                QueryRunner::timeoutStatement($version, 10),
                "version {$version}",
            );
        }
    }

    public function test_a_question_still_answers_when_the_timeout_cannot_be_set(): void
    {
        // The suite runs SQLite, where no timeout is attempted at all — which
        // is the same path a server that refuses the variable now takes. The
        // point being pinned is that the ROWS still come back either way.
        $result = app(QueryRunner::class)->run('SELECT 1 AS n', 200);

        $this->assertSame(['n'], $result['columns']);
        $this->assertSame(1, (int) $result['rows'][0]['n']);
        $this->assertFalse($result['truncated']);
    }

    public function test_the_row_cap_still_holds(): void
    {
        $result = app(QueryRunner::class)->run('SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3', 2);

        $this->assertCount(2, $result['rows']);
        $this->assertTrue($result['truncated']);
    }
}
