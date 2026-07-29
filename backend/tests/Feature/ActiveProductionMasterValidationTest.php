<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ActiveProductionMasterValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);
    }

    public function test_an_inactive_finished_item_cannot_supersede_its_active_bom(): void
    {
        $finishedItem = $this->item('FG-1', 'Bottle');
        $component = $this->item('RM-1', 'Resin');
        $existing = Bom::create([
            'item_id' => $finishedItem->id,
            'name' => 'Current recipe',
            'version' => '1',
            'is_active' => true,
        ]);
        $finishedItem->update(['is_active' => false]);

        $this->postJson('/api/v1/production/boms', [
            'item_id' => $finishedItem->id,
            'name' => 'Replacement recipe',
            'version' => '2',
            'is_active' => true,
            'lines' => [[
                'component_item_id' => $component->id,
                'quantity_per' => 0.012,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['item_id']);

        $this->assertTrue($existing->fresh()->is_active);
        $this->assertDatabaseCount('boms', 1);
    }

    public function test_an_inactive_component_cannot_be_added_to_a_recipe(): void
    {
        $finishedItem = $this->item('FG-1', 'Bottle');
        $component = $this->item('RM-1', 'Retired Resin', false);

        $this->postJson('/api/v1/production/boms', [
            'item_id' => $finishedItem->id,
            'name' => 'Invalid recipe',
            'version' => '1',
            'is_active' => true,
            'lines' => [[
                'component_item_id' => $component->id,
                'quantity_per' => 0.012,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.component_item_id']);

        $this->assertDatabaseCount('boms', 0);
    }

    public function test_an_inactive_warehouse_cannot_start_a_batch(): void
    {
        [$shift, $machine, $item] = $this->startBatchMasters();
        $warehouse = Warehouse::create([
            'code' => 'FG-INACTIVE',
            'name' => 'Inactive FG Store',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);

        $this->assertDatabaseCount('shift_production_entries', 0);
    }

    public function test_an_inactive_operator_cannot_start_a_batch(): void
    {
        [$shift, $machine, $item] = $this->startBatchMasters();
        $warehouse = Warehouse::create([
            'code' => 'FG',
            'name' => 'FG Store',
            'is_active' => true,
        ]);
        $operator = Employee::create([
            'employee_code' => 'OP-001',
            'name' => 'Inactive Operator',
            'date_of_joining' => now()->subYear()->toDateString(),
            'status' => 'inactive',
        ]);

        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'operator_id' => $operator->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['operator_id']);

        $this->assertDatabaseCount('shift_production_entries', 0);
    }

    private function item(string $sku, string $name, bool $active = true): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $name,
            'uom' => 'Nos.',
            'is_active' => $active,
        ]);
    }

    /**
     * @return array{Shift, WorkCenter, Item}
     */
    private function startBatchMasters(): array
    {
        return [
            Shift::create([
                'name' => 'Morning',
                'start_time' => '06:00',
                'end_time' => '14:00',
            ]),
            WorkCenter::create([
                'code' => 'MC-01',
                'name' => 'Machine 1',
                'is_active' => true,
            ]),
            Item::create([
                'sku' => 'FG-1',
                'name' => 'Bottle',
                'uom' => 'Nos.',
                'is_active' => true,
            ]),
        ];
    }
}
