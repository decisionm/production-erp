<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Consumption variance — expected material use per norm (active BOM first,
 * else the item's nominal weight) vs what the supervisor actually entered.
 * The whole "variance" key is null until the batch completes.
 */
class ConsumptionVarianceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $rmStore;

    private Item $resin;

    private function completedEntry(array $itemAttributes = [], array $entryAttributes = []): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'Finished Goods']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);
        $item = Item::create($itemAttributes + ['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);
        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'kg']);

        return ShiftProductionEntry::create($entryAttributes + [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-25',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
    }

    public function test_item_weight_norm_happy_path_appears_on_the_approval_list(): void
    {
        $entry = $this->completedEntry(
            itemAttributes: ['nominal_weight_grams' => '20.0000'],
            entryAttributes: ['quantity_rejection_kg' => '2'],
        );
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '25',
        ]);
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '1.5']);

        $viewer = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $viewer->givePermissionTo('production.view');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/production/shift-production-entries')
            ->assertOk()
            ->assertJsonPath('data.0.variance', [
                'norm_source' => 'item_weight',
                'expected_kg' => '20.0000',
                'actual_kg' => '25.0000',
                'variance_kg' => '5.0000',
                // JSON numbers carry no zero fraction — 25.0 goes over the
                // wire as 25 and decodes as int.
                'variance_pct' => 25,
                'variance_band' => 'investigate',
                'rejection_kg' => '2.0000',
                'scrap_kg' => '1.5000',
                'unaccounted_kg' => '1.5000',
            ]);
    }

    public function test_no_weight_and_no_bom_reports_actuals_with_null_norm(): void
    {
        $entry = $this->completedEntry();
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '10',
        ]);
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '0.75']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame([
            'norm_source' => null,
            'expected_kg' => null,
            'actual_kg' => '10.0000',
            'variance_kg' => null,
            'variance_pct' => null,
            'variance_band' => null,
            'rejection_kg' => '0',
            'scrap_kg' => '0.7500',
            'unaccounted_kg' => null,
        ], $variance);
    }

    public function test_active_bom_overrides_item_weight_and_excludes_non_kg_components(): void
    {
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);
        $masterbatch = Item::create(['sku' => 'MB-BLUE', 'name' => 'Masterbatch Blue', 'uom' => 'KGS']);
        $cap = Item::create(['sku' => 'CAP-28MM', 'name' => '28mm Cap', 'uom' => 'pcs']);

        // A superseded inactive version must not participate.
        $old = Bom::create(['item_id' => $entry->item_id, 'name' => 'Bottle BOM', 'version' => '1', 'is_active' => false]);
        $old->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.9000']);

        $bom = Bom::create(['item_id' => $entry->item_id, 'name' => 'Bottle BOM', 'version' => '2', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0250']);
        $bom->lines()->create(['component_item_id' => $masterbatch->id, 'quantity_per' => '0.0010']);
        // Counted in Nos — must NOT be summed into kg.
        $bom->lines()->create(['component_item_id' => $cap->id, 'quantity_per' => '1.0000']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame('bom', $variance['norm_source']);
        // 1000 x (0.0250 + 0.0010) — not 20 kg from the item weight, and not
        // 1026 kg from also counting the cap line.
        $this->assertSame('26.0000', $variance['expected_kg']);
    }

    public function test_soft_deleted_component_still_counts_toward_the_bom_norm(): void
    {
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);

        $bom = Bom::create(['item_id' => $entry->item_id, 'name' => 'Bottle BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0250']);

        // A master cleanup trashing the resin item must not zero the norm.
        $this->resin->delete();

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame('bom', $variance['norm_source']);
        $this->assertSame('25.0000', $variance['expected_kg']);
    }

    public function test_bom_with_only_nos_lines_falls_back_to_the_item_weight(): void
    {
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);
        $cap = Item::create(['sku' => 'CAP-28MM', 'name' => '28mm Cap', 'uom' => 'pcs']);

        // Packaging-only BOM provides no mass norm — expected must come from
        // the product weight, not report a 0 kg BOM norm.
        $bom = Bom::create(['item_id' => $entry->item_id, 'name' => 'Pack BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $cap->id, 'quantity_per' => '1.0000']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame('item_weight', $variance['norm_source']);
        $this->assertSame('20.0000', $variance['expected_kg']);
    }

    public function test_variance_is_null_for_a_batch_that_has_not_completed(): void
    {
        $entry = $this->completedEntry(entryAttributes: [
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
        ]);

        $this->assertNull(app(ShiftProductionEntryService::class)->consumptionVariance($entry));
    }
}
