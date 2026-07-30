<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds machine–product configurations from what the factory ACTUALLY RAN.
 *
 * ## Where the data comes from
 *
 * The factory's own Daily Production Review sheets, 12–26 July 2026: 464 shift
 * records giving 46 distinct (machine, product, colour, cavities) combinations,
 * each with the cycle-time range genuinely observed on that machine. This is
 * the machine–product mapping the workbook master could never provide — the
 * workbook has no machine column, which is why the Configuration page has sat
 * empty and Start Batch says "No approved machine–product mapping yet".
 *
 * The conversion from the sheet, and the observation→item name matching (size
 * + shape + colour + exact weight, bidirectional, refusing ambiguity), happen
 * offline; this command consumes the reviewed JSON, exactly like the product
 * master import. Four observations were refused at that stage and travel in the
 * file's `skipped` list so they are reported, not forgotten: three colours
 * Tally has no item for (120ml Black, 200ml Gold and Yellow) and one row
 * against a machine 11 that does not exist.
 *
 * ## Everything lands as DRAFT, and drafts are inert
 *
 * ProductionConfigurationService::resolve() filters on status=approved, so
 * nothing imported here changes a single run until a person approves the row.
 * That is the point: these are observations offered for approval, not
 * decisions. An import must not approve on anyone's behalf — the same rule the
 * product-master import follows.
 *
 * For the same reason, a row that already exists with any status other than
 * draft is NEVER touched. Someone approved or retired it deliberately, and a
 * re-import does not get to argue.
 *
 * ## Row identity
 *
 * (machine, item, colour, cavities) — cavities included because the same
 * product genuinely runs at different cavity counts on the same machine
 * (Machine 4 ran 60ml Round Amber at 4 AND 5 cavities in July; Machine 10 ran
 * 500ml Round at 3 and 7). Those are different setups with different expected
 * outputs, and collapsing them would hide exactly the choice a supervisor has
 * to make.
 *
 * Dry run unless --write.
 */
class ImportMachineConfigurations extends Command
{
    public const DEFAULT_ROW_FILE = 'app/observed-machine-configurations.json';

    public const FIXTURE_ROW_FILE = 'tests/fixtures/observed-machine-configurations.json';

    protected $signature = 'production:import-machine-configurations
        {--json= : JSON file (default: storage/'.self::DEFAULT_ROW_FILE.', falling back to the committed fixture)}
        {--write : Actually write (default is a dry run)}';

    protected $description = 'Seed draft machine–product configurations from the factory\'s daily production review sheets';

    public function handle(): int
    {
        $path = $this->option('json')
            ?: (is_file(storage_path(self::DEFAULT_ROW_FILE)) ? storage_path(self::DEFAULT_ROW_FILE) : base_path(self::FIXTURE_ROW_FILE));

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $rows = $data['configurations'] ?? null;

        if (! is_array($rows) || $rows === []) {
            $this->error("No configurations in: {$path}");

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $preserved = [];
        $unresolved = [];

        $apply = function () use ($rows, &$created, &$updated, &$unchanged, &$preserved, &$unresolved) {
            foreach ($rows as $row) {
                $machine = WorkCenter::query()->where('name', 'Machine '.$row['machine'])->first();
                if ($machine === null) {
                    $unresolved[] = "Machine {$row['machine']} — no work centre with that name";

                    continue;
                }

                $item = Item::query()->where('name', $row['item'])->first();
                if ($item === null) {
                    $unresolved[] = "{$row['item']} — no item with that exact name in this catalogue";

                    continue;
                }

                $config = ProductionConfiguration::query()->firstOrNew([
                    'work_center_id' => $machine->id,
                    'item_id' => $item->id,
                    'colour' => $row['colour'],
                    'default_cavities' => $row['cavities'],
                ]);

                if ($config->exists && $config->status !== ConfigurationStatus::Draft) {
                    // A person approved or retired this row. Their decision
                    // outranks a re-import, always.
                    $preserved[] = "Machine {$row['machine']} · {$row['item']} (status {$config->status->value})";

                    continue;
                }

                $config->fill([
                    'unit_weight_grams' => (string) $row['unit_weight_grams'],
                    'default_cycle_time' => $row['cycle_time_median'] !== null ? (string) $row['cycle_time_median'] : null,
                    'cycle_time_min' => $row['cycle_time_min'] !== null ? (string) $row['cycle_time_min'] : null,
                    'cycle_time_max' => $row['cycle_time_max'] !== null ? (string) $row['cycle_time_max'] : null,
                    'status' => ConfigurationStatus::Draft,
                    'source' => 'DAILY-PRODUCTION-REVIEW',
                    'source_reference' => $row['first_seen'].' – '.$row['last_seen'],
                    'confirmation_status' => 'Observed, awaiting approval',
                    'notes' => sprintf(
                        'Observed over %d shift record(s), %s to %s. Cycle time seen %s–%s s (median %s). Sheet name(s): %s.',
                        $row['shifts_observed'],
                        $row['first_seen'],
                        $row['last_seen'],
                        $row['cycle_time_min'] ?? '?',
                        $row['cycle_time_max'] ?? '?',
                        $row['cycle_time_median'] ?? '?',
                        implode(', ', $row['sheet_names']),
                    ),
                ]);

                if (! $config->exists) {
                    $created++;
                } elseif ($config->isDirty()) {
                    $updated++;
                } else {
                    $unchanged++;
                }

                $config->save();
            }
        };

        if ($this->option('write')) {
            DB::transaction($apply);
        } else {
            // Dry run inside a transaction that is always thrown away, so the
            // counts reported are exactly what a real run would do.
            DB::beginTransaction();

            try {
                $apply();
            } finally {
                DB::rollBack();
            }
        }

        $this->info($this->option('write') ? 'IMPORTED' : 'DRY RUN — nothing written');
        $this->newLine();

        $this->table(['count', 'value'], [
            ['configurations in file', count($rows)],
            ['created as draft', $created],
            ['existing drafts refreshed', $updated],
            ['already identical', $unchanged],
            ['preserved (approved/inactive — never touched)', count($preserved)],
            ['could not resolve machine or item', count($unresolved)],
            ['refused at matching time (in the file)', count($data['skipped'] ?? [])],
        ]);

        foreach ($preserved as $line) {
            $this->line('  kept as-is: '.$line);
        }

        if ($unresolved !== []) {
            $this->newLine();
            $this->warn('Could not resolve on this database:');
            foreach ($unresolved as $line) {
                $this->line('  · '.$line);
            }
        }

        if (($data['skipped'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Refused when the observations were matched to items (carried in the file for the record):');
            foreach ($data['skipped'] as $skip) {
                $this->line(sprintf(
                    '  · M%s %s %s — %s',
                    $skip['machine'],
                    $skip['product'],
                    $skip['colour'],
                    $skip['reason'],
                ));
            }
        }

        $this->newLine();
        $this->line('Every imported row is DRAFT. Drafts never affect a run — approve them on the Configuration page.');

        return self::SUCCESS;
    }
}
