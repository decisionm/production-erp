<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionConfigurationService;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The factory's ten machines are the only ones production may pick, they
 * sort naturally, and the demo stations they replace survive as history.
 */
class CanonicalMachineTest extends TestCase
{
    use RefreshDatabase;

    private function seedLegacyStations(): array
    {
        // What BottleManufacturingDemoSeeder leaves behind.
        return collect(['INJ-01' => 'Injection Molding Machine 1', 'BLOW-01' => 'Stretch Blow Molding Machine 1',
            'EBM-01' => 'Extrusion Blow Molding Machine 1', 'LABEL-01' => 'Labeling Station', 'PACK-01' => 'Packing Station'])
            ->map(fn ($name, $code) => WorkCenter::create(['code' => $code, 'name' => $name, 'is_active' => true]))
            // values(): the source map is keyed by code, and callers index
            // this numerically.
            ->values()
            ->all();
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'production.view'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_selector_offers_exactly_the_ten_canonical_machines(): void
    {
        $this->seedLegacyStations();
        $this->seed(CanonicalMachineSeeder::class);
        $this->actor();

        $names = collect($this->getJson('/api/v1/production/work-centers?active=1')->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        $this->assertSame([
            'Machine 1', 'Machine 2', 'Machine 3', 'Machine 4', 'Machine 5',
            'Machine 6', 'Machine 7', 'Machine 8', 'Machine 9', 'Machine 10',
        ], $names);
    }

    public function test_machines_sort_numerically_not_alphabetically(): void
    {
        $this->seed(CanonicalMachineSeeder::class);
        $this->actor();

        $codes = collect($this->getJson('/api/v1/production/work-centers?active=1')->json('data'))
            ->pluck('code')
            ->all();

        // The failure this guards: alphabetical order puts Machine 10
        // immediately after Machine 1, so a supervisor tapping the second
        // card starts the wrong machine.
        $this->assertSame('MC-09', $codes[8]);
        $this->assertSame('MC-10', $codes[9]);
        $this->assertLessThan(
            array_search('MC-10', $codes, true),
            array_search('MC-02', $codes, true),
            'Machine 2 must sort before Machine 10.',
        );
    }

    public function test_legacy_stations_are_deactivated_and_never_deleted(): void
    {
        $legacy = $this->seedLegacyStations();
        $this->seed(CanonicalMachineSeeder::class);

        foreach ($legacy as $station) {
            $fresh = WorkCenter::find($station->id);
            // The row survives — entries reference it and deleting would
            // orphan production history.
            $this->assertNotNull($fresh, "{$station->code} must not be deleted.");
            $this->assertFalse($fresh->is_active, "{$station->code} must be deactivated.");
            $this->assertSame($station->id, $fresh->id, 'IDs must be preserved.');
        }

        $this->actor();
        $active = collect($this->getJson('/api/v1/production/work-centers?active=1')->json('data'))->pluck('code');
        $this->assertFalse($active->contains('INJ-01'));

        // Still findable for the admin screen's Inactive filter.
        $inactive = collect($this->getJson('/api/v1/production/work-centers?active=0')->json('data'))->pluck('code');
        $this->assertTrue($inactive->contains('INJ-01'));
    }

    public function test_an_inactive_legacy_machine_cannot_start_a_batch(): void
    {
        $legacy = $this->seedLegacyStations();
        $this->seed(CanonicalMachineSeeder::class);

        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $item = Item::create([
            'sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12', 'standard_cycle_time' => '12', 'standard_cavities' => 5,
            'nos_per_box' => 840, 'colour' => 'Amber', 'tally_stock_item_guid' => 'itm-1',
        ]);

        // The gate must refuse it server-side — a stale browser tab or a
        // direct API call must not be able to run a retired machine.
        config()->set('production.readiness.enforced', true);
        $this->actor();

        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $legacy[0]->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
        ])->assertStatus(422)->assertJsonPath('blocking.0.code', 'machine_active');

        $this->assertDatabaseCount('shift_production_entries', 0);
    }

    public function test_reseeding_preserves_ids_and_does_not_duplicate(): void
    {
        $this->seed(CanonicalMachineSeeder::class);
        $first = WorkCenter::where('code', 'MC-01')->firstOrFail();

        // A configuration and an entry reference this machine — the seeder
        // running again must not orphan either.
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);
        $config = app(ProductionConfigurationService::class)->create([
            'work_center_id' => $first->id, 'item_id' => $item->id,
            'default_cycle_time' => '12', 'default_cavities' => 5, 'unit_weight_grams' => '12',
        ], null);

        $this->seed(CanonicalMachineSeeder::class);
        $this->seed(CanonicalMachineSeeder::class);

        $this->assertSame(10, WorkCenter::where('code', 'like', 'MC-%')->count(), 'Re-running must not duplicate.');
        $this->assertSame($first->id, WorkCenter::where('code', 'MC-01')->first()->id);
        $this->assertSame($first->id, ProductionConfiguration::find($config->id)->work_center_id);
    }

    public function test_a_name_the_factory_corrected_is_not_overwritten_by_reseeding(): void
    {
        $this->seed(CanonicalMachineSeeder::class);

        $machine = WorkCenter::where('code', 'MC-03')->firstOrFail();
        $machine->update(['name' => 'ASB-3 Big Blow']);

        $this->seed(CanonicalMachineSeeder::class);

        // Master data the factory has edited outranks a redeploy.
        $this->assertSame('ASB-3 Big Blow', $machine->fresh()->name);
        $this->assertSame(3, $machine->fresh()->display_sequence);
    }

    public function test_configurations_reference_canonical_machine_ids(): void
    {
        $this->seed(CanonicalMachineSeeder::class);
        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $item = Item::create([
            'sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12',
        ]);

        $config = app(ProductionConfigurationService::class)->create([
            'work_center_id' => $machine->id, 'item_id' => $item->id,
            'default_cycle_time' => '12', 'default_cavities' => 5, 'unit_weight_grams' => '12',
        ], null);
        app(ProductionConfigurationService::class)->approve($config, null);

        $this->actor();
        $this->getJson("/api/v1/production/work-centers/{$machine->id}/configurations")
            ->assertOk()
            ->assertJsonPath('data.0.work_center.id', $machine->id);
    }

    public function test_history_on_a_deactivated_station_is_still_readable(): void
    {
        $legacy = $this->seedLegacyStations();
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'Nos.']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $legacy[0]->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'production_date' => '2026-07-20',
            'batch_number' => '20260720-M01-001', 'batch_status' => 'completed',
            'quantity_produced' => '100', 'quantity_scrap' => '0',
        ]);

        $this->seed(CanonicalMachineSeeder::class);

        $this->actor();
        $row = collect($this->getJson('/api/v1/production/shift-production-entries')->assertOk()->json('data'))
            ->firstWhere('id', $entry->id);

        $this->assertNotNull($row, 'A completed entry must remain visible after its machine is retired.');
        $this->assertSame('Injection Molding Machine 1', $row['work_center']['name']);
    }
}
