<?php

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\SensitiveColumns;
use PHPUnit\Framework\TestCase;

class SchemaCatalogueTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/catalogue-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/vendors.yaml', <<<'YAML'
table: vendors
module: procurement
label: Vendors
purpose: One supplier the factory buys from.
columns:
  - name: id
    type: bigint
    meaning: primary key
  - name: name
    type: string
    meaning: supplier name
    sensitive: supplier-identity
  - name: state_code
    type: string
    nullable: true
    meaning: GST state code
joins:
  - purchase_orders.vendor_id = vendors.id
keywords: [supplier, party]
questions:
  - How many vendors are active?
YAML);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
    }

    public function test_loads_a_table_spec_from_yaml(): void
    {
        $catalogue = SchemaCatalogue::fromDirectory($this->dir);
        $vendors = $catalogue->find('vendors');

        $this->assertSame('procurement', $vendors->module);
        $this->assertSame(['id', 'name', 'state_code'], $vendors->columnNames());
        $this->assertSame(['name' => 'supplier-identity'], $vendors->sensitiveColumns());
        $this->assertTrue($vendors->columns[2]->nullable);
        $this->assertSame(['purchase_orders'], $vendors->joinedTables());
        $this->assertNull($catalogue->find('nope'));
    }

    public function test_render_omits_hidden_columns(): void
    {
        $spec = SchemaCatalogue::fromDirectory($this->dir)->find('vendors');
        $text = $spec->render(['name']);

        $this->assertStringContainsString('vendors (procurement): One supplier', $text);
        $this->assertStringContainsString('state_code string nullable — GST state code', $text);
        $this->assertStringNotContainsString('supplier name', $text);
        $this->assertStringContainsString('joins: purchase_orders.vendor_id = vendors.id', $text);
    }

    public function test_sensitive_kinds_map_to_permissions(): void
    {
        $this->assertSame(['carton-trace.view', 'carton-trace.manage', 'finance.view', 'finance.manage'], SensitiveColumns::permissionsFor('rates'));
        $this->assertSame(['procurement.view', 'procurement.manage'], SensitiveColumns::permissionsFor('supplier-identity'));
        $this->assertSame(['payroll.view', 'payroll.manage'], SensitiveColumns::permissionsFor('pay'));
        $this->assertSame(['hrms.manage'], SensitiveColumns::permissionsFor('personal'));
        $this->assertSame([], SensitiveColumns::permissionsFor('unknown'));
    }
}
