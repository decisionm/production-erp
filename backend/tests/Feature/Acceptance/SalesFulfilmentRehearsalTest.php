<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\Support\WritesInvoiceHistory;
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
 * It also pins what the shipped Control view says at each step: WHO must act
 * next, and that a fully held line is not called "ready" until Quality has
 * signed it off. There is no customer-approval gate and there must never be
 * one — DEC-20260831-006 settled that the factory performs no such step.
 *
 * The chain now CLOSES, in the second half of the file: Tally raises the sales
 * invoice, the ERP imports that voucher and matches it to the order
 * (DEC-20260831-012), and nothing travels the other way.
 */
class SalesFulfilmentRehearsalTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;
    use WritesInvoiceHistory;

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

        // QUALITY SIGNS THE HELD 600 OFF FIRST — dispatch is gated on internal
        // quality approval and capped at the approved quantity
        // (DEC-20260831-006). The gate itself is DispatchQualityGateTest's
        // subject; here it is the precondition the owner's sequence puts
        // between the store's hold and the store's dispatch.
        $this->approveQualityForDispatch($lineId, '600');

        // ---- 5b. THE STORE, not Sales, performs the dispatch ---------------
        // DEC-20260901-005 resolving Q78. The store holds the goods, so the
        // store lets them go; the desk that sold them may only read what left.
        // It dispatches on `inventory.manage` and is given no Sales permission.
        $this->actingWith(['inventory.manage']);

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

        // ---- 6. THE BILL ---------------------------------------------------
        // The ERP no longer raises one (DEC-20260903-004): Tally originates
        // the sales invoice and the ERP imports and matches it. The rehearsal
        // keeps the step because the FIGURE the fulfilment desk reads is
        // computed from invoice rows, and those rows still exist — so the row
        // is written as history and the desk is read exactly as before. A
        // store login is refused the Sales READ, which is what is left of
        // "the invoice is Sales' and stays Sales'" (DEC-20260901-005).
        $this->getJson('/api/v1/sales/invoices')->assertForbidden();

        $this->actingWith(['sales.view', 'sales.manage']);

        $this->issuedInvoiceHistory(SalesOrder::findOrFail($orderId), '600', null, '2026-08-31');

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
        // These two lines RESTORE THE SHIPPED DEFAULT; they do not override it.
        // Under DEC-20260831-012 the ERP sends no Sales Order, no Delivery Note
        // and no Sales Invoice to Tally, and config/tally-sync.php ships both
        // flags OFF. phpunit.xml pins them ON for the suite because staged
        // Sales and Delivery Note vouchers are the fixture vehicle for most of
        // TallySync's tests — so a deployment-shaped test has to say so out
        // loud. SalesTallyEmissionGateTest is where the shipped default itself
        // is asserted, by reading config/tally-sync.php with the env cleared.
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

        // Quality's dispatch sign-off, for the same reason as above
        // (DEC-20260831-006): this half's subject is what reaches Tally, not
        // the quality gate, so the sign-off is assumed rather than walked.
        $this->approveQualityForDispatch($lineId, '100');

        // The Store dispatches (DEC-20260901-005), then Sales invoices.
        $this->actingWith(['inventory.manage']);

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $orderId,
            'warehouse_id' => $fg->id,
            'delivered_date' => '2026-08-31',
            'lines' => [['sales_order_line_id' => $lineId, 'quantity' => '100']],
        ])->assertSuccessful();

        $this->actingWith(['sales.view', 'sales.manage']);

        // An issued invoice as history (DEC-20260903-004) — this test's point
        // is that on the SHIPPED defaults the whole chain stages nothing for
        // Tally, and an issued invoice is the one step that would, so it has
        // to be reached the way it can still be reached: the transition the
        // staging listener watches.
        $this->issuedInvoiceHistory(SalesOrder::findOrFail($orderId), '100', null, '2026-08-31');

        $this->assertSame(
            0,
            TallySyncEntry::query()->count(),
            'on the shipped defaults nothing at all is staged for Tally — no Delivery Note, no Sales voucher',
        );
    }

    /**
     * THE OTHER HALF OF THE SAME DECISION — and the half that is easy to
     * forget, because "we post nothing to Tally" sounds complete on its own.
     *
     * It is not. DEC-20260831-012 does not merely switch the outbound path
     * off; it names the surviving direction. Tally raises the Sales Invoice,
     * the e-invoice and the e-way details, and the ERP READS that voucher back
     * and matches it to the order it belongs to. A build that stopped at
     * "stages nothing" would leave the sales order never learning it had been
     * invoiced at all.
     *
     * So this walks the closing move on REAL TALLY BYTES — the same UTF-16LE
     * fixture cut from the factory's own 31-Aug export — and asserts the
     * order can read back the voucher that closed it.
     */
    public function test_tallys_own_invoice_comes_back_in_and_lands_on_the_order_that_earned_it(): void
    {
        $customer = Customer::create(['code' => 'CUST-3', 'name' => 'Revive Formulations']);
        $customer->forceFill(['tally_ledger_name' => 'Revive Formulations India Pvt Ltd'])->save();

        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);

        $this->actingWith(['sales.view', 'sales.manage']);

        // The customer quotes THEIR purchase order number — "480". That string,
        // and not any voucher number, is what the two books have in common:
        // Tally owns a contiguous NNN/26-27 series and the ERP mints INV-{id}.
        $orderId = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $customer->id,
            'order_date' => '2026-07-28',
            'customer_po_reference' => '480',
            'lines' => [['item_id' => $bottle->id, 'quantity' => '100', 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');

        $this->getJson("/api/v1/sales/sales-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.tally_invoice.invoiced_in_tally', false);

        // Accounts raise the invoice in Tally and export it. Dry run first,
        // exactly as a person would run it (AGENTS.md).
        $fixture = base_path('tests/fixtures/tally/sales-invoices.xml');

        $this->artisan('tally:import-sales-invoices', ['path' => $fixture])->assertSuccessful();

        $this->getJson("/api/v1/sales/sales-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.tally_invoice.invoiced_in_tally', false);

        $this->artisan('tally:import-sales-invoices', ['path' => $fixture, '--write' => true])->assertSuccessful();

        $this->getJson("/api/v1/sales/sales-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.tally_invoice.invoiced_in_tally', true)
            ->assertJsonPath('data.tally_invoice.vouchers.0.voucher_number', '699/26-27')
            ->assertJsonPath('data.tally_invoice.vouchers.0.voucher_date', '2026-08-01')
            // The order's OWN lifecycle is untouched. Being invoiced in another
            // book is not a delivery, and nothing here pretends it is.
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame(
            0,
            TallySyncEntry::query()->count(),
            'importing from Tally must never stage anything back towards it',
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
