<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\AvailabilityService;
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
 * WHAT MAY STILL BE PROMISED — on_hand, reserved, free, over_reserved, and
 * nothing else.
 *
 * The "nothing else" is the point of half this file. FC-06 (and S13) put
 * purchase rates, supplier identity and average cost out of reach of
 * everyone but Owner/Accounts, and stock_balances — the table this read
 * sits on — carries `average_cost` one column away from `quantity`. The key
 * set is therefore asserted EXACTLY, not by naming the one field we
 * remembered to exclude: `assertArrayNotHasKey('average_cost')` would pass
 * happily on a payload that had grown `unit_cost` or `value`.
 */
class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    /** The four figures this read is allowed to carry, and no fifth. */
    private const EXPECTED_KEYS = ['item_id', 'on_hand', 'reserved', 'free', 'over_reserved'];

    private Item $bottle;

    private Warehouse $fg;

    private Customer $customer;

    private User $store;

    protected function setUp(): void
    {
        parent::setUp();

        // A STOREKEEPER, deliberately without any finance standing — the
        // person this payload is actually built for.
        $this->store = $this->userWith('Storekeeper', ['inventory.view', 'inventory.manage']);
        Sanctum::actingAs($this->store);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
    }

    public function test_it_carries_the_four_figures_and_no_cost_field(): void
    {
        $this->seedStock('500');

        $row = app(AvailabilityService::class)->forItem($this->bottle->id);

        $this->assertSame(self::EXPECTED_KEYS, array_keys($row));
        $this->assertSame('500.0000', $row['on_hand']);
        $this->assertSame('0.0000', $row['reserved']);
        $this->assertSame('500.0000', $row['free']);
        $this->assertSame('0.0000', $row['over_reserved']);
    }

    public function test_an_item_the_factory_holds_none_of_reads_as_zero(): void
    {
        $row = app(AvailabilityService::class)->forItem($this->bottle->id);

        $this->assertSame(self::EXPECTED_KEYS, array_keys($row));
        $this->assertSame('0.0000', $row['on_hand']);
        $this->assertSame('0.0000', $row['free']);
    }

    public function test_holds_come_off_the_free_figure(): void
    {
        $this->seedStock('500');
        app(StockReservationService::class)->reserve($this->line('300'), '300', $this->store->id);

        $row = app(AvailabilityService::class)->forItem($this->bottle->id);

        $this->assertSame('500.0000', $row['on_hand']);
        $this->assertSame('300.0000', $row['reserved']);
        $this->assertSame('200.0000', $row['free']);
        $this->assertSame('0.0000', $row['over_reserved']);
    }

    /**
     * S8 — MORE HELD THAN THERE IS. QC can net stock away after it was held,
     * and a delivery may legally draw against a line whose hold sits
     * elsewhere. `free` clamps at zero (nobody can promise a negative), and
     * the size of the hole is printed as its own figure rather than hidden:
     * a store looking at a full shelf that reserves nothing needs to be told
     * how many pieces are promised twice.
     */
    public function test_an_over_reservation_is_shown_rather_than_hidden(): void
    {
        $this->seedStock('500');
        app(StockReservationService::class)->reserve($this->line('400'), '400', $this->store->id);

        // The stock leaves for another reason entirely — a QC hold, a
        // correction, a legal cross-consumption. The hold does not follow it.
        app(StockMovementService::class)->recordIssue(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: '350',
            reference: 'qc netting',
        );

        $row = app(AvailabilityService::class)->forItem($this->bottle->id);

        $this->assertSame('150.0000', $row['on_hand']);
        $this->assertSame('400.0000', $row['reserved']);
        $this->assertSame('0.0000', $row['free']);
        $this->assertSame('250.0000', $row['over_reserved']);
    }

    public function test_several_items_are_answered_in_one_read(): void
    {
        $jar = Item::create(['sku' => 'JAR-1L', 'name' => '1L PET Jar', 'uom' => 'Nos']);
        $this->seedStock('120');

        $rows = app(AvailabilityService::class)->forItems([$this->bottle->id, $jar->id]);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(self::EXPECTED_KEYS, array_keys($row));
        }
        $this->assertSame('120.0000', $rows[0]['on_hand']);
        $this->assertSame('0.0000', $rows[1]['on_hand']);
    }

    // ---- fixtures ---------------------------------------------------------

    /** @param  list<string>  $permissions */
    private function userWith(string $name, array $permissions): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

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
            'quantity_delivered' => '0',
        ]);
    }
}
