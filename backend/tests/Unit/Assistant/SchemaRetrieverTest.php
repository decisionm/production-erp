<?php

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Catalogue\ColumnSpec;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\TableSpec;
use App\Modules\Assistant\Services\SchemaRetriever;
use Illuminate\Contracts\Auth\Authenticatable;
use PHPUnit\Framework\TestCase;

class SchemaRetrieverTest extends TestCase
{
    private SchemaCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = SchemaCatalogue::fromArray([
            new TableSpec('vendors', 'procurement', 'Vendors', 'A supplier.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('name', 'string', meaning: 'supplier name', sensitive: 'supplier-identity'),
            ], keywords: ['supplier'], questions: ['How many vendors?']),
            new TableSpec('purchase_orders', 'procurement', 'Purchase Orders', 'A PO on a vendor.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('vendor_id', 'integer', meaning: 'the supplier', references: 'vendors.id'),
                new ColumnSpec('total_amount', 'decimal', meaning: 'PO value', sensitive: 'rates'),
            ], joins: ['purchase_orders.vendor_id = vendors.id'], keywords: ['po', 'purchase order']),
            new TableSpec('employees', 'hrms', 'Employees', 'A person.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('phone', 'string', meaning: 'mobile', sensitive: 'personal'),
            ], keywords: ['staff', 'worker']),
        ]);
    }

    /** @param list<string> $permissions */
    private function user(array $permissions): Authenticatable
    {
        return new class($permissions) implements Authenticatable
        {
            /** @param list<string> $permissions */
            public function __construct(private array $permissions) {}

            /** @param list<string> $names */
            public function hasAnyPermission(array $names): bool
            {
                return count(array_intersect($names, $this->permissions)) > 0;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 1;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): string
            {
                return '';
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return '';
            }
        };
    }

    private function retriever(): SchemaRetriever
    {
        return new SchemaRetriever($this->catalogue, tablesPerQuestion: 8);
    }

    /** @param list<TableSpec> $specs @return list<string> */
    private function names(array $specs): array
    {
        return array_map(static fn (TableSpec $spec) => $spec->table, $specs);
    }

    public function test_only_tables_of_viewable_modules_are_allowed(): void
    {
        $allowed = $this->retriever()->allowedTables($this->user(['hrms.view']));

        $this->assertSame(['employees'], array_keys($allowed));
    }

    public function test_manage_counts_as_view(): void
    {
        $allowed = $this->retriever()->allowedTables($this->user(['procurement.manage']));

        $this->assertSame(['purchase_orders', 'vendors'], array_keys($allowed));
    }

    public function test_hidden_columns_follow_sensitivity_permissions(): void
    {
        $retriever = $this->retriever();
        $po = $this->catalogue->find('purchase_orders');
        $employees = $this->catalogue->find('employees');

        $this->assertSame(['total_amount'], $retriever->hiddenColumns($this->user(['procurement.view']), $po));
        $this->assertSame([], $retriever->hiddenColumns($this->user(['procurement.view', 'finance.view']), $po));
        $this->assertSame(['phone'], $retriever->hiddenColumns($this->user(['hrms.view']), $employees));
        $this->assertSame([], $retriever->hiddenColumns($this->user(['hrms.manage']), $employees));
    }

    public function test_ranks_by_keyword_and_pulls_in_joined_tables(): void
    {
        $picked = $this->names($this->retriever()->forQuestion(
            $this->user(['procurement.view', 'hrms.view']),
            'how many purchase orders per supplier this month',
        ));

        $this->assertSame('purchase_orders', $picked[0]);
        $this->assertContains('vendors', $picked);
        $this->assertNotContains('employees', $picked);
    }

    public function test_previous_turn_tables_stay_in_scope(): void
    {
        $picked = $this->names($this->retriever()->forQuestion(
            $this->user(['procurement.view', 'hrms.view']),
            'and only the active ones',
            ['employees'],
        ));

        $this->assertContains('employees', $picked);
    }

    public function test_never_returns_a_table_the_user_may_not_see(): void
    {
        $picked = $this->retriever()->forQuestion($this->user(['hrms.view']), 'purchase orders per supplier', ['purchase_orders']);

        $this->assertSame([], array_filter($picked, static fn (TableSpec $spec) => $spec->module !== 'hrms'));
    }

    public function test_tokens_are_lowercased_stemmed_words(): void
    {
        $this->assertSame(['purchas', 'order', 'supplier'], SchemaRetriever::tokens('Purchase orders, per SUPPLIER!'));
    }
}
