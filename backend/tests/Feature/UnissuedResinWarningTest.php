<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A BATCH WHOSE RESIN THE STORE HAS NOT ISSUED CLOSES, AND THE APPROVAL
 * SCREEN SAYS SO (DEC-20260903-003).
 *
 * The live defect behind it: for fifteen days every batch consumed "Pet
 * Resin" straight from the Store while 1,000 kg of Relpet stood issued and
 * untouched on the floor, and nothing anywhere said so — the Store's balance
 * went negative without a shortfall, because nothing of THAT item stood in
 * Production/WIP (DEC-20260831-009). The owner chose the warning over the
 * refusal: the batch closes, the consumption is recorded exactly as
 * submitted, and the approval desk sees the material named.
 *
 * Same wire as stock_shortfalls: frozen onto the entry's config_snapshot at
 * completion with the names, read back as metrics.unissued_materials, and
 * cleared by an amendment before the re-run writes its own.
 */
class UnissuedResinWarningTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $relpet;

    private Item $masterbatch;

    private Item $bottle;

    private Warehouse $fg;

    private Warehouse $store;

    private Warehouse $wip;

    private Shift $shift;

    private WorkCenter $machine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);
        $this->seed(CanonicalMachineSeeder::class);
        $this->machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->store = Warehouse::create(['code' => 'RM', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production WIP', 'is_active' => true]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);

        // Two resins and a masterbatch, spelled the way this factory's Tally
        // spells them. The Store holds plenty of both resins.
        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'Pet Resin', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'g1']);
        $this->relpet = Item::create(['sku' => 'RELPET', 'name' => 'Relpet G5801M', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'g2']);
        $this->masterbatch = Item::create(['sku' => 'MB-AMBER', 'name' => 'Master Batch Amber', 'uom' => 'Kgs', 'colour' => 'Amber', 'is_active' => true, 'tally_stock_item_guid' => 'g4']);
        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $this->store->id, 'quantity' => '5000.0000', 'average_cost' => '90.0000']);
        StockBalance::create(['item_id' => $this->relpet->id, 'warehouse_id' => $this->store->id, 'quantity' => '5000.0000', 'average_cost' => '132.0000']);
        StockBalance::create(['item_id' => $this->masterbatch->id, 'warehouse_id' => $this->store->id, 'quantity' => '100.0000', 'average_cost' => '300.0000']);

        $this->bottle = Item::create([
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g3',
        ]);
        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        // The recipe names both resins and the masterbatch, so every line
        // below is on-plan: the controlled exception for off-plan materials
        // (DEC-20260902-019) is a different gate and not what this file pins.
        $bom->lines()->create(['component_item_id' => $this->relpet->id, 'quantity_per' => '0.0129']);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);
        $bom->lines()->create(['component_item_id' => $this->masterbatch->id, 'quantity_per' => '0.0003']);

        $this->user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user->givePermissionTo(['production.view', 'production.manage', 'inventory.view', 'inventory.manage']);
        Sanctum::actingAs($this->user);
    }

    private function startBatch(): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-30',
        ])->assertOk()->json('data.id');
    }

    /** @param  list<array{item_id: int, quantity_issued_kg: string}>  $lines */
    private function complete(int $entryId, array $lines): TestResponse
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => $lines,
        ]);
    }

    /** The Store hands this material into Production/WIP on an issue still open. */
    private function storeIssued(Item $item, string $kg): void
    {
        $issue = StoreIssue::create([
            'issue_number' => 'SI-'.str_pad((string) (StoreIssue::count() + 1), 6, '0', STR_PAD_LEFT),
            'status' => StoreIssueStatus::Issued,
            'issued_by' => $this->user->id,
            'received_by' => $this->user->id,
            'issued_at' => now(),
        ]);
        StoreIssueLine::create([
            'store_issue_id' => $issue->id,
            'item_id' => $item->id,
            'from_warehouse_id' => $this->store->id,
            'to_warehouse_id' => $this->wip->id,
            'quantity_issued' => $kg,
            'quantity_returned' => '0',
            'uom' => 'Kgs',
        ]);
        StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $this->store->id)->decrement('quantity', $kg);
        StockBalance::updateOrCreate(
            ['item_id' => $item->id, 'warehouse_id' => $this->wip->id],
            ['quantity' => $kg, 'average_cost' => '132.0000'],
        );
    }

    public function test_the_live_case_closes_the_batch_and_names_the_resin_at_approval(): void
    {
        // Relpet is on the floor; the batch names Pet Resin.
        $this->storeIssued($this->relpet, '1000');

        $entryId = $this->startBatch();
        $response = $this->complete($entryId, [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '118.998'],
            ['item_id' => $this->masterbatch->id, 'quantity_issued_kg' => '0.25'],
        ]);

        // NEVER REFUSED. The consumption is stored exactly as submitted and
        // drawn from the Store, where that item actually is.
        $response->assertOk();
        $entry = ShiftProductionEntry::findOrFail($entryId);
        $line = $entry->materialConsumptions()->where('item_id', $this->resin->id)->firstOrFail();
        $this->assertSame($this->store->id, $line->warehouse_id);
        $this->assertSame('4881.0020', StockBalance::where('item_id', $this->resin->id)->where('warehouse_id', $this->store->id)->value('quantity'));

        // Frozen on the entry with the names, so a later rename cannot turn
        // the warning back into "item #592".
        $recorded = $entry->config_snapshot['unissued_materials'];
        $this->assertCount(1, $recorded, 'the resin only — masterbatch is not a bin material (DEC-20260902-004)');
        $this->assertSame($this->resin->id, $recorded[0]['item_id']);
        $this->assertSame('Pet Resin', $recorded[0]['item_name']);
        $this->assertSame('Kgs', $recorded[0]['item_uom']);
        $this->assertSame('118.9980', $recorded[0]['quantity']);
        $this->assertSame($this->store->id, $recorded[0]['warehouse_id']);
        $this->assertSame('Raw Material Store', $recorded[0]['warehouse_name']);
        $this->assertStringContainsString('Store', $recorded[0]['basis']);

        // And on the resource, where the approval screen reads it.
        $metrics = $response->json('data.metrics.unissued_materials');
        $this->assertCount(1, $metrics);
        $this->assertSame('Pet Resin', $metrics[0]['item_name']);
        $this->assertSame('118.9980', $metrics[0]['quantity']);

        // No shortfall: the Store had the stock. The two warnings are
        // different facts and must not be conflated.
        $this->assertSame([], $response->json('data.metrics.stock_shortfalls'));
    }

    public function test_a_resin_the_store_issued_raises_no_warning(): void
    {
        $this->storeIssued($this->relpet, '1000');

        $entryId = $this->startBatch();
        $response = $this->complete($entryId, [
            ['item_id' => $this->relpet->id, 'quantity_issued_kg' => '118.998'],
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('data.metrics.unissued_materials'));
        $this->assertArrayNotHasKey('unissued_materials', ShiftProductionEntry::findOrFail($entryId)->config_snapshot ?? []);

        // Drawn from the floor, as DEC-20260831-009 provides.
        $line = ShiftProductionEntry::findOrFail($entryId)->materialConsumptions()->firstOrFail();
        $this->assertSame($this->wip->id, $line->warehouse_id);
    }

    public function test_a_factory_that_has_never_issued_anything_sees_no_warning(): void
    {
        // No Store Issue exists at all: consumption is answered exactly as
        // before the store-issue flow existed, and there is no handover to
        // have missed.
        $entryId = $this->startBatch();
        $response = $this->complete($entryId, [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '118.998'],
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('data.metrics.unissued_materials'));
    }

    public function test_an_amendment_clears_the_warning_before_the_re_run_writes_its_own(): void
    {
        $this->storeIssued($this->relpet, '1000');

        $entryId = $this->startBatch();
        $this->complete($entryId, [['item_id' => $this->resin->id, 'quantity_issued_kg' => '118.998']])->assertOk();
        $this->assertCount(1, ShiftProductionEntry::findOrFail($entryId)->config_snapshot['unissued_materials']);

        // The supervisor corrects the batch to the resin the Store issued.
        $amended = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/amend", [
            'amendment_reason' => 'Wrong resin named — the floor is running on Relpet.',
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [['item_id' => $this->relpet->id, 'quantity_issued_kg' => '118.998']],
        ])->assertOk();

        $this->assertArrayNotHasKey('unissued_materials', ShiftProductionEntry::findOrFail($entryId)->config_snapshot ?? []);
        $this->assertSame([], $amended->json('data.metrics.unissued_materials'));
    }
}
