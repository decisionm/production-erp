<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Database\Seeders\ShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The shift lifecycle contract, pinned against the shape LIVE actually has.
 *
 * The live database carries SIX shift rows: the factory's three (Shift A/B/C,
 * active) plus Morning/Afternoon/Night — deactivated leftovers of the rename
 * era (DEC-20260806-007, seeder incident PR #125) that historical production
 * still references and that must therefore never be deleted. The dashboard
 * shift rail consumed the raw list and drew six segments on live; that
 * failure mode and its contract are what this test freezes:
 *
 *  - operational surfaces (pickers, the rail, new transactions) see and
 *    accept ACTIVE shifts only;
 *  - history keeps resolving a shift that has since been deactivated;
 *  - admin/history reads still see everything;
 *  - the seeder can neither recreate nor reactivate a retired name.
 */
class ShiftLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, Shift> */
    private array $active = [];

    /** @var array<int, Shift> */
    private array $retired = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.enforced' => false]);

        // The live shape, exactly: 3 active + 3 inactive legacy rows.
        foreach ([['Shift A', '06:00', '14:00'], ['Shift B', '14:00', '22:00'], ['Shift C', '22:00', '06:00']] as [$name, $start, $end]) {
            $this->active[] = Shift::create(['name' => $name, 'start_time' => $start, 'end_time' => $end, 'is_active' => true]);
        }
        foreach ([['Morning', '06:00', '14:00'], ['Afternoon', '14:00', '22:00'], ['Night', '22:00', '06:00']] as [$name, $start, $end]) {
            $this->retired[] = Shift::create(['name' => $name, 'start_time' => $start, 'end_time' => $end, 'is_active' => false]);
        }

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    public function test_the_raw_list_carries_six_rows_and_the_operational_contract_three(): void
    {
        // The raw list is what the dashboard rail consumed — six rows is the
        // exact shape that drew six segments on live.
        $all = $this->getJson('/api/v1/production/shifts')->assertOk()->json('data');
        $this->assertCount(6, $all);

        // The operational contract: active only, the factory's three.
        $active = $this->getJson('/api/v1/production/shifts?active=1')->assertOk()->json('data');
        $this->assertSame(['Shift A', 'Shift B', 'Shift C'], array_column($active, 'name'));
    }

    public function test_a_new_batch_cannot_start_on_a_retired_shift(): void
    {
        $machine = WorkCenter::create(['code' => 'ASB-1', 'name' => 'Machine 1', 'is_active' => true]);
        $product = Item::create(['sku' => 'BTL', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);

        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->retired[0]->id,
            'work_center_id' => $machine->id,
            'item_id' => $product->id,
            'production_date' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    }

    public function test_a_new_downtime_log_cannot_name_a_retired_shift(): void
    {
        $machine = WorkCenter::create(['code' => 'ASB-1', 'name' => 'Machine 1', 'is_active' => true]);

        $this->postJson('/api/v1/production/machine-downtime-logs', [
            'work_center_id' => $machine->id,
            'shift_id' => $this->retired[1]->id,
            'nature_of_problem' => 'heater fault',
        ])->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    }

    public function test_a_mold_change_log_cannot_name_a_retired_shift(): void
    {
        $machine = WorkCenter::create(['code' => 'ASB-1', 'name' => 'Machine 1', 'is_active' => true]);

        // Other required fields deliberately omitted — the pin is shift_id's
        // own error, which reports regardless of its neighbours.
        $this->postJson('/api/v1/production/mold-change-logs', [
            'work_center_id' => $machine->id,
            'shift_id' => $this->retired[2]->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    }

    public function test_a_batch_preview_cannot_name_a_retired_shift(): void
    {
        $product = Item::create(['sku' => 'BTL', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);

        $this->getJson('/api/v1/production/shift-production-entries/preview?item_id='.$product->id.'&shift_id='.$this->retired[0]->id)
            ->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    }

    public function test_a_handover_cannot_bring_in_a_retired_shift(): void
    {
        config(['production.traceability_enabled' => true]);

        Warehouse::create([
            'code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true,
            'tally_guid' => '7cabb80e-0000-0000-0000-00000000003e',
        ]);
        $machine = WorkCenter::create(['code' => 'ASB-1', 'name' => 'Machine 1', 'is_active' => true]);
        $product = Item::create(['sku' => 'BTL', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);

        $entry = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->active[0]->id,
            'work_center_id' => $machine->id,
            'item_id' => $product->id,
            'production_date' => now()->toDateString(),
        ])->assertOk()->json('data.id');

        // The INCOMING shift is a new operational choice — a retired row may
        // not take it, whatever else the payload is missing.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry}/handover", [
            'shift_id' => $this->retired[1]->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    }

    public function test_history_still_resolves_a_shift_retired_after_the_fact(): void
    {
        // The single Tally-linked warehouse the finished-goods fallback needs.
        Warehouse::create([
            'code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true,
            'tally_guid' => '7cabb80e-0000-0000-0000-00000000003e',
        ]);
        $machine = WorkCenter::create(['code' => 'ASB-1', 'name' => 'Machine 1', 'is_active' => true]);
        $product = Item::create(['sku' => 'BTL', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);

        // Filed while the shift was active — that is what makes it history.
        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->active[0]->id,
            'work_center_id' => $machine->id,
            'item_id' => $product->id,
            'production_date' => now()->toDateString(),
        ])->assertOk();

        $this->active[0]->forceFill(['is_active' => false])->save();

        // The old record keeps its shift, name and all: deactivation retires
        // a shift from NEW work, never from what already happened.
        $this->getJson('/api/v1/production/shift-production-entries')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Shift A']);
    }

    public function test_the_seeder_neither_recreates_nor_reactivates_retired_names(): void
    {
        $this->seed(ShiftSeeder::class);

        $this->assertSame(6, Shift::query()->count());
        $this->assertSame(3, Shift::query()->where('is_active', true)->count());
        foreach (['Morning', 'Afternoon', 'Night'] as $name) {
            $this->assertFalse((bool) Shift::query()->where('name', $name)->value('is_active'));
        }
    }
}
