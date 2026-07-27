<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Product packing master (go-live wave): items carry nos_per_tray /
 * trays_per_box / nos_per_box standards the frontend uses to prefill
 * Complete Batch, and the shift entry captures the machine helper's name.
 * Both are strictly additive — everything stays nullable and optional, so
 * the shop-floor flow is byte-for-byte unchanged until the standards data
 * load lands.
 */
class PackingMasterTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUserWithPermission(string $permission): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    private function inProgressEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-27',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    public function test_item_store_accepts_and_returns_the_packing_master_fields(): void
    {
        $this->actingAsUserWithPermission('inventory.manage');

        $this->postJson('/api/v1/inventory/items', [
            'sku' => 'BTL-500',
            'name' => '500ml Bottle',
            'uom' => 'NOS',
            'nos_per_tray' => 84,
            'trays_per_box' => 6,
            'nos_per_box' => 504,
        ])
            ->assertCreated()
            ->assertJsonPath('data.nos_per_tray', 84)
            ->assertJsonPath('data.trays_per_box', 6)
            ->assertJsonPath('data.nos_per_box', 504);
    }

    public function test_item_update_round_trips_the_packing_master_fields(): void
    {
        $this->actingAsUserWithPermission('inventory.manage');
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);

        $this->putJson("/api/v1/inventory/items/{$item->id}", [
            'nos_per_tray' => 120,
            'trays_per_box' => 4,
            'nos_per_box' => 480,
        ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_tray', 120)
            ->assertJsonPath('data.trays_per_box', 4)
            ->assertJsonPath('data.nos_per_box', 480);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'nos_per_tray' => 120,
            'trays_per_box' => 4,
            'nos_per_box' => 480,
        ]);

        // The standards are clearable — a wrong data load must not be sticky.
        $this->putJson("/api/v1/inventory/items/{$item->id}", ['nos_per_tray' => null])
            ->assertOk()
            ->assertJsonPath('data.nos_per_tray', null);
    }

    public function test_item_packing_fields_reject_zero_and_negative(): void
    {
        $this->actingAsUserWithPermission('inventory.manage');
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['nos_per_tray' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nos_per_tray']);

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['trays_per_box' => -3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trays_per_box']);

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['nos_per_box' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nos_per_box']);
    }

    public function test_items_without_standards_still_return_null_packing_fields(): void
    {
        // The data load arrives later — until then every item must expose
        // the keys as null so the frontend can keep the form fully manual.
        $this->actingAsUserWithPermission('inventory.view');
        Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);

        $this->getJson('/api/v1/inventory/items')
            ->assertOk()
            ->assertJsonPath('data.0.nos_per_tray', null)
            ->assertJsonPath('data.0.trays_per_box', null)
            ->assertJsonPath('data.0.nos_per_box', null);
    }

    public function test_complete_batch_stores_helper_name_and_the_resource_returns_it(): void
    {
        $this->actingAsUserWithPermission('production.manage');
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1000',
            'helper_name' => 'Murugan',
        ])
            ->assertOk()
            ->assertJsonPath('data.helper_name', 'Murugan');

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry->id,
            'helper_name' => 'Murugan',
        ]);
    }

    public function test_complete_batch_without_helper_name_behaves_as_before(): void
    {
        $this->actingAsUserWithPermission('production.manage');
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1000',
        ])
            ->assertOk()
            ->assertJsonPath('data.helper_name', null)
            ->assertJsonPath('data.batch_status', 'completed');
    }

    public function test_complete_batch_rejects_an_overlong_helper_name(): void
    {
        $this->actingAsUserWithPermission('production.manage');
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1000',
            'helper_name' => str_repeat('x', 121),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['helper_name']);
    }
}
