<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\SubcontractOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /production/subcontract-orders — the shared list contract (ListSort, 03-Sep-2026).
 */
class SubcontractOrdersListSortTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private Warehouse $store;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        $this->item = Item::create(['sku' => 'BTL-A', 'name' => 'Bottle A', 'uom' => 'Nos']);
        $this->store = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
        $this->vendor = Vendor::create(['code' => 'JOB-A', 'name' => 'Job Worker A']);
    }

    private function order(string $planned): SubcontractOrder
    {
        return SubcontractOrder::create([
            'vendor_id' => $this->vendor->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->store->id,
            'quantity_planned' => $planned,
            'quantity_received' => '0',
            'status' => 'draft',
        ]);
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->getJson('/api/v1/production/subcontract-orders?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_a_descending_sort_tiebreaks_on_id_desc(): void
    {
        $one = $this->order('10');
        $two = $this->order('20');
        $three = $this->order('20');

        $ids = array_column(
            $this->getJson('/api/v1/production/subcontract-orders?sort=-quantity_planned')->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$three->id, $two->id, $one->id], $ids);
    }

    public function test_per_page_cuts_a_real_page_with_the_real_total(): void
    {
        $this->order('10');
        $this->order('10');
        $this->order('10');

        $response = $this->getJson('/api/v1/production/subcontract-orders?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
