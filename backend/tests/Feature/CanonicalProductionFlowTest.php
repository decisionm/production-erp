<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\TallySyncEntry;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The canonical production flow, end to end, as one executable scenario:
 *
 *   GRN → supplier lot → bag barcodes → Start Batch → scan-load 5 kg of a
 *   100 kg bag → running day-bin → closing count → automatic consumption →
 *   completion → expected vs actual → Plant Manager → Accounts →
 *   voucher preview → STOP (nothing transmitted to Tally).
 *
 * Deliberately one test rather than several isolated ones: every previous
 * gap in this chain sat in the joins between components that each worked
 * alone.
 */
class CanonicalProductionFlowTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $mb;

    private Item $cap;

    private Warehouse $rm;

    private Warehouse $fg;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        // Traceability is the canonical flow, not an option.
        config()->set('production.traceability_enabled', true);

        $this->seed(CanonicalMachineSeeder::class);
        $this->machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);

        $this->resin = Item::create(['sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.', 'is_active' => true, 'tally_stock_item_guid' => 'g1']);
        $this->mb = Item::create(['sku' => 'MB-AMBER', 'name' => 'Master Batch - Amber', 'uom' => 'Kgs.', 'is_active' => true, 'tally_stock_item_guid' => 'g2']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos.', 'is_active' => true, 'tally_stock_item_guid' => 'g3']);

        $this->bottle = Item::create([
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g4',
        ]);

        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);
        $bom->lines()->create(['component_item_id' => $this->mb->id, 'quantity_per' => '0.0003']);
        $bom->lines()->create(['component_item_id' => $this->cap->id, 'quantity_per' => '1.0000']);

        $stock = app(StockMovementService::class);
        foreach ([[$this->resin, '2000'], [$this->mb, '100'], [$this->cap, '50000']] as [$item, $qty]) {
            $stock->recordReceipt(itemId: $item->id, warehouseId: $this->rm->id, quantity: $qty, unitCost: '0', reference: 'opening');
        }
    }

    private function actAs(string ...$roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'inventory.view', 'inventory.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage', 'inventory.view', 'inventory.manage']);
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** GRN → lot → one barcoded bag per physical bag. */
    private function receiveLot(int $bags = 2, string $kgPerBag = '100'): MaterialLot
    {
        return app(TraceabilityService::class)->createLot([
            'item_id' => $this->resin->id,
            'supplier_lot_no' => 'ACC-LOT-001',
            'received_date' => now()->subDay()->toDateString(),
            'bag_count' => $bags,
            'bag_weight_kg' => $kgPerBag,
            'total_received_kg' => bcmul((string) $bags, $kgPerBag, 4),
            'warehouse_id' => $this->rm->id,
        ], null);
    }

    // =================================================================
    // THE SCENARIO
    // =================================================================

    public function test_the_full_canonical_flow_from_barcode_to_voucher_preview(): void
    {
        $supervisor = $this->actAs();

        // ---- 1. GRN: lot with one barcode per physical bag -----------
        $lot = $this->receiveLot(bags: 2, kgPerBag: '100');
        $this->assertCount(2, $lot->bags);

        $bag = $lot->bags->first();
        $this->assertNotEmpty($bag->barcode, 'Every physical bag must carry a barcode.');
        $this->assertSame('100.0000', (string) $bag->original_kg);
        $this->assertSame('100.0000', (string) $bag->remaining_kg);
        // Barcodes are unique across the lot.
        $this->assertSame(2, $lot->bags->pluck('barcode')->unique()->count());

        // ---- 2. Start Batch -------------------------------------------
        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-29',
        ])->assertOk()->json('data.id');

        // ---- 3. Scan-load 5 kg of the 100 kg bag ----------------------
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $bag->barcode,
            'work_center_id' => $this->machine->id,
            'shift_production_entry_id' => $entryId,
            'quantity_kg' => '5',
        ])->assertSuccessful();

        // The bag keeps its remaining 95 kg and stays in the store — a
        // partial load pours off the weighed quantity.
        $bag->refresh();
        $this->assertSame('95.0000', (string) $bag->remaining_kg);
        $this->assertSame('in_store', $bag->status->value);

        // Day bin holds exactly the 5 kg.
        $state = $this->getJson("/api/v1/production/work-centers/{$this->machine->id}/day-bin")->assertOk();
        $resinLine = collect($state->json('data.materials'))->firstWhere('item.id', $this->resin->id);
        $this->assertSame('5.0000', $resinLine['balance_kg']);
        // The bag that fed it is traceable from the machine's day bin.
        $this->assertSame($bag->barcode, $resinLine['loaded_bags'][0]['barcode']);

        // ---- 4. Complete with a closing count of 0 --------------------
        // This is the connection that was missing: without closing_day_bin
        // on a normal completion, consumed kg stayed null.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',      // 10 boxes x 810
            'no_of_box' => 10,
            'nos_per_box' => 810,
            'running_hours' => '8',
            'quantity_scrap' => '160',          // rejection pieces
            'qc_rejection_kg' => '1.930',
            'closing_day_bin' => [
                ['item_id' => $this->resin->id, 'quantity_kg' => '0'],
            ],
            'scraps' => [['type' => 'lumps', 'quantity_kg' => '1.650']],
        ])->assertOk();

        // ---- 5. Automatic consumption --------------------------------
        // opening 0 + loaded 5 − closing 0 − returned 0 = 5 kg.
        $consumption = $this->getJson("/api/v1/production/shift-production-entries/{$entryId}/day-bin")->assertOk();
        $resinConsumption = collect($consumption->json('data.materials'))->firstWhere('item.id', $this->resin->id);

        $this->assertSame('0.0000', $resinConsumption['opening_kg']);
        $this->assertSame('5.0000', $resinConsumption['loaded_kg']);
        $this->assertSame('0.0000', $resinConsumption['closing_kg']);
        $this->assertSame('5.0000', $resinConsumption['consumption_kg']);

        // ---- 6. Expected vs actual -----------------------------------
        $row = collect($this->getJson('/api/v1/production/shift-production-entries?status=pending')->assertOk()->json('data'))
            ->firstWhere('id', $entryId);
        $metrics = $row['metrics'];

        // CT 12.3s, 5 cavities, 8h -> FLOOR(28800/12.3)=2341 cycles x 5
        // = 11,705 pieces; /810 -> 14.45 -> ROUND 14 boxes.
        $this->assertSame(14, $metrics['expected_boxes']);
        $this->assertSame(10, $metrics['actual_boxes']);
        // 10/14 = 71.428…; the approval display carries 1 dp. The engine's
        // boxEfficiency() gives 71.43 at 2 dp — same number, different
        // presentation boundary. Not unified here on purpose: changing the
        // rounding would move the figure shown on already-approved entries.
        $this->assertSame(71.4, $metrics['efficiency_pct']);
        // 8100 x 12.9g = 104.49 kg good.
        $this->assertSame('104.4900', $metrics['good_production_kg']);
        // QC weighed the rejection, so QC wins over the piece-derived figure.
        $this->assertSame('1.9300', $metrics['confirmed_rejection_kg']);
        $this->assertSame('1.6500', $metrics['lumps_kg']);

        // ---- 7. Plant Manager, then Accounts -------------------------
        $this->actAs('Plant Manager');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/pm-approve")->assertOk();
        $this->assertSame(0, TallySyncEntry::query()->count(), 'No voucher may exist before Accounts approval.');

        // ---- 8. Voucher preview — and STOP ---------------------------
        $this->actAs('Accounts');
        $preview = $this->getJson("/api/v1/production/shift-production-entries/{$entryId}/voucher-preview")->assertOk();
        $preview->assertJsonPath('data.voucher.voucher_number', 'SPE-'.$entryId);
        $preview->assertJsonPath('data.voucher.godown', 'FG Store');

        // The preview is read-only: inspecting it transmits nothing.
        $this->assertSame(0, TallySyncEntry::query()->count(), 'A voucher preview must not queue or transmit anything.');
    }

    // =================================================================
    // FAILURE PATHS
    // =================================================================

    public function test_an_unknown_barcode_is_rejected(): void
    {
        $this->actAs();
        $entryId = $this->startBatch();

        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => 'NO-SUCH-BAG',
            'work_center_id' => $this->machine->id,
            'shift_production_entry_id' => $entryId,
            'quantity_kg' => '5',
        ])->assertStatus(422)
            ->assertJsonPath('errors.barcode.0', 'Unknown bag barcode — no registered bag carries this code.');
    }

    public function test_a_duplicate_barcode_cannot_be_created(): void
    {
        $this->receiveLot();

        $this->expectException(QueryException::class);
        MaterialBag::create([
            'material_lot_id' => MaterialLot::first()->id,
            'barcode' => MaterialBag::first()->barcode,
            'original_kg' => '50', 'remaining_kg' => '50', 'status' => 'in_store',
        ]);
    }

    public function test_loading_more_than_the_bag_holds_is_refused(): void
    {
        $this->actAs();
        $lot = $this->receiveLot(bags: 1, kgPerBag: '100');
        $entryId = $this->startBatch();

        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $lot->bags->first()->barcode,
            'work_center_id' => $this->machine->id,
            'shift_production_entry_id' => $entryId,
            'quantity_kg' => '150',
        ])->assertStatus(422);

        // The bag is untouched — a refused load must not consume anything.
        $this->assertSame('100.0000', (string) $lot->bags->first()->fresh()->remaining_kg);
    }

    public function test_two_bags_can_feed_one_batch(): void
    {
        // Loading the newer bag while an older one is still open is a FIFO
        // override, and an override needs the permission — that is the
        // policy, not an obstacle to work around.
        $user = $this->actAs();
        Permission::findOrCreate('production.override-fifo', 'web');
        $user->givePermissionTo('production.override-fifo');
        $lot = $this->receiveLot(bags: 2, kgPerBag: '100');
        $entryId = $this->startBatch();

        foreach ($lot->bags as $index => $bag) {
            $this->postJson('/api/v1/production/day-bin/load', [
                'barcode' => $bag->barcode,
                'work_center_id' => $this->machine->id,
                'shift_production_entry_id' => $entryId,
                'quantity_kg' => $index === 0 ? '5' : '3',
                'override_fifo' => true,
            ])->assertSuccessful();
        }

        $state = $this->getJson("/api/v1/production/work-centers/{$this->machine->id}/day-bin")->assertOk();
        $line = collect($state->json('data.materials'))->firstWhere('item.id', $this->resin->id);
        $this->assertSame('8.0000', $line['balance_kg'], 'Both bags must feed the same day bin.');
        $this->assertCount(2, $line['loaded_bags'], 'Both bags must be traceable from the day bin.');
    }

    public function test_a_missing_closing_count_leaves_consumption_honestly_null(): void
    {
        $this->actAs();
        $lot = $this->receiveLot(bags: 1);
        $entryId = $this->startBatch();

        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $lot->bags->first()->barcode,
            'work_center_id' => $this->machine->id,
            'shift_production_entry_id' => $entryId,
            'quantity_kg' => '5',
        ])->assertSuccessful();

        // Completing WITHOUT a closing count.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100', 'no_of_box' => 10, 'nos_per_box' => 810, 'running_hours' => '8',
        ])->assertOk();

        $consumption = $this->getJson("/api/v1/production/shift-production-entries/{$entryId}/day-bin")->assertOk();
        $line = collect($consumption->json('data.materials'))->firstWhere('item.id', $this->resin->id);

        // Null, never a fabricated zero — "we did not count" is not "nothing
        // was consumed".
        $this->assertNull($line['closing_kg']);
        $this->assertNull($line['consumption_kg']);
    }

    public function test_an_impossible_closing_count_aborts_the_whole_completion(): void
    {
        $this->actAs();
        $lot = $this->receiveLot(bags: 1);
        $entryId = $this->startBatch();

        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $lot->bags->first()->barcode,
            'work_center_id' => $this->machine->id,
            'shift_production_entry_id' => $entryId,
            'quantity_kg' => '5',
        ])->assertSuccessful();

        // Claiming 50 kg still in the bin when only 5 kg was ever loaded.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100', 'no_of_box' => 10, 'nos_per_box' => 810, 'running_hours' => '8',
            'closing_day_bin' => [['item_id' => $this->resin->id, 'quantity_kg' => '50']],
        ])->assertStatus(422);

        // Atomic: the batch is still running, nothing half-saved.
        $this->assertSame('in_progress', ShiftProductionEntry::find($entryId)->batch_status->value);
    }

    public function test_a_duplicate_start_returns_the_running_batch(): void
    {
        $this->actAs();
        $first = $this->startBatch();

        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id, 'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id, 'warehouse_id' => $this->fg->id,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'machine_busy')
            ->assertJsonPath('active_batch.id', $first);
    }

    public function test_an_inactive_machine_cannot_start(): void
    {
        config()->set('production.readiness.enforced', true);
        $this->actAs();
        $this->machine->update(['is_active' => false]);

        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id, 'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id, 'warehouse_id' => $this->fg->id,
        ])->assertStatus(422)->assertJsonPath('blocking.0.code', 'machine_active');
    }

    public function test_cavity_six_and_seven_products_run_the_same_way(): void
    {
        $this->actAs();

        foreach ([[6, '10.5', 11.6, 1150], [7, '8.5', 12.0, 1320]] as [$cav, $weight, $ct, $perBox]) {
            $item = Item::create([
                'sku' => "BTL-CAV{$cav}", 'name' => "Bottle cav {$cav}", 'uom' => 'Nos.', 'is_active' => true,
                'nominal_weight_grams' => $weight, 'colour' => 'Amber', 'tally_stock_item_guid' => "gc{$cav}",
            ]);
            $std = ProductionStandard::create([
                'item_id' => $item->id, 'source_product_name' => "Bottle cav {$cav}",
                'cavities' => $cav, 'unit_weight_grams' => $weight, 'cycle_time' => $ct, 'status' => 'draft',
            ]);
            ProductionStandardPackaging::create([
                'production_standard_id' => $std->id, 'mode' => 'tray',
                'nos_per_tray' => 100, 'trays_per_box' => 10, 'nos_per_box' => $perBox, 'is_default' => true,
            ]);

            $machine = WorkCenter::where('code', sprintf('MC-%02d', $cav))->firstOrFail();
            $preview = $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
                'item_id' => $item->id, 'work_center_id' => $machine->id, 'shift_id' => $this->shift->id,
            ]))->assertOk();

            $preview->assertJsonPath('data.standard.cavities', $cav);
            $preview->assertJsonPath('data.estimation.active_cavities', $cav);
            $this->assertNotNull($preview->json('data.estimation.expected_pieces'));
        }
    }

    public function test_a_pouch_product_offers_both_packagings_and_a_tray_only_product_does_not(): void
    {
        $this->actAs();

        $pouchItem = Item::create(['sku' => 'BTL-60', 'name' => '60ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '10.5', 'colour' => 'Amber', 'tally_stock_item_guid' => 'gp']);
        $std = ProductionStandard::create(['item_id' => $pouchItem->id, 'source_product_name' => '60ML ROUND',
            'cavities' => 6, 'unit_weight_grams' => '10.5', 'cycle_time' => '11.6', 'status' => 'draft']);
        ProductionStandardPackaging::create(['production_standard_id' => $std->id, 'mode' => 'pouch',
            'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225]);
        ProductionStandardPackaging::create(['production_standard_id' => $std->id, 'mode' => 'tray',
            'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150]);

        $preview = $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
            'item_id' => $pouchItem->id, 'work_center_id' => $this->machine->id, 'shift_id' => $this->shift->id,
        ]))->assertOk();

        $modes = collect($preview->json('data.variants.0.packagings'))->pluck('mode')->sort()->values()->all();
        $this->assertSame(['pouch', 'tray'], $modes);
        // Two options and no default: the supervisor must be asked.
        $this->assertNull($preview->json('data.packaging'));
        $this->assertContains('packaging_choice_required', array_column($preview->json('data.warnings'), 'code'));
    }

    private function startBatch(): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-29',
        ])->assertOk()->json('data.id');
    }
}
