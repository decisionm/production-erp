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
 * A cycle-time override outside the configuration's own cycle_time_min/max
 * is refused whatever the reason — see
 * ProductionConfigurationService::assertCycleTimeAllowed().
 */
class OverrideReasonRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $fgStore;

    private WorkCenter $machine;

    private Shift $shift;

    private Item $item;

    /**
     * The shared fixture, built ONCE here rather than lazily inside
     * readyItem()/supervisor() helpers called from startPayload() (the
     * original shape). That made startPayload() below a pure array
     * builder with no side effects — safe for a test to call it, or post
     * to the endpoint, any number of times without re-inserting the item,
     * its BOM or its APPROVED configuration.
     */
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

        $this->item = Item::create([
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
        $bom = Bom::create(['item_id' => $this->item->id, 'name' => 'BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0315']);

        ProductionConfiguration::create([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->item->id,
            'status' => ConfigurationStatus::Approved->value,
            'default_cycle_time' => 10,
            'cycle_time_min' => 8,
            'cycle_time_max' => 14,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);
    }

    private function startPayload(array $overrides = []): array
    {
        return [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->fgStore->id,
            ...$overrides,
        ];
    }

    /**
     * A second machine + product + APPROVED configuration, independent of
     * the shared fixture above — for the two tests below that vary the
     * MACHINE's own cycle_time_min/max, without touching the four tests
     * that rely on setUp()'s fixture staying exactly as it is.
     */
    private function readyItemWithConfiguration(WorkCenter $machine, string $sku, array $configOverrides = []): Item
    {
        $item = Item::create([
            'sku' => $sku,
            'name' => '500ml Round Amber',
            'uom' => 'Nos.',
            'is_active' => true,
            'nominal_weight_grams' => '31.5000',
            'standard_cycle_time' => '12.00',
            'standard_cavities' => 5,
            'nos_per_box' => 800,
            'nos_per_tray' => 40,
            'colour' => 'Amber',
            'tally_stock_item_guid' => 'itm-'.$sku,
        ]);

        $resin = Item::create(['sku' => 'PET-'.$sku, 'name' => 'Billion Pet Resin', 'uom' => 'Kgs.', 'is_active' => true]);
        $bom = Bom::create(['item_id' => $item->id, 'name' => 'BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0315']);

        ProductionConfiguration::create($configOverrides + [
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'status' => ConfigurationStatus::Approved->value,
        ]);

        return $item->fresh();
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

    public function test_a_reason_never_bypasses_the_limit(): void
    {
        $payload = $this->startPayload(['cycle_time_override' => 20, 'override_reason' => 'Trying it']);
        $this->postJson('/api/v1/production/shift-production-entries', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['cycle_time_override']);
    }

    public function test_a_reason_never_bypasses_the_minimum(): void
    {
        $payload = $this->startPayload(['cycle_time_override' => 5, 'override_reason' => 'Trying it']);
        $this->postJson('/api/v1/production/shift-production-entries', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['cycle_time_override']);
    }

    public function test_a_configuration_with_no_bounds_falls_back_to_the_machines(): void
    {
        $machine = WorkCenter::create([
            'code' => 'MC-02', 'name' => 'Machine 2', 'is_active' => true,
            'cycle_time_min' => 8, 'cycle_time_max' => 14,
        ]);
        // Deliberately no cycle_time_min/max on the configuration itself.
        $item = $this->readyItemWithConfiguration($machine, 'BTL-501', ['default_cycle_time' => 10]);

        $payload = $this->startPayload([
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'cycle_time_override' => 20,
            'override_reason' => 'Trying it',
        ]);

        $this->postJson('/api/v1/production/shift-production-entries', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['cycle_time_override']);
    }

    public function test_a_configurations_own_bound_wins_over_the_machines(): void
    {
        $machine = WorkCenter::create([
            'code' => 'MC-03', 'name' => 'Machine 3', 'is_active' => true,
            'cycle_time_min' => 8, 'cycle_time_max' => 14,
        ]);
        // The configuration's own range is WIDER than the machine's — 20 is
        // refused by the machine's max of 14 but allowed by the
        // configuration's own max of 20, and the configuration must win,
        // the same precedent assertCavitiesAllowed() sets for cavities.
        $item = $this->readyItemWithConfiguration($machine, 'BTL-502', [
            'default_cycle_time' => 10, 'cycle_time_min' => 4, 'cycle_time_max' => 20,
        ]);

        $payload = $this->startPayload([
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'cycle_time_override' => 20,
            'override_reason' => 'Trying it',
        ]);

        $this->postJson('/api/v1/production/shift-production-entries', $payload)->assertOk();
    }
}
