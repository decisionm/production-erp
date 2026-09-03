<?php

namespace App\Console\Commands;

use App\Modules\Assistant\Catalogue\CatalogueGenerator;
use Illuminate\Console\Command;

/**
 * Writes or refreshes one YAML per business table for Ask ERP. Re-run after
 * any migration that adds or drops a column: CatalogueCompletenessTest fails
 * the build until the files match the database again. Hand-written
 * annotations survive the refresh.
 */
class GenerateSchemaCatalogue extends Command
{
    protected $signature = 'schema:catalogue:generate {--path= : Directory to write into (default: config ask-erp.catalogue_path)}';

    protected $description = 'Write or refresh one YAML per business table for Ask ERP, keeping hand-written annotations';

    public function handle(CatalogueGenerator $generator): int
    {
        $dir = (string) ($this->option('path') ?: config('ask-erp.catalogue_path'));
        $result = $generator->generate($dir);

        $this->info(sprintf(
            '%d created, %d updated, %d unchanged in %s',
            count($result['created']),
            count($result['updated']),
            count($result['unchanged']),
            $dir,
        ));

        return self::SUCCESS;
    }
}
