<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\SubcontractOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHAT JOB WORK COSTS IS NOT THE FLOOR'S TO READ (FC-06).
 *
 * The subcontract order payload is served inside the production module, so its
 * reader is a production login — and it handed that reader `materials_cost`,
 * `service_cost` and `total_cost` in the open. That is what the factory pays an
 * outside processor: the money half of FC-06, "Floor and sales logins never see
 * what a material cost or who supplied it".
 *
 * The gate is PurchaseOrderLineResource::showsCost, the ONE predicate that
 * decides who is served a purchase rate anywhere in this app. Reused rather
 * than re-derived, because a second spelling of the same rule is how two gates
 * come to disagree.
 *
 * OMITTED, NOT NULLED, matching every other rate gate here: an absent key
 * cannot be misread as a cost of zero.
 *
 * The supplier half of FC-06 is NOT tested here, and deliberately so. A
 * production login already reaches every vendor through the picker this
 * screen's own create form uses, so hiding the name on this one payload would
 * close nothing while breaking the screen. That is a wider question and is
 * recorded rather than quietly decided.
 */
class SubcontractCostVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function order(): SubcontractOrder
    {
        $vendor = Vendor::create(['code' => 'V-JOB', 'name' => 'Vendor Alpha']);
        $item = Item::create(['sku' => 'JOB-ITEM', 'name' => 'Job Item', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'WH-JOB', 'name' => 'Job Store', 'is_active' => true]);

        return SubcontractOrder::create([
            'vendor_id' => $vendor->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity_planned' => '100.0000',
            'quantity_received' => '0.0000',
            'materials_cost' => '1500.0000',
            'service_cost' => '500.0000',
            'total_cost' => '2000.0000',
        ]);
    }

    public function test_a_production_login_is_not_served_what_the_job_costs(): void
    {
        $this->order();

        $response = $this->actingAs($this->user('production.view'))
            ->getJson('/api/v1/production/subcontract-orders')
            ->assertOk();

        $row = $response->json('data.0');

        $this->assertArrayNotHasKey('materials_cost', $row, 'the floor was served a purchase cost (FC-06)');
        $this->assertArrayNotHasKey('service_cost', $row);
        $this->assertArrayNotHasKey('total_cost', $row);
    }

    public function test_a_finance_login_is_served_the_costs(): void
    {
        $this->order();

        $response = $this->actingAs($this->user('production.view', 'finance.view'))
            ->getJson('/api/v1/production/subcontract-orders')
            ->assertOk();

        // Compared as numbers: this table carries no decimal cast, so the
        // driver decides whether a string or a float comes back. What matters
        // is that the figure REACHES a finance reader at all.
        $this->assertEqualsWithDelta(1500, (float) $response->json('data.0.materials_cost'), 0.0001);
        $this->assertEqualsWithDelta(500, (float) $response->json('data.0.service_cost'), 0.0001);
        $this->assertEqualsWithDelta(2000, (float) $response->json('data.0.total_cost'), 0.0001);
    }

    /** The gate hides money, not the job — the row is still fully usable. */
    public function test_the_rest_of_the_order_is_unchanged_for_the_floor(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->user('production.view'))
            ->getJson('/api/v1/production/subcontract-orders')
            ->assertOk();

        $this->assertSame($order->id, $response->json('data.0.id'));
        $this->assertEqualsWithDelta(100, (float) $response->json('data.0.quantity_planned'), 0.0001);
        $this->assertNotNull($response->json('data.0.status'));
    }
}
