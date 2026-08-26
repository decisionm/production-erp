<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `item_group` ON ItemResource — PRESENT WHERE IT WAS ASKED FOR, ABSENT
 * EVERYWHERE ELSE, AND NEVER LAZY-LOADED.
 *
 * ItemResource is not just the item list's shape. It is EMBEDDED in ~35
 * other resources — SalesOrderLineResource, PurchaseOrderLineResource,
 * DeliveryLineResource, InvoiceLineResource, StockBalanceResource,
 * BatchResource, BomLineResource and the rest — every one of them through
 * `ItemResource::make($this->whenLoaded('item'))`, and NOT ONE of them eager
 * -loads `item.group`. So a plain `$item->group?->name` in that resource is
 * one lazy query PER LINE on every one of those screens, and it breaks
 * SalesOrderService::WITH's stated invariant that "the resource never
 * lazy-loads" without anything going red.
 *
 * The gate is `whenLoaded`, which makes the key ABSENT rather than wrong,
 * and the endpoints that owe a person the group load it themselves. That is
 * a two-sided contract and both sides are pinned here — a future edit that
 * drops the gate passes the first three tests and fails the fourth, and one
 * that drops the eager-loads passes the fourth and fails the first three.
 *
 * All data synthetic (AGENTS.md, FC-06): no real customer or product name,
 * and no rate anywhere.
 */
class ItemResourceGroupLoadingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsManager(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ([...$permissions, 'inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function groupedItem(): Item
    {
        $group = ItemGroup::create(['name' => 'Synthetic Packing Group']);

        return Item::create([
            'sku' => 'SYN-GRP-1',
            'name' => 'Synthetic Grouped Bottle',
            'uom' => 'Nos.',
            'item_group_id' => $group->id,
        ]);
    }

    public function test_the_item_list_states_the_group(): void
    {
        $this->actingAsManager();
        $this->groupedItem();

        $row = collect($this->getJson('/api/v1/inventory/items')->assertSuccessful()->json('data'))
            ->firstWhere('sku', 'SYN-GRP-1');

        $this->assertIsArray($row);
        $this->assertArrayHasKey('item_group', $row);
        $this->assertSame('Synthetic Packing Group', $row['item_group']);
    }

    public function test_showing_one_item_states_the_group(): void
    {
        $this->actingAsManager();
        $item = $this->groupedItem();

        $this->getJson("/api/v1/inventory/items/{$item->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.item_group', 'Synthetic Packing Group');
    }

    /**
     * The write actions answer with the group too. This is the path a form
     * patches its row from after a save: if `update` stopped stating the
     * group, the Group column would blank out on exactly the row somebody
     * just edited, with no error anywhere.
     */
    public function test_updating_an_item_answers_with_the_group_still_stated(): void
    {
        $this->actingAsManager();
        $item = $this->groupedItem();

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['display_name' => 'Synthetic ERP label'])
            ->assertSuccessful()
            ->assertJsonPath('data.item_group', 'Synthetic Packing Group')
            ->assertJsonPath('data.display_name', 'Synthetic ERP label');
    }

    /**
     * THE ONE THAT MATTERS — a nested ItemResource must not touch
     * `item_groups` AT ALL. Counted rather than merely asserted absent: an
     * ungated read on a null group also yields a null key, so "the value is
     * null" would pass while the queries were being fired. Zero is the only
     * honest assertion.
     */
    public function test_a_sales_order_renders_its_line_items_without_querying_item_groups(): void
    {
        $this->actingAsManager('sales.view');

        $item = $this->groupedItem();
        $second = Item::create([
            'sku' => 'SYN-GRP-2',
            'name' => 'Synthetic Grouped Jar',
            'uom' => 'Nos.',
            'item_group_id' => $item->item_group_id,
        ]);

        $customer = Customer::create(['code' => 'SYN-CUST-1', 'name' => 'Synthetic Trading Co']);
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
        ]);
        foreach ([$item, $second] as $line) {
            $order->lines()->create([
                'item_id' => $line->id,
                'quantity' => '10',
                'unit_price' => '1.00',
                'quantity_delivered' => 0,
            ]);
        }

        $groupQueries = 0;
        DB::listen(function ($query) use (&$groupQueries): void {
            if (str_contains($query->sql, 'item_groups')) {
                $groupQueries++;
            }
        });

        $response = $this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertSuccessful();

        $this->assertSame(0, $groupQueries, 'A sales order must not lazy-load an item group per line.');

        $lines = $response->json('data.lines');
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertArrayNotHasKey('item_group', $line['item']);
        }
    }
}
