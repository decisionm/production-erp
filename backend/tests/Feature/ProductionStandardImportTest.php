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
            // Multi-valued WEIGHT — the ambiguous 200Ml Round family, open
            // since 24 July. Must split like a multi-valued cycle time.
            ['sl_no' => 48, 'product' => '200ML ROUND', 'cavities' => 4, 'unit_weight_grams' => '18/20', 'cycle_time' => 16.5,
                'nos_per_pouch' => 105, 'pouch_nos_per_box' => 520],
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

    public function test_a_multi_valued_weight_splits_rather_than_averaging(): void
    {
        $this->import();

        $variants = ProductionStandard::where('source_product_name', '200ML ROUND')
            ->orderBy('unit_weight_grams')->get();

        // 18 g and 20 g are different bottles, not a 19 g average. The SPLIT is
        // what this test is about, and it is unchanged.
        $this->assertCount(2, $variants);
        $this->assertSame(['18.0000', '20.0000'], $variants->pluck('unit_weight_grams')->map(fn ($w) => (string) $w)->all());
        $this->assertSame(0, ProductionStandard::where('unit_weight_grams', 19)->count());

        foreach ($variants as $variant) {
            // Cycle time was single-valued, so it is shared, not split.
            $this->assertSame('16.50', (string) $variant->cycle_time);
        }
    }

    public function test_a_confirmed_split_no_longer_asks_the_factory_about_itself(): void
    {
        // SL 48 is 200ML ROUND, whose "18/20" weight the factory has confirmed
        // as deliberate (see ProductMasterCorrections). The variants still
        // exist; they simply stop being an open question.
        $this->import();

        foreach (ProductionStandard::where('source_product_name', '200ML ROUND')->get() as $variant) {
            $this->assertSame('draft', $variant->status);
            $this->assertNull($variant->unresolved_reason);
        }
    }

    public function test_an_unconfirmed_multi_valued_weight_is_still_flagged(): void
    {
        // The rule itself is intact for any product the factory has not spoken
        // about — otherwise the correction above would look like the importer
        // had simply stopped caring.
        $result = app(ProductionStandardImportService::class)->import([
            ['sl_no' => 900, 'product' => 'UNSPOKEN BOTTLE', 'cavities' => 4,
                'unit_weight_grams' => '18/20', 'cycle_time' => 16.5,
                'nos_per_tray' => 100, 'tray_nos_per_box' => 500],
        ], true, null);

        foreach ($result['variants'] as $variant) {
            $this->assertSame('unresolved', $variant['status']);
            $this->assertStringContainsString('Unit weight cell held several values (18/20)', (string) $variant['unresolved_reason']);
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

    public function test_a_reimport_beside_a_person_added_second_tray_updates_only_the_row_it_matches(): void
    {
        // Phase 5 (D1): a standard may hold two same-mode packings. The
        // importer used to key on (standard, mode) and would have rewritten
        // whichever of the two it found first with the sheet's counts.
        $this->import();
        $standard = ProductionStandard::where('source_product_name', '90ML RIB')->firstOrFail();
        $sheetTray = $standard->packagings()->where('mode', 'tray')->firstOrFail();
        $this->assertSame([208, 5, 1040], [$sheetTray->nos_per_tray, $sheetTray->trays_per_box, $sheetTray->nos_per_box]);

        // A person adds the tray packing the workbook never carried.
        $personTray = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 200, 'trays_per_box' => 5, 'nos_per_box' => 1000,
        ]);

        $result = $this->import();

        $trays = $standard->packagings()->where('mode', 'tray')->orderBy('id')->get();
        $this->assertCount(2, $trays, 'the re-import must neither drop nor duplicate a tray row');
        $this->assertSame([208, 5, 1040], [$trays[0]->nos_per_tray, $trays[0]->trays_per_box, $trays[0]->nos_per_box]);
        $this->assertSame($personTray->id, $trays[1]->id);
        $this->assertSame([200, 5, 1000], [$trays[1]->nos_per_tray, $trays[1]->trays_per_box, $trays[1]->nos_per_box], 'a person-entered tray must survive a re-import untouched');

        $variant = collect($result['variants'])->firstWhere('source_product_name', '90ML RIB');
        $this->assertSame([], $variant['packaging_warnings'] ?? [], 'the sheet\'s tray matched exactly one row — nothing to warn about');
        $this->assertSame(0, $result['summary']['packaging_warnings']);
    }

    public function test_a_reimport_whose_tray_matches_none_of_two_leaves_both_intact_and_reports_it(): void
    {
        $this->import();
        $standard = ProductionStandard::where('source_product_name', '90ML RIB')->firstOrFail();
        $sheetTray = $standard->packagings()->where('mode', 'tray')->firstOrFail();

        // A person corrects the imported tray's count in the workspace AND
        // adds a second tray. The sheet's 208 × 5 now matches neither.
        $sheetTray->update(['nos_per_tray' => 210, 'nos_per_box' => 1050]);
        $personTray = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 200, 'trays_per_box' => 5, 'nos_per_box' => 1000,
        ]);

        $result = $this->import();

        $trays = $standard->packagings()->where('mode', 'tray')->orderBy('id')->get();
        $this->assertCount(2, $trays);
        $this->assertSame([210, 5, 1050], [$trays[0]->nos_per_tray, $trays[0]->trays_per_box, $trays[0]->nos_per_box], 'a corrected row is not silently put back');
        $this->assertSame([200, 5, 1000], [$trays[1]->nos_per_tray, $trays[1]->trays_per_box, $trays[1]->nos_per_box]);
        $this->assertSame($personTray->id, $trays[1]->id);

        $variant = collect($result['variants'])->firstWhere('source_product_name', '90ML RIB');
        $warnings = $variant['packaging_warnings'] ?? [];
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('90ML RIB', $warnings[0]);
        $this->assertStringContainsString('2 tray packings', $warnings[0]);
        $this->assertStringContainsString('208', $warnings[0]);
        $this->assertStringContainsString('left untouched', $warnings[0]);
        $this->assertSame(1, $result['summary']['packaging_warnings']);

        // A standard with ONE row of the mode keeps today's behaviour: the
        // sheet's figures update it in place (60ML ROUND's pouch row).
        $pouch = ProductionStandard::where('source_product_name', '60ML ROUND')->where('cavities', 5)->firstOrFail()
            ->packagings()->where('mode', 'pouch')->get();
        $this->assertCount(1, $pouch);
        $this->assertSame(1225, $pouch[0]->nos_per_box);
    }

    public function test_a_row_imported_unlinked_adopts_its_item_when_the_link_arrives(): void
    {
        // The go-live sequence: the whole workbook is loaded first so the
        // standards page shows every product, most rows unlinked; Tally links
        // arrive one by one as the factory confirms names. The later import
        // must ADOPT the existing null-item row — a sibling row would show the
        // same mould twice, once linked and once orphaned, forever.
        $this->import();
        $unlinked = ProductionStandard::where('source_product_name', '90ML RIB')->get();
        $this->assertCount(1, $unlinked);
        $this->assertNull($unlinked->first()->item_id);
        $rowId = $unlinked->first()->id;

        // The factory confirms the name: the item now exists, exactly as the
        // sheet spells it (the name-index fallback links on the exact name).
        $item = Item::create([
            'sku' => '90ML RIB', 'name' => '90ML RIB', 'uom' => 'NOS',
            'is_active' => true, 'tally_stock_item_guid' => 'g-adopt',
        ]);

        $this->import();

        $rows = ProductionStandard::where('source_product_name', '90ML RIB')->get();
        $this->assertCount(1, $rows, 'The re-import must adopt the unlinked row, not add a sibling.');
        $this->assertSame($rowId, $rows->first()->id);
        $this->assertSame($item->id, $rows->first()->item_id);
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

    public function test_exact_only_writes_matched_unambiguous_rows_and_reports_the_rest(): void
    {
        // The production safety setting. Only 60ML ROUND has a matching item
        // here, so only its two variants may be written; everything else is
        // reported with a reason rather than silently dropped.
        $item = Item::create([
            'sku' => 'FAC-1', 'name' => '60ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-1',
        ]);
        // 500ML ROUND DOES match an item but is still ambiguous (split cycle
        // time, blank weight) — so it exercises the second skip reason,
        // which the unmatched check would otherwise mask.
        Item::create([
            'sku' => 'FAC-2', 'name' => '500ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-2',
        ]);

        $result = app(ProductionStandardImportService::class)
            ->import($this->factoryRows(), dryRun: false, createdBy: null, exactOnly: true);

        $this->assertSame(2, $result['summary']['importable']);
        $this->assertSame(2, ProductionStandard::count());
        $this->assertSame($item->id, ProductionStandard::first()->item_id);

        // Every skipped variant carries a reason a person can act on.
        $skipped = array_filter($result['variants'], fn ($v) => $v['skip_reason'] !== null);
        $this->assertSame($result['summary']['skipped'], count($skipped));
        foreach ($skipped as $variant) {
            $this->assertNotEmpty($variant['skip_reason']);
        }

        // The unmatched and the ambiguous are skipped for DIFFERENT reasons.
        $reasons = implode(' ', array_column($skipped, 'skip_reason'));
        $this->assertStringContainsString('No exact item match for "90ML RIB"', $reasons);
        // A matched-but-ambiguous variant is skipped for its ambiguity, not
        // for a missing match.
        $this->assertStringContainsString('Cycle time cell held several values', $reasons);

        // Nothing unresolved reached the database.
        $this->assertSame(0, ProductionStandard::where('status', 'unresolved')->count());
    }

    public function test_exact_only_import_is_idempotent(): void
    {
        Item::create([
            'sku' => 'FAC-1', 'name' => '60ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'tally_stock_item_guid' => 'itm-1',
        ]);
        $service = app(ProductionStandardImportService::class);

        $service->import($this->factoryRows(), false, null, true);
        $afterFirst = [ProductionStandard::count(), ProductionStandardPackaging::count()];

        $service->import($this->factoryRows(), false, null, true);
        $service->import($this->factoryRows(), false, null, true);

        $this->assertSame($afterFirst, [ProductionStandard::count(), ProductionStandardPackaging::count()]);
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

        // Running on the product standard is the normal case — no notice.
        $this->assertNotContains('machine_mapping_unconfirmed', array_column($preview->json('data.warnings'), 'code'));

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
