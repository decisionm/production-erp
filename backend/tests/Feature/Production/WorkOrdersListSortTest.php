<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /production/work-orders — the shared list contract (ListSort,
 * 03-Sep-2026), plus the one rule of its own: an undated work order sorts
 * last whichever way the scheduled-date arrow points.
 */
class WorkOrdersListSortTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        $this->item = Item::create(['sku' => 'BTL-A', 'name' => 'Bottle A', 'uom' => 'Nos']);
        $this->store = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
    }

    private function workOrder(string $planned, ?string $scheduled = null): WorkOrder
    {
        return WorkOrder::create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->store->id,
            'quantity_planned' => $planned,
            'quantity_completed' => '0',
            'scheduled_date' => $scheduled,
            'status' => 'draft',
        ]);
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->getJson('/api/v1/production/work-orders?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_a_descending_sort_tiebreaks_on_id_desc(): void
    {
        $one = $this->workOrder('10');
        $two = $this->workOrder('20');
        $three = $this->workOrder('20');

        $ids = array_column(
            $this->getJson('/api/v1/production/work-orders?sort=-quantity_planned')->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$three->id, $two->id, $one->id], $ids);
    }

    public function test_an_undated_work_order_sorts_last_in_both_directions(): void
    {
        $undated = $this->workOrder('10');
        $early = $this->workOrder('10', '2026-09-01');
        $late = $this->workOrder('10', '2026-09-05');

        $ascending = array_column($this->getJson('/api/v1/production/work-orders?sort=scheduled_date')->json('data'), 'id');
        $descending = array_column($this->getJson('/api/v1/production/work-orders?sort=-scheduled_date')->json('data'), 'id');

        $this->assertSame([$early->id, $late->id, $undated->id], $ascending);
        $this->assertSame([$late->id, $early->id, $undated->id], $descending);
    }

    public function test_per_page_cuts_a_real_page_with_the_real_total(): void
    {
        $this->workOrder('10');
        $this->workOrder('10');
        $this->workOrder('10');

        $response = $this->getJson('/api/v1/production/work-orders?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
