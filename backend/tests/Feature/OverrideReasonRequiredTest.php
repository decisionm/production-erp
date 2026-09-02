<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DEC-20260902-021, pinned: with an approved machine configuration, a Start
 * Batch that overrides its cycle time or cavities needs a reason; the
 * snapshot keeps the original, the selected value, the reason and the person.
 *
 * A third pin — a cycle-time override outside the configuration's
 * cycle_time_min/max is refused whatever the reason — is NOT in this file.
 * ProductionConfigurationService::assertCycleTimeAllowed()
 * (ProductionConfigurationService.php:846-852) is deliberately empty: cycle
 * time bounds are advisory only, never blocking (unlike cavities bounds,
 * which assertCavitiesAllowed() at :854-873 does enforce). Reported BLOCKED
 * in the task-4 report per the task's ruling 3 rather than committed red or
 * patched around; see the report for the full failing test and output.
 */
class OverrideReasonRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $fgStore;

    private WorkCenter $machine;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->fgStore = Warehouse::create([
            'code' => 'FG', 'name' => 'FG Store', 'is_active' => true,
            'tally_guid' => 'gd-fg-0001',
        ]);

        config()->set('production.readiness.enforced', true);
    }

    /** A product with every master present, and an APPROVED configuration governing it. */
    private function readyItem(array $overrides = []): Item
    {
        $item = Item::create($overrides + [
            'sku' => 'BTL-500',
            'name' => '500ml Round Amber',
            'uom' => 'Nos.',
            'is_active' => true,
            'nominal_weight_grams' => '31.5000',
            'standard_cycle_time' => '12.00',
            'standard_cavities' => 5,
            'nos_per_box' => 800,
            'nos_per_tray' => 40,
            'colour' => 'Amber',
            'tally_stock_item_guid' => 'itm-0001',
        ]);

        $resin = Item::create(['sku' => 'PET', 'name' => 'Billion Pet Resin', 'uom' => 'Kgs.', 'is_active' => true]);
        $bom = Bom::create(['item_id' => $item->id, 'name' => 'BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0315']);

        ProductionConfiguration::create([
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'status' => ConfigurationStatus::Approved->value,
            'default_cycle_time' => 10,
            'cycle_time_min' => 8,
            'cycle_time_max' => 14,
        ]);

        return $item->fresh();
    }

    private function supervisor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function startPayload(array $overrides = []): array
    {
        $this->supervisor();
        $item = $this->readyItem();

        return [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fgStore->id,
            ...$overrides,
        ];
    }

    public function test_an_override_without_a_reason_is_refused(): void
    {
        $payload = $this->startPayload(['cycle_time_override' => 12]);
        $this->postJson('/api/v1/production/shift-production-entries', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['override_reason']);
    }

    public function test_an_override_with_a_reason_is_recorded_with_the_original(): void
    {
        $payload = $this->startPayload(['cycle_time_override' => 12, 'override_reason' => 'Mould running hot']);
        // ShiftProductionEntryController::store() returns the resource
        // directly (200), matching ProductReadinessGateTest::assertOk() for
        // a successful start — not 201. The brief's assertCreated() assumed
        // REST convention rather than this app's actual return shape.
        $id = $this->postJson('/api/v1/production/shift-production-entries', $payload)->assertOk()->json('data.id');

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $id, 'standard_cycle_time' => 10, 'cycle_time_source' => 'override', 'override_reason' => 'Mould running hot',
        ]);
        $this->assertNotNull(ShiftProductionEntry::find($id)->override_by);
    }
}
