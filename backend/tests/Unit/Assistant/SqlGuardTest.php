<?php

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Exceptions\SqlRefusedException;
use App\Modules\Assistant\Services\SqlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlGuardTest extends TestCase
{
    private SqlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SqlGuard;
    }

    public function test_plain_select_on_allowed_table_passes_and_gets_a_limit(): void
    {
        $sql = $this->guard->check('SELECT status, COUNT(*) AS n FROM purchase_orders GROUP BY status', ['purchase_orders'], [], 200);

        $this->assertSame('SELECT status, COUNT(*) AS n FROM purchase_orders GROUP BY status LIMIT 200', $sql);
    }

    public function test_existing_limit_is_kept_but_capped(): void
    {
        $this->assertStringEndsWith('LIMIT 50', $this->guard->check('SELECT id FROM vendors LIMIT 50', ['vendors'], [], 200));
        $this->assertStringEndsWith('LIMIT 200', $this->guard->check('SELECT id FROM vendors LIMIT 5000', ['vendors'], [], 200));
    }

    public function test_trailing_semicolon_and_whitespace_are_tolerated(): void
    {
        $this->assertSame('SELECT id FROM vendors LIMIT 200', $this->guard->check("SELECT id FROM vendors;\n", ['vendors'], [], 200));
    }

    #[DataProvider('refused')]
    public function test_refuses(string $sql, string $reason): void
    {
        $this->expectException(SqlRefusedException::class);
        $this->expectExceptionMessage($reason);

        $this->guard->check($sql, ['vendors', 'purchase_orders'], ['purchase_orders' => ['total_amount']], 200);
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'update' => ['UPDATE vendors SET name = 1', 'Only a SELECT'],
            'delete' => ['DELETE FROM vendors', 'Only a SELECT'],
            'two statements' => ['SELECT 1; DROP TABLE vendors', 'one statement'],
            'comment' => ['SELECT id FROM vendors -- x', 'comments'],
            'block comment' => ['SELECT /* x */ id FROM vendors', 'comments'],
            'into outfile' => ["SELECT id INTO OUTFILE '/x' FROM vendors", 'INTO'],
            'for update' => ['SELECT id FROM vendors FOR UPDATE', 'FOR UPDATE'],
            'sleep' => ['SELECT SLEEP(5) FROM vendors', 'SLEEP'],
            'information schema' => ['SELECT * FROM information_schema.tables', 'not available'],
            'other table' => ['SELECT id FROM employees', 'employees'],
            'join to other table' => ['SELECT v.id FROM vendors v JOIN employees e ON e.id = v.id', 'employees'],
            'subquery other table' => ['SELECT id FROM vendors WHERE id IN (SELECT id FROM employees)', 'employees'],
            'cte over other table' => ['WITH e AS (SELECT id FROM employees) SELECT id FROM e', 'employees'],
            'star on table with hidden column' => ['SELECT * FROM purchase_orders', 'total_amount'],
            'aliased star on table with hidden column' => ['SELECT po.* FROM purchase_orders po', 'total_amount'],
            'qualified hidden column' => ['SELECT po.total_amount FROM purchase_orders po', 'total_amount'],
            'bare hidden column' => ['SELECT total_amount FROM purchase_orders', 'total_amount'],
            'hidden column in aggregate' => ['SELECT SUM(total_amount) FROM purchase_orders', 'total_amount'],
        ];
    }

    public function test_count_star_on_a_table_with_hidden_columns_is_fine(): void
    {
        $sql = $this->guard->check('SELECT po.status, COUNT(*) AS n FROM purchase_orders po GROUP BY po.status', ['purchase_orders'], ['purchase_orders' => ['total_amount']], 200);

        $this->assertStringEndsWith('LIMIT 200', $sql);
    }

    public function test_star_on_a_table_without_hidden_columns_is_fine(): void
    {
        $sql = $this->guard->check('SELECT * FROM vendors', ['vendors', 'purchase_orders'], ['purchase_orders' => ['total_amount']], 200);

        $this->assertSame('SELECT * FROM vendors LIMIT 200', $sql);
    }

    public function test_cte_names_are_not_treated_as_tables(): void
    {
        $sql = 'WITH recent AS (SELECT vendor_id FROM purchase_orders) SELECT v.id FROM vendors v JOIN recent r ON r.vendor_id = v.id';

        $this->assertStringEndsWith('LIMIT 200', $this->guard->check($sql, ['vendors', 'purchase_orders'], [], 200));
        $this->assertSame(['purchase_orders', 'vendors'], $this->guard->tablesIn($sql));
    }

    public function test_derived_table_is_not_a_table_name(): void
    {
        $sql = 'SELECT t.n FROM (SELECT COUNT(*) AS n FROM vendors) t';

        $this->assertSame(['vendors'], $this->guard->tablesIn($sql));
        $this->assertStringEndsWith('LIMIT 200', $this->guard->check($sql, ['vendors'], [], 200));
    }

    public function test_backticked_and_qualified_names_are_read(): void
    {
        $this->assertSame(['vendors'], $this->guard->tablesIn('SELECT id FROM `vendors`'));
        $this->assertSame(['vendors'], $this->guard->tablesIn('SELECT id FROM erp.`vendors`'));
        $this->assertStringContainsString('LIMIT 200', $this->guard->check('SELECT id FROM `vendors`', ['vendors'], [], 200));
    }
}
