<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The item-423 packaging shape, pinned: the workbook gave "B.450ML Ribbed
 * Pet Bottle Amber 34gms" exactly one packaging row — 120 per pouch, boxes
 * NOT stated (packaging id 119: units 120, per-box NULL) — while the item
 * master carries a complete 249/box. A half-stated row used to be adopted
 * just for being the only row; now it is display data, never run data. The
 * run falls back to the item master's packing and the preview says so.
 */
class IncompletePackagingNeverRunsTest extends TestCase
{
    use RefreshDatabase;

    private WorkCenter $machine;

    private Item $item;

    private Warehouse $warehouse;

    private Shift $shift;

    private ProductionStandard $standard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = WorkCenter::create(['name' => 'ASB 2', 'code' => 'ASB-2', 'is_active' => true]);
        $this->shift = Shift::create(['name' => 'Shift A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        // The live 423 shape: complete item master (249/box), so the
        // fallback tier the incomplete row must yield to is real.
        $this->item = Item::create([
            'sku' => 'B.450ML Ribbed Pet Bottle Amber 34gms',
            'name' => 'B.450ML Ribbed Pet Bottle Amber 34gms',
            'uom' => 'NOS', 'is_active' => true, 'colour' => 'Amber',
            'nominal_weight_grams' => '34.0000', 'nos_per_box' => 249,
            'tally_stock_item_guid' => 'itm-450-amber',
        ]);
        $this->standard = ProductionStandard::create([
            'item_id' => $this->item->id,
            'source_product_name' => 'B.450ML Ribbed Pet Bottle Amber 34gms',
            'cavities' => 7,
            'unit_weight_grams' => '34.0000',
            'cycle_time' => '16.50',
            'carton_spec' => 'LD 30 X 49',
            'tray_spec' => '450 LAYER',
            'pouch_spec' => 'LD 30 X 49',
            'status' => 'approved',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    /** The live row 119, byte for byte: pouch of 120, boxes unstated. */
    private function incompletePouchRow(): ProductionStandardPackaging
    {
        return ProductionStandardPackaging::create([
            'production_standard_id' => $this->standard->id,
            'mode' => ProductionStandardPackaging::MODE_POUCH,
            'nos_per_pouch' => 120,
            'pouches_per_box' => null,
            'nos_per_box' => null,
        ]);
    }

    private function preview(string $extra = ''): array
    {
        return $this->getJson(
            '/api/v1/production/shift-production-entries/preview'
            ."?item_id={$this->item->id}&work_center_id={$this->machine->id}"
            ."&warehouse_id={$this->warehouse->id}&shift_id={$this->shift->id}{$extra}"
        )->assertOk()->json('data');
    }

    public function test_a_lone_incomplete_row_is_not_auto_resolved_and_the_run_uses_the_item_master(): void
    {
        $row = $this->incompletePouchRow();

        $data = $this->preview();

        // Never the incomplete row — not even partially. Before the fix the
        // estimation took its 120/pouch while boxes fell back to the item
        // master: two sources answering one packing question.
        $this->assertNull($data['packaging']);
        $this->assertSame(249, $data['estimation']['nos_per_box']);
        $this->assertNull($data['estimation']['nos_per_pouch']);
        $this->assertSame('pouch', $data['variants'][0]['packagings'][0]['mode']);
        $this->assertFalse($data['variants'][0]['packagings'][0]['is_complete']);

        $codes = array_column($data['warnings'], 'code');
        $this->assertContains('packaging_incomplete', $codes);
        // And the preview still stands on its feet: readiness assessed, the
        // estimation computed from the standard's own figures.
        $this->assertTrue($data['readiness']['ready']);
        $this->assertSame(7, $data['estimation']['active_cavities']);

        // The row's id is still real — it just cannot run a batch.
        $this->assertNotNull($row->fresh());
    }

    public function test_explicitly_choosing_the_incomplete_row_is_refused_the_same_way(): void
    {
        $row = $this->incompletePouchRow();

        $data = $this->preview("&production_standard_packaging_id={$row->id}");

        $this->assertNull($data['packaging']);
        $this->assertSame(249, $data['estimation']['nos_per_box']);
        $this->assertContains('packaging_incomplete', array_column($data['warnings'], 'code'));
    }

    public function test_a_started_batch_snapshots_the_item_master_not_the_incomplete_row(): void
    {
        $row = $this->incompletePouchRow();

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'production_date' => '2026-08-09',
            'production_standard_id' => $this->standard->id,
            'production_standard_packaging_id' => $row->id,
        ], null);

        $this->assertNull($entry->production_standard_packaging_id);
        $this->assertSame(249, $entry->config_snapshot['nos_per_box']);
        $this->assertNull($entry->config_snapshot['nos_per_pouch']);
    }

    public function test_pouch_and_tray_both_complete_is_a_choice_not_a_silent_pick(): void
    {
        ProductionStandardPackaging::create([
            'production_standard_id' => $this->standard->id,
            'mode' => ProductionStandardPackaging::MODE_POUCH,
            'nos_per_pouch' => 120, 'pouches_per_box' => 2, 'nos_per_box' => 240,
        ]);
        ProductionStandardPackaging::create([
            'production_standard_id' => $this->standard->id,
            'mode' => ProductionStandardPackaging::MODE_TRAY,
            'nos_per_tray' => 83, 'trays_per_box' => 3, 'nos_per_box' => 249,
        ]);

        $data = $this->preview();

        $this->assertNull($data['packaging']);
        $this->assertContains('packaging_choice_required', array_column($data['warnings'], 'code'));
    }

    public function test_the_default_flag_still_decides_between_two_complete_rows(): void
    {
        ProductionStandardPackaging::create([
            'production_standard_id' => $this->standard->id,
            'mode' => ProductionStandardPackaging::MODE_POUCH,
            'nos_per_pouch' => 120, 'pouches_per_box' => 2, 'nos_per_box' => 240,
        ]);
        $tray = ProductionStandardPackaging::create([
            'production_standard_id' => $this->standard->id,
            'mode' => ProductionStandardPackaging::MODE_TRAY,
            'nos_per_tray' => 83, 'trays_per_box' => 3, 'nos_per_box' => 249,
            'is_default' => true,
        ]);

        $data = $this->preview();

        $this->assertSame($tray->id, $data['packaging']['id']);
        $this->assertNotContains('packaging_choice_required', array_column($data['warnings'], 'code'));
    }

    public function test_one_complete_row_among_incomplete_siblings_resolves_alone(): void
    {
        $this->incompletePouchRow();
        $tray = ProductionStandardPackaging::create([
            'production_standard_id' => $this->standard->id,
            'mode' => ProductionStandardPackaging::MODE_TRAY,
            'nos_per_tray' => 83, 'trays_per_box' => 3, 'nos_per_box' => 249,
        ]);

        $data = $this->preview();

        // The one row that can run a batch is not a "choice" — the
        // incomplete sibling is not an option, so nothing is asked.
        $this->assertSame($tray->id, $data['packaging']['id']);
        $this->assertNotContains('packaging_choice_required', array_column($data['warnings'], 'code'));
        $this->assertNotContains('packaging_incomplete', array_column($data['warnings'], 'code'));
    }
}
