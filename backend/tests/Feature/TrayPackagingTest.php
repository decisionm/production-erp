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
 * Packaging combinations at Complete Batch (QA matrix items: tray-only,
 * tray-and-pouch, partial box + loose pieces). Products differ — some pack
 * in trays only, some in pouches only, some in both — so every combination
 * must round-trip independently: what the supervisor counted persists, the
 * container families they did NOT use stay honestly null, and negative
 * counts are refused. Pouch-only is covered by PackagingModelTest.
 */
class TrayPackagingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);

        return $user;
    }

    private function inProgressEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create([
            'sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS',
            'nos_per_tray' => 84, 'trays_per_box' => 6, 'nos_per_box' => 504,
        ]);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'FG Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
            'batch_number' => '20260728-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    public function test_tray_only_completion_persists_trays_and_leaves_box_and_pouch_null(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry();

        // A tray-packed product: 5 trays of 84, no boxes closed, no pouches.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '420',
            'nos_per_tray' => 84,
            'no_of_trays' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_tray', 84)
            ->assertJsonPath('data.no_of_trays', 5)
            ->assertJsonPath('data.no_of_box', null)
            ->assertJsonPath('data.no_of_pouches', null)
            ->assertJsonPath('data.loose_pieces', null)
            ->assertJsonPath('data.metrics.actual_pouches', null);

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry->id,
            'nos_per_tray' => 84,
            'no_of_trays' => 5,
            'no_of_box' => null,
            'no_of_pouches' => null,
        ]);
    }

    public function test_tray_and_pouch_completion_persists_both_container_families(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry();

        // Both families on one product: trays for the clean room, pouches
        // for the auto-packer — neither count may shadow the other.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1265',
            'nos_per_tray' => 84,
            'no_of_trays' => 12,
            'no_of_pouches' => 10,
            'loose_pieces' => 7,
        ])
            ->assertOk()
            ->assertJsonPath('data.no_of_trays', 12)
            ->assertJsonPath('data.no_of_pouches', 10)
            ->assertJsonPath('data.loose_pieces', 7)
            ->assertJsonPath('data.metrics.actual_pouches', 10);

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry->id,
            'no_of_trays' => 12,
            'no_of_pouches' => 10,
            'loose_pieces' => 7,
        ]);
    }

    public function test_partial_box_counts_as_whole_boxes_plus_loose_pieces(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry();

        // Box-first counting with a part-filled last box: the floor closes
        // 2 full boxes of 504 and leaves 257 loose — 1265 pieces total. The
        // app must store exactly what was counted (2 + 257), never a
        // fabricated third box (the ceil() habit Conflict C2 banned).
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1265',
            'nos_per_box' => 504,
            'no_of_box' => 2,
            'loose_pieces' => 257,
        ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_box', 504)
            ->assertJsonPath('data.no_of_box', 2)
            ->assertJsonPath('data.loose_pieces', 257)
            ->assertJsonPath('data.metrics.actual_boxes', 2);

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry->id,
            'no_of_box' => 2,
            'loose_pieces' => 257,
        ]);
    }

    public function test_negative_tray_counts_are_rejected(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '420',
            'nos_per_tray' => -84,
            'no_of_trays' => -5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nos_per_tray', 'no_of_trays']);

        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
    }
}
