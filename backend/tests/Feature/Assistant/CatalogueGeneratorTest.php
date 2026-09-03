<?php

namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Catalogue\CatalogueGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class CatalogueGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/catalogue-gen-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_writes_one_file_per_business_table_and_skips_framework_tables(): void
    {
        $result = app(CatalogueGenerator::class)->generate($this->dir);

        $this->assertFileExists($this->dir.'/employees.yaml');
        $this->assertFileExists($this->dir.'/purchase_orders.yaml');
        $this->assertFileDoesNotExist($this->dir.'/cache.yaml');
        $this->assertFileDoesNotExist($this->dir.'/migrations.yaml');
        $this->assertFileDoesNotExist($this->dir.'/permissions.yaml');
        $this->assertContains('employees', $result['created']);

        $employees = Yaml::parseFile($this->dir.'/employees.yaml');
        $names = array_column($employees['columns'], 'name');
        $this->assertContains('employee_code', $names);
        $this->assertSame('hrms', $employees['module']);
        $manager = collect($employees['columns'])->firstWhere('name', 'manager_id');
        $this->assertSame('employees.id', $manager['references']);
        $this->assertTrue($manager['nullable']);
        $this->assertContains('employees.manager_id = employees.id', $employees['joins']);
    }

    public function test_regeneration_keeps_annotations_and_adds_new_columns(): void
    {
        $generator = app(CatalogueGenerator::class);
        $generator->generate($this->dir);

        $data = Yaml::parseFile($this->dir.'/employees.yaml');
        $data['purpose'] = 'One person on the payroll.';
        $data['columns'] = array_values(array_filter($data['columns'], fn ($c) => $c['name'] !== 'phone'));
        $data['columns'][1]['meaning'] = 'the Pooja app ID';
        file_put_contents($this->dir.'/employees.yaml', Yaml::dump($data, 4, 2));

        $result = $generator->generate($this->dir);

        $again = Yaml::parseFile($this->dir.'/employees.yaml');
        $this->assertSame('One person on the payroll.', $again['purpose']);
        $this->assertSame('the Pooja app ID', $again['columns'][1]['meaning']);
        $this->assertContains('phone', array_column($again['columns'], 'name'));
        $this->assertContains('employees', $result['updated']);

        $third = $generator->generate($this->dir);
        $this->assertContains('employees', $third['unchanged']);
    }
}
