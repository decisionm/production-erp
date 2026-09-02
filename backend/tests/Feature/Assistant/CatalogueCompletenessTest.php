<?php

namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Catalogue\CatalogueGenerator;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\SensitiveColumns;
use App\Modules\Core\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE CATALOGUE IS COMPLETE OR THE BUILD IS RED. A table with no file is a
 * table the assistant cannot answer about; a column with no meaning is one
 * the model guesses at; a module not in the permission catalogue is a table
 * nobody could ever be allowed to read. Run `php artisan
 * schema:catalogue:generate` after a migration and annotate what it added.
 */
class CatalogueCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_business_table_has_an_annotated_file(): void
    {
        $catalogue = SchemaCatalogue::fromDirectory(resource_path('schema-catalogue'));
        $modules = array_keys(PermissionService::MODULES);
        $problems = [];

        foreach (Schema::getTableListing() as $table) {
            if (in_array($table, CatalogueGenerator::FRAMEWORK_TABLES, true)) {
                continue;
            }
            $spec = $catalogue->find($table);
            if ($spec === null) {
                $problems[] = "{$table}: no catalogue file";

                continue;
            }
            if (trim($spec->purpose) === '') {
                $problems[] = "{$table}: no purpose";
            }
            if (! in_array($spec->module, $modules, true)) {
                $problems[] = "{$table}: module {$spec->module} is not in the permission catalogue";
            }

            $dbColumns = array_column(Schema::getColumns($table), 'name');
            sort($dbColumns);
            $fileColumns = $spec->columnNames();
            sort($fileColumns);
            if ($dbColumns !== $fileColumns) {
                $problems[] = "{$table}: columns drifted from the database (run schema:catalogue:generate)";
            }

            foreach ($spec->columns as $column) {
                if ($column->meaning === null) {
                    $problems[] = "{$table}.{$column->name}: no meaning";
                }
                if ($column->sensitive !== null && ! array_key_exists($column->sensitive, SensitiveColumns::KINDS)) {
                    $problems[] = "{$table}.{$column->name}: unknown sensitivity {$column->sensitive}";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_no_file_names_a_table_the_database_does_not_have(): void
    {
        $catalogue = SchemaCatalogue::fromDirectory(resource_path('schema-catalogue'));
        $tables = Schema::getTableListing();

        $orphans = array_values(array_diff(array_keys($catalogue->all()), $tables));

        $this->assertSame([], $orphans, 'Catalogue files without a table: '.implode(', ', $orphans));
    }
}
