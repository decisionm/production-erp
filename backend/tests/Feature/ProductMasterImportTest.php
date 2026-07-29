<?php

namespace Tests\Feature;

use App\Console\Commands\ImportProductMasterXlsx;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionStandardImportService;
use Database\Seeders\LocalFactoryFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The xlsx -> normalised, versioned product-standard import.
 *
 * The count assertions below are the contract with the factory: 103 rows of
 * spreadsheet describe 76 products, which run as 90 distinct standard
 * variants packed 109 different ways, and 9 of those variants carry a
 * question only the factory can answer. If a future edit changes any of
 * those numbers, it changed what the factory can select on the floor.
 */
class ProductMasterImportTest extends TestCase
{
    use RefreshDatabase;

    /** Rows in the sheet's data range, B10:O112. */
    private const SOURCE_ROWS = 103;

    /** Distinct product names — how many products the sheet describes. */
    private const PRODUCT_FAMILIES = 76;

    /** Distinct product + cavities + weight + cycle time. */
    private const VARIANTS = 90;

    private const PACKAGING_CONFIGURATIONS = 109;

    /** Multi-valued or blank cells, split rather than guessed. */
    private const UNRESOLVED = 9;

    /** Sibling rows folded into a variant that already existed. */
    private const ROWS_MERGED = 20;

    private function service(): ProductionStandardImportService
    {
        return app(ProductionStandardImportService::class);
    }

    /**
     * The real workbook, via the JSON the python conversion writes.
     *
     * @return list<array<string, mixed>>
     */
    private function realRows(): array
    {
        // Prefer the committed fixture so CI actually RUNS these tests. The
        // storage/app copy is gitignored, so relying on it alone meant every
        // real-file assertion silently skipped on the server while passing
        // locally — coverage that looks green and proves nothing.
        $path = base_path('tests/fixtures/product-master-rows.json');

        if (! is_file($path)) {
            $path = storage_path(ImportProductMasterXlsx::DEFAULT_ROW_FILE);
        }

        if (! is_file($path)) {
            // The workbook is not in the repository, so this file cannot be
            // either. Skipping is honest; failing would punish a clean
            // checkout for something it was never given.
            $this->markTestSkipped(
                "Product master rows missing at {$path}. Regenerate with:\n"
                ."  python - <<'PY'\n"
                ."  import json, openpyxl\n"
                ."  ws = openpyxl.load_workbook('ERPPRO29072026.xlsx', data_only=True)['PRODUCT DETAILS - SOFTWARE  (2']\n"
                ."  cols = {'sl_no':1,'product':2,'cavities':3,'unit_weight_grams':4,'cycle_time':5,'nos_per_pouch':6,'pouch_nos_per_box':7,'nos_per_tray':9,'tray_nos_per_box':10}\n"
                ."  cell = lambda v: None if v is None else (str(int(v)) if isinstance(v, float) and v.is_integer() else (str(v) if isinstance(v, (int, float)) else (str(v).strip() or None)))\n"
                ."  rows = [{k: cell([ws.cell(row=r, column=c).value for c in range(1,16)][i]) for k, i in cols.items()} for r in range(10, 113)]\n"
                ."  json.dump(rows, open('storage/app/product-master-rows.json','w'), indent=1)\n"
                .'  PY',
            );
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) file_get_contents($path), true);

        return $rows;
    }

    // ---------------------------------------------------------------------
    // The real 103-row file
    // ---------------------------------------------------------------------

    public function test_the_real_workbook_normalises_to_the_expected_counts(): void
    {
        $result = $this->service()->import($this->realRows(), dryRun: true, createdBy: null);
        $summary = $result['summary'];

        $this->assertSame(self::SOURCE_ROWS, $summary['source_rows']);
        $this->assertSame(self::PRODUCT_FAMILIES, $summary['product_families']);
        $this->assertSame(self::VARIANTS, $summary['variants']);
        $this->assertSame(self::PACKAGING_CONFIGURATIONS, $summary['packaging_options']);
        $this->assertSame(self::UNRESOLVED, $summary['unresolved']);

        // 110 emissions (the multi-valued cells split into extras) minus 90
        // variants = 20 rows folded into a sibling. That is the pouch/tray
        // merge working, NOT a rejection — which is why it is counted apart
        // from duplicates_refused.
        $this->assertSame(self::ROWS_MERGED, $summary['rows_merged']);
        $this->assertSame(0, $summary['duplicates_refused']);
        $this->assertSame(0, $summary['packaging_conflicts']);
    }

    public function test_the_real_workbook_writes_the_expected_rows(): void
    {
        $this->service()->import($this->realRows(), dryRun: false, createdBy: null);

        $this->assertSame(self::VARIANTS, ProductionStandard::count());
        $this->assertSame(self::PACKAGING_CONFIGURATIONS, ProductionStandardPackaging::count());
        $this->assertSame(self::UNRESOLVED, ProductionStandard::where('status', 'unresolved')->count());
        $this->assertSame(
            self::PRODUCT_FAMILIES,
            ProductionStandard::query()->distinct()->count('source_product_name'),
        );
    }

    public function test_re_running_the_real_import_creates_zero_extra_rows(): void
    {
        $rows = $this->realRows();

        $this->service()->import($rows, dryRun: false, createdBy: null);
        $after = [ProductionStandard::count(), ProductionStandardPackaging::count(), Item::withTrashed()->count()];

        $this->service()->import($rows, dryRun: false, createdBy: null);
        $this->service()->import($rows, dryRun: false, createdBy: null);

        $this->assertSame(
            $after,
            [ProductionStandard::count(), ProductionStandardPackaging::count(), Item::withTrashed()->count()],
            'A second and third run must update in place, never add.',
        );
        $this->assertSame([self::VARIANTS, self::PACKAGING_CONFIGURATIONS, 0], $after);
    }

    public function test_re_running_the_real_import_with_local_fixtures_creates_zero_extra_rows(): void
    {
        $rows = $this->realRows();
        $service = $this->service();

        $first = $service->import($rows, dryRun: false, createdBy: null, exactOnly: false, createLocalFixtureItems: true);
        $this->assertSame(self::PRODUCT_FAMILIES, $first['summary']['local_fixture_items']);
        $after = [ProductionStandard::count(), ProductionStandardPackaging::count(), Item::withTrashed()->count()];

        $second = $service->import($rows, dryRun: false, createdBy: null, exactOnly: false, createLocalFixtureItems: true);

        // Nothing new was fabricated, because the deterministic SKU found
        // every item the first run made.
        $this->assertSame(0, $second['summary']['local_fixture_items']);
        $this->assertSame($after, [ProductionStandard::count(), ProductionStandardPackaging::count(), Item::withTrashed()->count()]);
        $this->assertSame([self::VARIANTS, self::PACKAGING_CONFIGURATIONS, self::PRODUCT_FAMILIES], $after);
    }

    public function test_nothing_from_the_real_workbook_is_ever_imported_as_approved(): void
    {
        $this->service()->import($this->realRows(), dryRun: false, createdBy: null);

        $this->assertSame(0, ProductionStandard::where('status', 'approved')->count());
        $this->assertSame(0, ProductionStandard::whereNotNull('approved_at')->count());
        $this->assertSame(0, ProductionStandard::whereNotNull('approved_by')->count());
        // Every row is one of the two unapproved states.
        $this->assertSame(
            ['draft', 'unresolved'],
            ProductionStandard::query()->distinct()->orderBy('status')->pluck('status')->all(),
        );
    }

    public function test_the_pouch_row_and_the_tray_row_of_one_standard_become_one_standard(): void
    {
        $this->service()->import($this->realRows(), dryRun: false, createdBy: null);

        // SL 4 (pouch) and SL 5 (tray) are the same bottle at the same
        // cavity, weight and cycle time — one variant, two options.
        $standard = ProductionStandard::where('source_product_name', '60ML ROUND')
            ->where('cavities', 5)->with('packagings')->firstOrFail();

        $this->assertSame(['pouch', 'tray'], $standard->packagings->pluck('mode')->sort()->values()->all());
        $this->assertSame(1225, $standard->packagings->firstWhere('mode', 'pouch')->nos_per_box);
        $this->assertSame(1150, $standard->packagings->firstWhere('mode', 'tray')->nos_per_box);
    }

    // ---------------------------------------------------------------------
    // Multi-valued cells
    // ---------------------------------------------------------------------

    public function test_a_multi_value_cell_splits_into_separate_variants_and_is_never_averaged(): void
    {
        // SL 72 verbatim: both the weight and the cycle time are two values
        // in one cell.
        $rows = [[
            'sl_no' => 72, 'product' => '500ML ROUND', 'cavities' => '3',
            'unit_weight_grams' => '36 / 31.5', 'cycle_time' => '21.5 / 17.8',
            'nos_per_pouch' => '83', 'pouch_nos_per_box' => '249',
        ]];

        $this->service()->import($rows, dryRun: false, createdBy: null);

        $standards = ProductionStandard::orderBy('unit_weight_grams')->orderBy('cycle_time')->get();

        // 2 weights x 2 cycle times = 4 real pairings to choose between.
        $this->assertCount(4, $standards);
        $this->assertSame(
            ['31.5000|17.80', '31.5000|21.50', '36.0000|17.80', '36.0000|21.50'],
            $standards->map(fn ($s) => $s->unit_weight_grams.'|'.$s->cycle_time)->all(),
        );

        // The averages — 33.75 g and 19.65 s — are a bottle nobody moulds
        // and a rate no machine runs at. Neither may exist.
        $this->assertSame(0, ProductionStandard::where('unit_weight_grams', 33.75)->count());
        $this->assertSame(0, ProductionStandard::where('cycle_time', 19.65)->count());

        foreach ($standards as $standard) {
            $this->assertSame('unresolved', $standard->status);
            $this->assertSame('21.5 / 17.8', $standard->cycle_time_raw);
            $this->assertStringContainsString('BOTH multi-valued', (string) $standard->unresolved_reason);
        }
    }

    public function test_only_genuinely_blank_values_are_flagged(): void
    {
        $rows = [
            // SL 97: the cycle time cell holds a single space. Genuinely blank.
            ['sl_no' => 97, 'product' => '200CC  (43MM NECK)', 'cavities' => '2', 'unit_weight_grams' => '19.5',
                'cycle_time' => null, 'nos_per_pouch' => '115', 'pouch_nos_per_box' => '575'],
            // SL 78: no pouch columns at all. Not a gap — it is tray-packed.
            ['sl_no' => 78, 'product' => '90ML RIB', 'cavities' => '7', 'unit_weight_grams' => '8.5',
                'cycle_time' => '12', 'nos_per_tray' => '132', 'tray_nos_per_box' => '1320'],
        ];

        $this->service()->import($rows, dryRun: false, createdBy: null);

        $blank = ProductionStandard::where('source_product_name', '200CC  (43MM NECK)')->firstOrFail();
        $this->assertSame('unresolved', $blank->status);
        $this->assertStringContainsString('Cycle time is blank', (string) $blank->unresolved_reason);

        $trayOnly = ProductionStandard::where('source_product_name', '90ML RIB')->with('packagings')->firstOrFail();
        $this->assertSame('draft', $trayOnly->status);
        $this->assertNull($trayOnly->unresolved_reason);
        $this->assertTrue($trayOnly->packagings->first()->is_default);
    }

    // ---------------------------------------------------------------------
    // Duplicates
    // ---------------------------------------------------------------------

    public function test_a_repeated_packaging_mode_is_refused_and_a_conflicting_one_is_flagged(): void
    {
        $base = ['product' => 'TEST BOTTLE', 'cavities' => '4', 'unit_weight_grams' => '18', 'cycle_time' => '16.5'];

        $result = $this->service()->import([
            $base + ['sl_no' => 1, 'nos_per_pouch' => '100', 'pouch_nos_per_box' => '500'],
            // Byte-identical repeat: refused, nothing to argue about.
            $base + ['sl_no' => 2, 'nos_per_pouch' => '100', 'pouch_nos_per_box' => '500'],
            // Same variant, same mode, DIFFERENT figures: refused and flagged.
            $base + ['sl_no' => 3, 'nos_per_pouch' => '120', 'pouch_nos_per_box' => '600'],
        ], dryRun: false, createdBy: null);

        $this->assertSame(1, $result['summary']['variants']);
        $this->assertSame(2, $result['summary']['duplicates_refused']);
        $this->assertSame(1, $result['summary']['packaging_conflicts']);
        // Merging is for rows contributing a NEW mode; these contributed
        // none, so they are refusals and nothing else. Counting them as
        // merges too would report a rejection as the feature working.
        $this->assertSame(0, $result['summary']['rows_merged']);

        $standard = ProductionStandard::with('packagings')->firstOrFail();
        $this->assertCount(1, $standard->packagings);

        // The first arrival stands — a later row does not silently overwrite
        // an earlier one.
        $this->assertSame(100, $standard->packagings->first()->nos_per_pouch);
        $this->assertSame(500, $standard->packagings->first()->nos_per_box);

        $this->assertSame('unresolved', $standard->status);
        $this->assertStringContainsString('Row 3 repeats the pouch packaging', (string) $standard->unresolved_reason);
        $this->assertStringContainsString('nos_per_pouch=120', (string) $standard->unresolved_reason);
    }

    // ---------------------------------------------------------------------
    // Local fixtures vs production map-only
    // ---------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function twoFamilies(): array
    {
        return [
            ['sl_no' => 4, 'product' => '60ML ROUND', 'cavities' => '5', 'unit_weight_grams' => '10',
                'cycle_time' => '10.8', 'nos_per_pouch' => '245', 'pouch_nos_per_box' => '1225'],
            ['sl_no' => 96, 'product' => '1000ML ROUND / IFF', 'cavities' => '2', 'unit_weight_grams' => '60',
                'cycle_time' => '30.5'],
        ];
    }

    public function test_local_fixture_mode_creates_clearly_marked_local_items(): void
    {
        $result = $this->service()->import(
            $this->twoFamilies(), dryRun: false, createdBy: null, exactOnly: false, createLocalFixtureItems: true,
        );

        $this->assertSame(2, $result['summary']['local_fixture_items']);
        $this->assertSame(2, Item::count());

        foreach (Item::all() as $item) {
            // Four independent markers. An item that quietly looked real
            // would be the worst thing this mode could produce.
            $this->assertStringStartsWith('LOCAL-', (string) $item->sku);
            $this->assertStringEndsWith('(LOCAL FIXTURE)', (string) $item->name);
            $this->assertStringContainsString('LOCAL TEST FIXTURE', (string) $item->description);
            $this->assertNull($item->tally_stock_item_guid);
            $this->assertFalse($item->isTallySourced());
        }

        // Deterministic, and slashes in the factory name do not leak in.
        $this->assertNotNull(Item::where('sku', 'LOCAL-60ML-ROUND')->first());
        $this->assertNotNull(Item::where('sku', 'LOCAL-1000ML-ROUND-IFF')->first());

        // The whole point: every standard is attached to a runnable item.
        $this->assertSame(2, ProductionStandard::whereNotNull('item_id')->count());
    }

    public function test_local_fixture_mode_reuses_a_real_catalogue_item_rather_than_fabricating_one(): void
    {
        $real = Item::create([
            'sku' => 'BTL-60-RND', 'name' => '60ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-60-round',
        ]);

        $result = $this->service()->import(
            $this->twoFamilies(), dryRun: false, createdBy: null, exactOnly: false, createLocalFixtureItems: true,
        );

        // Only the family the catalogue could not answer for is fabricated.
        $this->assertSame(1, $result['summary']['local_fixture_items']);
        $this->assertSame(0, Item::where('sku', 'LOCAL-60ML-ROUND')->count());

        $standard = ProductionStandard::where('source_product_name', '60ML ROUND')->firstOrFail();
        $this->assertSame($real->id, $standard->item_id);
    }

    public function test_a_dry_run_with_local_fixtures_fabricates_nothing(): void
    {
        $result = $this->service()->import(
            $this->twoFamilies(), dryRun: true, createdBy: null, exactOnly: false, createLocalFixtureItems: true,
        );

        // Reported as what WOULD be created; the database is untouched.
        $this->assertSame(2, $result['summary']['local_fixture_items']);
        $this->assertSame(0, Item::withTrashed()->count());
        $this->assertSame(0, ProductionStandard::count());
    }

    public function test_production_mode_creates_no_items_at_all(): void
    {
        $before = Item::withTrashed()->count();

        $result = $this->service()->import($this->realRows(), dryRun: false, createdBy: null);

        // Map-only. An unmatched standard is visible work; an invented item
        // is a lie that looks like master data.
        $this->assertSame($before, Item::withTrashed()->count());
        $this->assertSame(0, Item::withTrashed()->where('sku', 'like', 'LOCAL-%')->count());
        $this->assertSame(0, $result['summary']['local_fixture_items']);
        $this->assertSame(self::VARIANTS, $result['summary']['unmatched']);
        // Imported anyway, unmatched, so the mapping work is visible.
        $this->assertSame(self::VARIANTS, ProductionStandard::whereNull('item_id')->count());
    }

    public function test_production_mode_matches_an_existing_item_without_creating_one(): void
    {
        Item::create([
            'sku' => 'BTL-60-RND', 'name' => '  60ml   round ', 'uom' => 'Nos.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-60-round',
        ]);

        $this->service()->import($this->twoFamilies(), dryRun: false, createdBy: null);

        // Case and whitespace are normalised; nothing fuzzier than that.
        $this->assertSame(1, Item::withTrashed()->count());
        $this->assertSame(1, ProductionStandard::whereNotNull('item_id')->count());
        $this->assertSame(1, ProductionStandard::whereNull('item_id')->count());
    }

    // ---------------------------------------------------------------------
    // Command and seeder
    // ---------------------------------------------------------------------

    public function test_the_command_is_a_dry_run_unless_write_is_passed(): void
    {
        // Deliberately NOT the real workbook: whether writing is opt-in is a
        // property of the command, and this assertion must not break the day
        // the factory sends a corrected sheet.
        $path = tempnam(sys_get_temp_dir(), 'product-master-').'.json';
        file_put_contents($path, json_encode($this->twoFamilies()));

        try {
            $this->artisan('production:import-product-master', ['--json' => $path])->assertExitCode(0);
            $this->assertSame(0, ProductionStandard::count(), 'Dry run is the default; writing is opt-in.');

            $this->artisan('production:import-product-master', ['--json' => $path, '--write' => true])->assertExitCode(0);
            $this->assertSame(2, ProductionStandard::count());
            $this->assertSame(0, Item::withTrashed()->count(), 'Production mode never creates items.');

            // --local-fixtures is the only way an item is ever fabricated.
            $this->artisan('production:import-product-master', [
                '--json' => $path, '--write' => true, '--local-fixtures' => true,
            ])->assertExitCode(0);
            $this->assertSame(2, Item::where('sku', 'like', 'LOCAL-%')->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_the_command_imports_the_real_workbook_when_pointed_at_it(): void
    {
        $this->realRows(); // Skips the test if the converted rows are absent.

        $this->artisan('production:import-product-master', [
            // Resolver, not the raw storage path: on a clean checkout (every
            // CI run) storage/app is empty and only the committed fixture
            // exists.
            '--json' => ImportProductMasterXlsx::resolveRowFile(),
            '--write' => true,
        ])->assertExitCode(0);

        $this->assertSame(self::VARIANTS, ProductionStandard::count());
        $this->assertSame(0, Item::withTrashed()->count(), 'Production mode never creates items.');
    }

    public function test_the_command_fails_loudly_on_a_missing_row_file(): void
    {
        $this->artisan('production:import-product-master', ['--json' => '/nope/not-here.json'])
            ->assertExitCode(1);

        $this->assertSame(0, ProductionStandard::count());
    }

    public function test_the_local_fixture_seeder_makes_the_whole_master_runnable_on_machine_one_to_ten(): void
    {
        $this->realRows(); // Skips the test if the converted rows are absent.

        $this->seed(LocalFactoryFixtureSeeder::class);

        // Ten canonical machines, and no machine-specific mapping needed —
        // the master carries no machine column.
        $this->assertSame(10, WorkCenter::where('is_active', true)->whereIn('code', array_map(
            fn (int $n) => sprintf('MC-%02d', $n), range(1, 10),
        ))->count());

        $this->assertSame(self::VARIANTS, ProductionStandard::count());
        $this->assertSame(self::PACKAGING_CONFIGURATIONS, ProductionStandardPackaging::count());
        // Every standard resolves to an item, so any of them can be started.
        $this->assertSame(0, ProductionStandard::whereNull('item_id')->count());
        $this->assertSame(self::PRODUCT_FAMILIES, Item::where('sku', 'like', 'LOCAL-%')->count());

        // Still nothing approved: a fixture is a rehearsal, not a decision.
        $this->assertSame(0, ProductionStandard::where('status', 'approved')->count());
    }

    public function test_the_local_fixture_seeder_is_idempotent(): void
    {
        $this->realRows(); // Skips the test if the converted rows are absent.

        $this->seed(LocalFactoryFixtureSeeder::class);
        $after = [ProductionStandard::count(), ProductionStandardPackaging::count(), Item::withTrashed()->count(), WorkCenter::count()];

        $this->seed(LocalFactoryFixtureSeeder::class);
        $this->seed(LocalFactoryFixtureSeeder::class);

        $this->assertSame($after, [ProductionStandard::count(), ProductionStandardPackaging::count(), Item::withTrashed()->count(), WorkCenter::count()]);
    }
}
