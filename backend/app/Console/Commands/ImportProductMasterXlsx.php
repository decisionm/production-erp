<?php

namespace App\Console\Commands;

use App\Modules\Production\Services\ProductionStandardImportService;
use Illuminate\Console\Command;

/**
 * Imports the factory's product master workbook (ERPPRO29072026.xlsx) as
 * versioned, normalised production standards.
 *
 * ## Why the workbook is not read here
 *
 * There is no xlsx reader in this project's composer.json and one is not
 * being added: a spreadsheet parser is a large dependency with a large
 * attack surface, pulled in to serve a step that runs once when the factory
 * sends a new master. The conversion happens out of band, in python, and
 * this command consumes the JSON it produces:
 *
 *     # one-off, from a venv with openpyxl installed
 *     python - <<'PY'
 *     import json, openpyxl
 *     ws = openpyxl.load_workbook('ERPPRO29072026.xlsx', data_only=True)['PRODUCT DETAILS - SOFTWARE  (2']
 *     cols = {'sl_no':1,'product':2,'cavities':3,'unit_weight_grams':4,'cycle_time':5,
 *             'nos_per_pouch':6,'pouch_nos_per_box':7,'nos_per_tray':9,'tray_nos_per_box':10}
 *     def cell(v):
 *         if v is None: return None
 *         if isinstance(v, float) and v.is_integer(): return str(int(v))
 *         if isinstance(v, (int, float)): return str(v)
 *         return str(v).strip() or None
 *     rows = []
 *     for r in range(10, 113):                      # data rows B10:O112
 *         t = [ws.cell(row=r, column=c).value for c in range(1, 16)]
 *         rows.append({k: cell(t[i]) for k, i in cols.items()})
 *     json.dump(rows, open('storage/app/product-master-rows.json','w'), indent=1)
 *     PY
 *
 * Cells are copied VERBATIM. "18/20" and "21.5 / 17.8" must survive into the
 * JSON whole so the importer can split them into separate unresolved
 * variants; collapsing them during conversion would destroy the ambiguity
 * before anyone gets to see it. Whitespace-only cells become null, because
 * only genuinely blank values are meant to be flagged.
 *
 * ## Relationship to `production:import-standards`
 *
 * That command stays; this is not a second import path and not a second
 * normalisation engine. Both call the one
 * {@see ProductionStandardImportService}. The difference is operational:
 * `production:import-standards` takes an arbitrary JSON path and is the
 * general-purpose entry point, while this command is the pipeline for THIS
 * workbook — it defaults to the file the conversion above writes, documents
 * that conversion, reports the normalisation counts the factory asked for,
 * and carries `--local-fixtures`, which nothing else may.
 *
 * ## Defaults
 *
 * Dry run unless `--write` is passed, so an accidental invocation reports
 * rather than changes data.
 *
 * `--exact-only` is OFF by default. `production_standards.item_id` is
 * nullable precisely so an unmatched row is imported and visible as work to
 * do rather than silently dropped; turn it on when writing to an instance
 * whose Tally catalogue is authoritative and a half-mapped standard would be
 * worse than a missing one.
 */
class ImportProductMasterXlsx extends Command
{
    public const DEFAULT_ROW_FILE = 'app/product-master-rows.json';

    protected $signature = 'production:import-product-master
        {--json= : JSON row file (default: storage/'.self::DEFAULT_ROW_FILE.')}
        {--write : Actually write (default is a dry run)}
        {--exact-only : Write only variants matched to exactly one item and free of ambiguity}
        {--local-fixtures : LOCAL ONLY — fabricate a "LOCAL-" item for every unmatched product}';

    protected $description = 'Import the factory product master (converted to JSON) as normalised production standards';

    public function handle(ProductionStandardImportService $import): int
    {
        $path = $this->option('json') ?: storage_path(self::DEFAULT_ROW_FILE);

        if (! is_file($path)) {
            $this->error("No such file: {$path}");
            $this->line('Convert the workbook first — see the docblock on '.static::class.'.');

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows) || $rows === []) {
            $this->error("Not a non-empty JSON array of rows: {$path}");

            return self::FAILURE;
        }

        $localFixtures = (bool) $this->option('local-fixtures');

        if ($localFixtures && app()->environment('production')) {
            // Fabricated items in a live catalogue would be indistinguishable
            // from master data to everyone except whoever remembered running
            // this. Refuse rather than warn.
            $this->error('--local-fixtures is refused in the production environment: it fabricates items.');

            return self::FAILURE;
        }

        $result = $import->import(
            $rows,
            ! $this->option('write'),
            null,
            (bool) $this->option('exact-only'),
            $localFixtures,
        );
        $s = $result['summary'];

        $this->info($result['dry_run'] ? 'DRY RUN — nothing written' : 'IMPORTED');
        $this->newLine();

        $this->table(['count', 'value'], [
            ['source rows', $s['source_rows']],
            ['product families (distinct names)', $s['product_families']],
            ['standard variants', $s['variants']],
            ['packaging configurations', $s['packaging_options']],
            ['unresolved variants', $s['unresolved']],
            ['rows merged into an existing variant', $s['rows_merged']],
            ['duplicates refused', $s['duplicates_refused']],
            ['  of which conflicting figures', $s['packaging_conflicts']],
            ['matched to an item', $s['matched']],
            ['unmatched', $s['unmatched']],
            ['LOCAL- fixture items'.($result['dry_run'] ? ' (would create)' : ' created'), $s['local_fixture_items']],
            ['importable', $s['importable']],
            ['skipped', $s['skipped']],
        ]);

        $unresolved = array_filter($result['variants'], fn ($v) => $v['status'] === 'unresolved');
        if ($unresolved !== []) {
            $this->newLine();
            $this->warn('Unresolved variants (need a factory answer):');
            foreach ($unresolved as $v) {
                $this->line("  · {$v['source_product_name']} — {$v['unresolved_reason']}");
            }
        }

        return self::SUCCESS;
    }
}
