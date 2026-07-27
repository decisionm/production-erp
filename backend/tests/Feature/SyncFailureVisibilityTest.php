<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A failed Tally post must never be invisible: the approval list exposes
 * the latest sync error on failed entries so the floor sees exactly what
 * Tally rejected (found live on go-live testing day — a failed entry
 * appeared in no tab and carried no error).
 */
class SyncFailureVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_entry_lists_with_its_latest_sync_error(): void
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'Finished Goods']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-27',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Failed,
        ]);

        $entry->tallySyncEntries()->create([
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => 'SPE-'.$entry->id],
            'status' => 'failed',
            'attempts' => 1,
            'error_message' => 'connect ECONNREFUSED 127.0.0.1:9000',
        ]);

        $viewer = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $viewer->givePermissionTo('production.view');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/production/shift-production-entries?status=failed')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonPath('data.0.sync_error', 'connect ECONNREFUSED 127.0.0.1:9000');
    }
}
