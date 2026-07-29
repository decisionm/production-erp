<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionStandardImportService;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Importing the factory product master (ERPPRO29072026.xlsx) and running
 * against it in watch mode.
 *
 * Every fixture here is a real row from that workbook.
 */
class ProductionStandardImportTest extends TestCase
{
    use RefreshDatabase;

    /** Real rows: 60ML ROUND appears four times — two cavity variants x pouch/tray. */
    private function factoryRows(): array
    {
        return [
            ['sl_no' => 4, 'product' => '60ML ROUND', 'cavities' => 5, 'unit_weight_grams' => 10, 'cycle_time' => 10.8,
                'nos_per_pouch' => 245, 'pouch_nos_per_box' => 1225],
            ['sl_no' => 5, 'product' => '60ML ROUND', 'cavities' => 5, 'unit_weight_grams' => 10, 'cycle_time' => 10.8,
                'nos_per_tray' => 230, 'tray_nos_per_box' => 1150],
            ['sl_no' => 6, 'product' => '60ML ROUND', 'cavities' => 6, 'unit_weight_grams' => 10.5, 'cycle_time' => 11.6,
                'nos_per_pouch' => 245, 'pouch_nos_per_box' => 1225],
            ['sl_no' => 7, 'product' => '60ML ROUND', 'cavities' => 6, 'unit_weight_grams' => 10.5, 'cycle_time' => 11.6,
                'nos_per_tray' => 230, 'tray_nos_per_box' => 1150],
            // Cavity 7, tray only.
            ['sl_no' => 20, 'product' => '90ML RIB', 'cavities' => 7, 'unit_weight_grams' => 8.5, 'cycle_time' => 12,
                'nos_per_tray' => 208, 'tray_nos_per_box' => 1040],
            // Multi-valued cycle time — must split, never average.
            ['sl_no' => 60, 'product' => '500ML ROUND', 'cavities' => 3, 'unit_weight_grams' => null, 'cycle_time' => '21.5 / 17.8',
                'nos_per_pouch' => 60, 'pouch_nos_per_box' => 300],
            // Above the old 14 s "global maximum" — must import cleanly.
            ['sl_no' => 99, 'product' => '1000ML ROUND / IFF', 'cavities' => 2, 'unit_weight_grams' => 40, 'cycle_time' => 30.5,
                'nos_per_tray' => 40, 'tray_nos_per_box' => 160],
        ];
    }

    private function import(bool $write = true): array
    {
        return app(ProductionStandardImportService::class)->import($this->factoryRows(), ! $write, null);
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'production.view'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_pouch_and_tray_rows_of_one_standard_merge_into_one_variant(): void
    {
        $this->import();

        // Four source rows for 60ML ROUND become TWO variants (cavity 5 and
        // cavity 6), each with both packaging options — not four standards,
        // and not keyed on packaging mode.
        $variants = ProductionStandard::where('source_product_name', '60ML ROUND')->with('packagings')->get();
        $this->assertCount(2, $variants);

        foreach ($variants as $variant) {
            $modes = $variant->packagings->pluck('mode')->sort()->values()->all();
            $this->assertSame(['pouch', 'tray'], $modes);
        }
    }

    public function test_cavity_six_and_seven_import_and_behave_like_any_other_cavity(): void
    {
        // 6 and 7 are CAVITY COUNTS. Nothing about them is special and
        // nothing associates them with Machine 6 or Machine 7.
        $this->import();

        $cav6 = ProductionStandard::where('source_product_name', '60ML ROUND')->where('cavities', 6)->first();
        $cav7 = ProductionStandard::where('source_product_name', '90ML RIB')->where('cavities', 7)->first();

        $this->assertNotNull($cav6);
        $this->assertNotNull($cav7);
        $this->assertSame('11.60', (string) $cav6->cycle_time);
        $this->assertSame('10.5000', (string) $cav6->unit_weight_grams);
        $this->assertSame('12.00', (string) $cav7->cycle_time);
        $this->assertSame('draft', $cav6->status);
        $this->assertSame('draft', $cav7->status);
    }

    public function test_a_multi_valued_cycle_time_splits_into_unresolved_variants_never_averaged(): void
    {
        $this->import();

        $variants = ProductionStandard::where('source_product_name', '500ML ROUND')->orderBy('cycle_time')->get();

        $this->assertCount(2, $variants);
        $this->assertSame(['17.80', '21.50'], $variants->pluck('cycle_time')->map(fn ($c) => (string) $c)->all());
        // The average would be 19.65 — a rate no machine runs at.
        $this->assertNotContains('19.65', $variants->pluck('cycle_time')->map(fn ($c) => (string) $c)->all());

        foreach ($variants as $variant) {
            $this->assertSame('unresolved', $variant->status);
            $this->assertStringContainsString('21.5 / 17.8', (string) $variant->cycle_time_raw);
        }
    }

    public function test_a_cycle_time_above_fourteen_seconds_imports_without_complaint(): void
    {
        $this->import();

        $standard = ProductionStandard::where('source_product_name', '1000ML ROUND / IFF')->firstOrFail();

        $this->assertSame('30.50', (string) $standard->cycle_time);
        // Not flagged: 30.5 s is a real factory standard, not an anomaly.
        $this->assertSame('draft', $standard->status);
        $this->assertNull($standard->unresolved_reason);
    }

    public function test_only_genuinely_blank_values_are_flagged(): void
    {
        $this->import();

        // 90ML RIB has no pouch columns — that is not a gap, it is simply
        // not pouch-packed.
        $trayOnly = ProductionStandard::where('source_product_name', '90ML RIB')->with('packagings')->firstOrFail();
        $this->assertSame('draft', $trayOnly->status);
        $this->assertCount(1, $trayOnly->packagings);
        $this->assertTrue($trayOnly->packagings->first()->is_default, 'A sole option must be the default.');

        // 500ML ROUND has a genuinely blank weight — that IS flagged.
        $blankWeight = ProductionStandard::where('source_product_name', '500ML ROUND')->firstOrFail();
        $this->assertStringContainsString('Unit weight is blank', (string) $blankWeight->unresolved_reason);
    }

    public function test_reimporting_updates_rather_than_duplicating(): void
    {
        $this->import();
        $before = ProductionStandard::count();

        $this->import();
        $this->import();

        $this->assertSame($before, ProductionStandard::count());
        $this->assertSame(
            2,
            ProductionStandardPackaging::where('production_standard_id', ProductionStandard::where('source_product_name', '60ML ROUND')->first()->id)->count(),
        );
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $result = $this->import(write: false);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, ProductionStandard::count());
        $this->assertGreaterThan(0, $result['summary']['variants']);
    }

    public function test_derived_pouches_and_trays_per_box(): void
    {
        $this->import();

        $standard = ProductionStandard::where('source_product_name', '60ML ROUND')->where('cavities', 5)->with('packagings')->firstOrFail();
        $pouch = $standard->packagings->firstWhere('mode', 'pouch');
        $tray = $standard->packagings->firstWhere('mode', 'tray');

        // 1225 per box / 245 per pouch = 5 pouches per box.
        $this->assertSame(5, $pouch->pouches_per_box);
        $this->assertSame(1225, $pouch->nos_per_box);
        // 1150 / 230 = 5 trays.
        $this->assertSame(5, $tray->trays_per_box);
        $this->assertSame(1150, $tray->nos_per_box);
    }

    public function test_a_standard_is_offered_on_every_active_machine_in_watch_mode(): void
    {
        $this->seed(CanonicalMachineSeeder::class);
        $item = Item::create([
            'sku' => 'FAC-1', 'name' => '60ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-1',
        ]);
        $this->import();
        // Match the imported standards onto the item.
        ProductionStandard::where('source_product_name', '60ML ROUND')->update(['item_id' => $item->id]);

        $this->actor();

        // Machine 1 and Machine 10 both offer it — no machine-product
        // mapping exists, and none is required in watch mode.
        foreach (['MC-01', 'MC-10'] as $code) {
            $machine = WorkCenter::where('code', $code)->firstOrFail();

            $preview = $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
                'item_id' => $item->id,
                'work_center_id' => $machine->id,
            ]))->assertOk();

            $this->assertCount(2, $preview->json('data.variants'), "{$code} must offer both cavity variants.");

            $codes = array_column($preview->json('data.warnings'), 'code');
            $this->assertContains('standard_choice_required', $codes);
        }
    }

    public function test_choosing_a_variant_prefills_and_is_recorded_against_the_machine(): void
    {
        $this->seed(CanonicalMachineSeeder::class);
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $item = Item::create([
            'sku' => 'FAC-1', 'name' => '60ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-1',
        ]);
        $this->import();
        ProductionStandard::where('source_product_name', '60ML ROUND')->update(['item_id' => $item->id]);

        // The cavity-6 variant, packed in trays.
        $standard = ProductionStandard::where('source_product_name', '60ML ROUND')->where('cavities', 6)->with('packagings')->firstOrFail();
        $tray = $standard->packagings->firstWhere('mode', 'tray');
        $machine = WorkCenter::where('code', 'MC-10')->firstOrFail();

        $this->actor();

        // Preview prefills from the chosen variant.
        $preview = $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
            'item_id' => $item->id,
            'work_center_id' => $machine->id,
            'shift_id' => $shift->id,
            'production_standard_id' => $standard->id,
            'production_standard_packaging_id' => $tray->id,
        ]))->assertOk();

        $preview->assertJsonPath('data.standard.cavities', 6);
        $preview->assertJsonPath('data.estimation.active_cavities', 6);
        $preview->assertJsonPath('data.estimation.standard_cycle_time', '11.60');
        $preview->assertJsonPath('data.packaging.mode', 'tray');
        // 8 h at 11.6 s -> FLOOR(28800/11.6)=2482 cycles x 6 = 14,892 pieces.
        $preview->assertJsonPath('data.estimation.expected_pieces', 14892);

        // The watch-mode warning is present but nothing is blocked.
        $this->assertContains('machine_mapping_unconfirmed', array_column($preview->json('data.warnings'), 'code'));

        // Start Batch succeeds and records the pairing.
        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_standard_id' => $standard->id,
            'production_standard_packaging_id' => $tray->id,
        ])->assertOk()->json('data.id');

        $entry = ShiftProductionEntry::findOrFail($entryId);

        $this->assertSame($standard->id, $entry->production_standard_id);
        $this->assertSame($tray->id, $entry->production_standard_packaging_id);
        $this->assertSame('tray', $entry->packaging_mode);
        $this->assertSame(6, $entry->standard_cavities);
        $this->assertSame('11.60', (string) $entry->standard_cycle_time);
        // No approved machine mapping — and the run happened anyway.
        $this->assertNull($entry->production_configuration_id);
        // The pairing is on the record, ready to become an approved mapping.
        $this->assertSame($machine->id, $entry->work_center_id);
        $this->assertSame(1150, $entry->config_snapshot['nos_per_box']);
    }
}
