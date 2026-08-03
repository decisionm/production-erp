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
 * Batch #43's defect, pinned.
 *
 * The run was started with a machine configuration of 4 cavities. At
 * completion a person typed 5. The row then read: standard_cavities 4,
 * active_cavities 5, cavities_source "configuration" — a snapshot asserting
 * that a machine configuration supplied a number no configuration holds.
 *
 * The source field is what anyone auditing efficiency reads first, so a
 * figure a person chose must say so. Completion recalculates it now.
 */
class CompletionOverrideSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('production.readiness.enforced', false);
    }

    /** @return array{0: ShiftProductionEntry, 1: User} */
    private function runningBatch(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);

        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-04', 'name' => 'Machine 4', 'display_sequence' => 4]);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $item = Item::create(['sku' => 'BTL-60', 'name' => '60 Ml Round Amber', 'uom' => 'Nos.', 'nominal_weight_grams' => '10.0000']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-03',
            'batch_number' => '20260803-M04-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
            // What Start Batch froze from the machine configuration.
            'standard_cavities' => 4,
            'active_cavities' => 4,
            'cavities_source' => 'configuration',
            'standard_cycle_time' => '11.50',
        ]);

        return [$entry, $user];
    }

    public function test_changing_active_cavities_at_completion_is_recorded_as_an_override(): void
    {
        [$entry, $user] = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1000',
            'active_cavities' => 5,
            'running_hours' => '8',
        ])->assertOk();

        $entry->refresh();

        $this->assertSame(5, $entry->active_cavities);
        $this->assertSame('override', $entry->cavities_source, 'A figure a person typed must not be attributed to the machine configuration.');
        $this->assertSame($user->id, $entry->override_by);
        // The configuration's own figure is untouched — the override is the
        // run's, not a silent edit of the machine's setup.
        $this->assertSame(4, $entry->standard_cavities);
    }

    public function test_completing_without_changing_active_cavities_keeps_the_original_source(): void
    {
        // The other direction, and the reason this is not simply "always
        // override": echoing the prefilled value back is not a decision, and
        // marking it as one would make every completion look like a deviation.
        [$entry] = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1000',
            'active_cavities' => 4,
            'running_hours' => '8',
        ])->assertOk();

        $entry->refresh();

        $this->assertSame(4, $entry->active_cavities);
        $this->assertSame('configuration', $entry->cavities_source);
        $this->assertNull($entry->override_by);
    }
}
