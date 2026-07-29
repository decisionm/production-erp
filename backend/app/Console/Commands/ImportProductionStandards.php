<?php

namespace App\Console\Commands;

use App\Modules\Production\Services\ProductionStandardImportService;
use Illuminate\Console\Command;

/**
 * Imports the factory product master. Dry run by default — writing is opt-in
 * via --write, so an accidental invocation reports rather than changes data.
 */
class ImportProductionStandards extends Command
{
    protected $signature = 'production:import-standards {file : JSON file of source rows} {--write : Actually write (default is a dry run)} {--exact-only : Skip unmatched and ambiguous rows}';

    protected $description = 'Import factory product-level production standards from a JSON row file';

    public function handle(ProductionStandardImportService $import): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error('File is not a JSON array of rows.');

            return self::FAILURE;
        }

        $result = $import->import($rows, ! $this->option('write'), null, (bool) $this->option('exact-only'));
        $s = $result['summary'];

        $this->info($result['dry_run'] ? 'DRY RUN — nothing written' : 'IMPORTED');
        $this->table(
            ['source rows', 'variants', 'matched', 'unmatched', 'unresolved', 'packaging', 'importable', 'skipped'],
            [[$s['source_rows'], $s['variants'], $s['matched'], $s['unmatched'], $s['unresolved'], $s['packaging_options'], $s['importable'], $s['skipped']]],
        );

        $unresolved = array_filter($result['variants'], fn ($v) => $v['status'] === 'unresolved');
        if ($unresolved !== []) {
            $this->warn('Unresolved variants (need a factory answer):');
            foreach ($unresolved as $v) {
                $this->line("  · {$v['source_product_name']} — {$v['unresolved_reason']}");
            }
        }

        return self::SUCCESS;
    }
}
