<?php

namespace Database\Seeders;

use App\Console\Commands\ImportProductMasterXlsx;
use App\Modules\Production\Services\ProductionStandardImportService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The whole factory product master, runnable on the ten canonical machines,
 * today — without waiting for the Tally catalogue to be mapped.
 *
 * Everything the master describes but the catalogue does not yet carry is
 * fabricated as a LOCAL fixture item: SKU prefixed `LOCAL-`, name suffixed
 * `(LOCAL FIXTURE)`, a description that says so in words, and no Tally GUID.
 * Four independent markers, because an item that quietly looked real would
 * be the single worst thing this seeder could produce.
 *
 * LOCAL ONLY. Refuses to run in the production environment and is never
 * invoked by deploy.sh — production maps standards onto real Tally items via
 * `production:import-product-master` with no `--local-fixtures`, which
 * cannot create an item at all.
 *
 * Idempotent throughout: items are matched on their deterministic SKU and
 * standards on their variant key, so running it twice changes nothing.
 *
 * The 103 workbook rows are NOT hardcoded here. They are read from the JSON
 * the xlsx conversion writes — see the docblock on
 * {@see ImportProductMasterXlsx} for how to regenerate it. A seeder carrying
 * a copy of the factory's figures would go stale the first time the factory
 * corrected one, and nothing would say so.
 */
class LocalFactoryFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'LocalFactoryFixtureSeeder fabricates items and must never run in production.',
            );
        }

        $this->call(CanonicalMachineSeeder::class);

        $path = storage_path(ImportProductMasterXlsx::DEFAULT_ROW_FILE);

        if (! is_file($path)) {
            // Loud, not silent: seeding nothing while reporting success is
            // how a "seeded" environment ends up with ten machines and no
            // products, and nobody finds out until the first batch.
            throw new RuntimeException(
                "Product master rows not found at {$path}. Regenerate them from the workbook — see the docblock on ".ImportProductMasterXlsx::class.'.',
            );
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException("Product master rows at {$path} are not a non-empty JSON array.");
        }

        $result = app(ProductionStandardImportService::class)->import(
            $rows,
            dryRun: false,
            createdBy: null,
            // Unmatched and ambiguous variants are wanted here: the point of
            // a local fixture is to rehearse against the master as it
            // actually is, ambiguities included.
            exactOnly: false,
            createLocalFixtureItems: true,
        );

        $s = $result['summary'];

        $this->command?->info(sprintf(
            '%d source rows → %d product families, %d standard variants, %d packaging configurations '
            .'(%d unresolved, %d rows merged, %d duplicates refused, %d LOCAL- items created).',
            $s['source_rows'],
            $s['product_families'],
            $s['variants'],
            $s['packaging_options'],
            $s['unresolved'],
            $s['rows_merged'],
            $s['duplicates_refused'],
            $s['local_fixture_items'],
        ));
    }
}
