<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /sales/availability — WHAT THE DESK MAY PROMISE, as the order is typed.
 *
 * Four figures per item and no fifth. The three things this file exists to
 * pin:
 *
 *   1. free is what is left AFTER other people's holds, not what the shelf
 *      says — a desk promising held stock is how two customers are sold the
 *      same pallet;
 *   2. an over-promise is PRINTED (S8), never clamped away into a silent
 *      zero;
 *   3. the SALES desk reads it holding no inventory permission at all —
 *      that is the whole reason it lives on this surface — and FC-06 keeps
 *      every cost off it.
 */
class SalesAvailabilityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $fg;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        // The ONE Tally-linked active warehouse, so FactoryWarehouseResolver
        // resolves finished goods without a setting — this factory's reality.
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);
    }

    public function test_free_is_what_is_left_after_somebody_elses_hold(): void
    {
        $this->seedStock('500');
        $this->hold($this->line('200'), '120');

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/availability?item_ids[]='.$this->bottle->id)
            ->assertOk()
            ->assertJsonPath('data.0.item_id', $this->bottle->id)
            ->assertJsonPath('data.0.on_hand', '500.0000')
            ->assertJsonPath('data.0.reserved', '120.0000')
            ->assertJsonPath('data.0.free', '380.0000')
            ->assertJsonPath('data.0.over_reserved', '0.0000');
    }

    /**
     * S8. Holds CAN exceed the balance without anybody doing anything wrong —
     * QC can net stock away after it was held. The desk is told the size of
     * the hole rather than being shown a shelf that promises nothing for no
     * stated reason.
     */
    public function test_an_over_promise_is_printed_rather_than_clamped_into_silence(): void
    {
        $this->seedStock('100');
        $this->hold($this->line('100'), '100');
        // QC nets 60 away after the hold was taken.
        app(StockMovementService::class)->recordIssue(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: '60',
            reference: 'qc netting',
        );

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/availability?item_ids[]='.$this->bottle->id)
            ->assertOk()
            ->assertJsonPath('data.0.on_hand', '40.0000')
            ->assertJsonPath('data.0.reserved', '100.0000')
            ->assertJsonPath('data.0.free', '0.0000')
            ->assertJsonPath('data.0.over_reserved', '60.0000');
    }

    /**
     * An item the factory has never held answers four zeroes, not a 422.
     * A desk typing an order is asking "how many can I promise?", and for a
     * product that is not there the honest answer is none.
     */
    public function test_an_item_with_no_balance_row_answers_zeroes(): void
    {
        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/availability?item_ids[]='.$this->bottle->id)
            ->assertOk()
            ->assertJsonPath('data.0.on_hand', '0.0000')
            ->assertJsonPath('data.0.free', '0.0000');
    }

    public function test_asking_for_nothing_is_refused(): void
    {
        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/availability')
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_ids');
    }

    public function test_more_items_than_an_order_could_carry_is_refused(): void
    {
        $this->actingWith(['sales.view']);

        $ids = implode('&', array_map(fn (int $n) => "item_ids[]={$n}", range(1, 201)));

        $this->getJson("/api/v1/sales/availability?{$ids}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_ids');
    }

    // ---- the permission wall, both ways ------------------------------------

    public function test_the_sales_desk_reads_it_holding_no_inventory_permission(): void
    {
        $this->seedStock('10');
        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/availability?item_ids[]='.$this->bottle->id)->assertOk();
    }

    public function test_an_inventory_login_alone_cannot_read_the_sales_surface(): void
    {
        $this->actingWith(['inventory.manage']);

        $this->getJson('/api/v1/sales/availability?item_ids[]='.$this->bottle->id)->assertStatus(403);
    }

    public function test_a_login_with_neither_sees_nothing(): void
    {
        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/sales/availability?item_ids[]='.$this->bottle->id)->assertStatus(403);
    }

    /**
     * FC-06 / S13. stock_balances carries an average_cost, and a salesperson
     * with no finance standing must never be shown what the factory paid.
     * Asserted on the encoded payload, so a future key cannot slip past a
     * shape assertion.
     */
    public function test_no_cost_field_reaches_a_desk_with_no_finance_permission(): void
    {
        $this->seedStock('500');
        $this->actingWith(['sales.view', 'sales.manage']);

        $body = json_encode(
            $this->getJson('/api/v1/sales/availability?item_ids[]='.$this->bottle->id)->assertOk()->json()
        );

        foreach (['cost', 'rate', 'amount', 'price', 'vendor', 'supplier'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "FC-06: availability must not print {$forbidden}");
        }
    }

    // ---- fixtures ----------------------------------------------------------

    private function seedStock(string $quantity): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: $quantity,
            unitCost: '2.50',
            reference: 'seed',
        );
    }

    private function line(string $quantity): SalesOrderLine
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
        ]);

        return $order->lines()->create([
            'item_id' => $this->bottle->id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
            'quantity_delivered' => 0,
        ]);
    }

    private function hold(SalesOrderLine $line, string $quantity): void
    {
        app(StockReservationService::class)->reserve($line, $quantity, null);
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
