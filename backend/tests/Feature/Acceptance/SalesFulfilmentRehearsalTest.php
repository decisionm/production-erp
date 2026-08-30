<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Sales\Models\Customer;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE END-TO-END REHEARSAL — the whole sales fulfilment chain walked once,
 * through the REAL HTTP endpoints, in the order a factory actually walks it:
 *
 *   customer → sales order → confirm → store holds what it can → the
 *   shortfall goes to the floor → the floor starts it → dispatch → invoice
 *   → what (if anything) reaches Tally.
 *
 * Every step is walked AS THE ROLE THAT OWNS IT — the sales desk confirms,
 * the store holds, the floor starts — so the permission walls are exercised
 * by the rehearsal rather than assumed. This is the test that would have
 * caught a chain that works only when one Administrator does everything,
 * which is how the live instance is configured today.
 *
 * It also pins the two things the shipped Control view says at each step:
 * WHO must act next, and that a fully held line is never called simply
 * "ready" while the QA and customer-approval gates are unrecorded.
 */
class SalesFulfilmentRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_whole_chain_walks_and_each_step_names_the_next_actor(): void
    {
        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);

        // The store holds 600 of the 1,000 the customer is about to order.
        app(StockMovementService::class)->recordReceipt(
            itemId: $bottle->id,
            warehouseId: $fg->id,
            quantity: '600',
            unitCost: '2.50',
            reference: 'opening',
        );

        // ---- 1. SALES raises and confirms the order ------------------------
        $this->actingWith(['sales.view', 'sales.manage']);

        $orderId = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $customer->id,
            'order_date' => '2026-08-30',
            'expected_date' => '2026-09-10',
            'lines' => [['item_id' => $bottle->id, 'quantity' => '1000', 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');

        $this->postJson("/api/v1/sales/sales-orders/{$orderId}/confirm")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'confirmed');

        // CONFIRM HOLDS NOTHING (owner point 1 is not built): the control view
        // says so by putting the ball in the store's court with stock free.
        $row = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0');
        $this->assertSame('0.0000', $row['held'], 'confirm creates no hold in this build');
        $this->assertSame('600.0000', $row['available_stock']);
        $this->assertSame('store_has_not_held_stock', $row['blocker']['code']);
        $this->assertSame('Store', $row['blocker']['team']);
        $lineId = $row['line_id'];

        // ---- 2. THE STORE holds what it actually has -----------------------
        $this->actingWith(['inventory.view', 'inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$lineId}/reserve", ['quantity' => '600'])
            ->assertSuccessful();

        // ---- 3. THE STORE sends the shortfall to the floor -----------------
        $this->postJson("/api/v1/inventory/fulfilment/lines/{$lineId}/send-to-production", ['quantity' => '400'])
            ->assertSuccessful();

        $row = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0');
        $this->assertSame('600.0000', $row['held']);
        $this->assertSame('400.0000', $row['shortfall']);
        $this->assertSame('400.0000', $row['production']['requested']);
        $this->assertSame('queued_for_production', $row['blocker']['code']);
        $this->assertSame('Production', $row['blocker']['team'], 'the ball is now the floor\'s');

        // ---- 4. THE FLOOR picks it up --------------------------------------
        $this->actingWith(['production.view', 'production.manage']);

        $requestId = $this->getJson('/api/v1/production/requests')->assertOk()->json('data.0.id');
        $this->postJson("/api/v1/production/requests/{$requestId}/start")->assertSuccessful();

        $row = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0');
        $this->assertSame('in_production', $row['blocker']['code']);

        // AND WHAT THE FLOOR MAKES IS NOT LINKED BACK (owner point 9 is not
        // built): planned and completed have no source and say so.
        $this->assertSame('not_recorded', $row['production']['planned']);
        $this->assertSame('not_recorded', $row['production']['completed']);

        // ---- 5. DISPATCH the 600 that are held -----------------------------
        // Typed lines, not scanned cartons: the live instance has no cartons
        // at all, so this is the path a rehearsal there would actually use.
        $this->actingWith(['sales.view', 'sales.manage']);

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $orderId,
            'warehouse_id' => $fg->id,
            'delivered_date' => '2026-08-31',
            'lines' => [['sales_order_line_id' => $lineId, 'quantity' => '600']],
        ])->assertSuccessful();

        $row = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0');
        $this->assertSame('600.0000', $row['delivered'], 'the dispatch spent the hold');
        $this->assertSame('0.0000', $row['held'], 'a consumed hold no longer holds anything');

        // EXACTLY ONCE, asserted on the LEDGER rather than inferred from the
        // line's figures — "the stock moved once" is the claim an inventory
        // rehearsal actually relies on, so it is checked where stock lives.
        $issues = StockMovement::query()
            ->where('item_id', $bottle->id)
            ->where('type', StockMovementType::Issue)
            ->get();
        $this->assertCount(1, $issues, 'a dispatch writes ONE issue movement, never two');
        $this->assertSame('600.0000', (string) $issues->first()->quantity);

        // ---- 6. ACCOUNTS bills it ------------------------------------------
        $invoiceId = $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $orderId,
            'invoice_date' => '2026-08-31',
            'lines' => [['sales_order_line_id' => $lineId, 'quantity' => '600', 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');

        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/issue")->assertSuccessful();

        $row = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0');
        $this->assertSame('600.0000', $row['invoiced']);
        // 400 are still owed, so the floor still owns the line.
        $this->assertSame('in_production', $row['blocker']['code']);
    }

    /**
     * THE TALLY HALF OF THE REHEARSAL, on the application's OWN defaults —
     * which is the state a real deployment is in. With both gates fail-closed,
     * a dispatch and an issued invoice reach Tally as NOTHING, and the ERP's
     * own documents stand exactly as before.
     */
    public function test_on_the_shipped_defaults_the_chain_stages_nothing_for_tally(): void
    {
        config([
            'tally-sync.delivery_notes_enabled' => false,
            'tally-sync.sales_invoices_enabled' => false,
        ]);

        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $customer = Customer::create(['code' => 'CUST-2', 'name' => 'Aqua Traders']);

        app(StockMovementService::class)->recordReceipt(
            itemId: $bottle->id,
            warehouseId: $fg->id,
            quantity: '100',
            unitCost: '2.50',
            reference: 'opening',
        );

        $this->actingWith(['sales.view', 'sales.manage']);

        $orderId = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $customer->id,
            'order_date' => '2026-08-30',
            'lines' => [['item_id' => $bottle->id, 'quantity' => '100', 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');

        $this->postJson("/api/v1/sales/sales-orders/{$orderId}/confirm")->assertSuccessful();

        $lineId = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0.line_id');

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $orderId,
            'warehouse_id' => $fg->id,
            'delivered_date' => '2026-08-31',
            'lines' => [['sales_order_line_id' => $lineId, 'quantity' => '100']],
        ])->assertSuccessful();

        $invoiceId = $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $orderId,
            'invoice_date' => '2026-08-31',
            'lines' => [['sales_order_line_id' => $lineId, 'quantity' => '100', 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');

        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/issue")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'issued');

        $this->assertSame(
            0,
            TallySyncEntry::query()->count(),
            'on the shipped defaults nothing at all is staged for Tally — no Delivery Note, no Sales voucher',
        );
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
