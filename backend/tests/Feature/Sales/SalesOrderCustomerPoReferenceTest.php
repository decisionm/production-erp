<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE CUSTOMER'S OWN PO NUMBER on a sales order.
 *
 * "SO-{id}" is the ERP's number and nobody outside this building uses it. The
 * customer quotes THEIR purchase order number, and that string is what a
 * person matches an order to an invoice with. So it is recorded as typed:
 * optional (plenty of orders arrive by phone with none — and an invented
 * reference would be a fabricated factory value, AGENTS.md), free-text (every
 * customer numbers their POs differently), and not unique (one PO can cover
 * several orders).
 *
 * IT REACHES NOTHING ELSE IN THIS BUILD. No Tally voucher is emitted from it —
 * whether the ERP may emit a Sales Order voucher at all is an open owner
 * question — so what is pinned here is that the value is accepted, stored
 * exactly as sent, and absent rather than invented when nobody sends one.
 */
class SalesOrderCustomerPoReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->userWith('Sales Desk', ['sales.view', 'sales.manage']));

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'is_active' => true]);
    }

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

    /** @param  array<string, mixed>  $overrides */
    private function raise(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $this->customer->id,
            'order_date' => '2026-08-26',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '2000', 'unit_price' => '4.50']],
            ...$overrides,
        ]);
    }

    public function test_an_order_records_the_customers_po_number_as_sent(): void
    {
        $id = $this->raise(['customer_po_reference' => 'PO/2026-27/0481'])
            ->assertSuccessful()
            ->assertJsonPath('data.customer_po_reference', 'PO/2026-27/0481')
            ->json('data.id');

        // BOTH HALVES. The stored row, because that is what an invoice is
        // later matched against; and the payload, because the drawer the
        // sales desk reads it in gets it from there (the resource key landed
        // with the sibling lane — see B2-handoff.md).
        $this->assertSame('PO/2026-27/0481', SalesOrder::findOrFail($id)->customer_po_reference);
    }

    public function test_an_order_raised_without_one_stores_null_rather_than_a_blank_invention(): void
    {
        $id = $this->raise()->assertSuccessful()->json('data.id');

        $this->assertNull(SalesOrder::findOrFail($id)->customer_po_reference);
    }

    public function test_two_orders_may_quote_the_same_po(): void
    {
        // One customer PO routinely covers several deliveries, and two
        // customers may legitimately use the same number — the column is
        // theirs, not the ERP's, so nothing here is unique.
        $first = $this->raise(['customer_po_reference' => 'PO-77'])->assertSuccessful()->json('data.id');
        $second = $this->raise(['customer_po_reference' => 'PO-77'])->assertSuccessful()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, SalesOrder::where('customer_po_reference', 'PO-77')->count());
    }

    public function test_a_reference_longer_than_the_column_is_refused_not_truncated(): void
    {
        $this->raise(['customer_po_reference' => str_repeat('X', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_po_reference');
    }
}
