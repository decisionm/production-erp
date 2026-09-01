<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * Phase 3.5 — one ERP-originated sales document can be OPENED and its whole
 * chain read (phase35-contract.md, "Show endpoints", "Resource additions",
 * "trace on show"):
 *
 *   - GET sales-orders/{id} · deliveries/{id} · invoices/{id} return the
 *     list resource PLUS `trace` (inside `data`, beside the other keys —
 *     the same place TallySyncEntryResource puts `history`);
 *   - every document carries `document_number` (SO-/DN-/INV-{id});
 *   - a sales order carries its customer, totals (ordered / delivered /
 *     invoiced, 4dp strings), deliveries_count, invoices_count and
 *     can_cancel — computed by the rule the cancel service enforces;
 *   - a delivery carries its order stub, customer stub, carton_count and a
 *     TallyLink; an invoice its order stub and a TallyLink — null until a
 *     sync entry exists (a draft invoice has none);
 *   - the TallyLink is status + flags + link ONLY (entry_id, voucher_type,
 *     status, voucher_number, synced_at, flags, link) — no payload, no
 *     rate, no party GSTIN, no error text — and its flags carry the
 *     `unvalidated_builder` warning for Delivery Note and Sales alike;
 *   - the SO trace lists deliveries (with the cartons that physically left,
 *     traced to their batch) and invoices, each with its own TallyLink;
 *   - a missing id is a 404; a reader without sales.view gets 403.
 *
 * The chain is built through the REAL endpoints — carton labels, scan
 * dispatch, typed dispatch, invoice issue — so the Tally entries are the
 * ones the domain events enqueue, not hand-made rows.
 */
class SalesDocumentShowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private const TALLY_LINK_KEYS = ['entry_id', 'voucher_type', 'status', 'voucher_number', 'synced_at', 'flags', 'link'];

    private Item $bottle;

    private Warehouse $fg;

    private Customer $customer;

    private User $desk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->desk = User::factory()->create(['name' => 'Sales Desk', 'is_active' => true]);
        // `inventory.manage` because this actor DISPATCHES: the Store performs the
        // final dispatch action, not Sales (DEC-20260901-005, resolving Q78).
        foreach (['production.view', 'production.manage', 'sales.view', 'sales.manage', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->desk->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->desk);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);

        // The Sales voucher is this file's FIXTURE, not its subject: it issues an
        // invoice merely to get a 'Sales' row into the queue to link to.
        // SalesVoucherPayload now refuses (and stages nothing) without the GST
        // registration, ledger mappings, HSN/rate and customer ledger name, so
        // seed them LAST — after the item, the customer and the single
        // Tally-linked warehouse above, which the trait then leaves alone (a
        // second godown would be ambiguous, and nothing here is overwritten).
        $this->seedSalesTallyMasterData();
    }

    // ---- sales order ------------------------------------------------------

    public function test_a_sales_order_show_carries_the_new_fields_and_the_whole_trace(): void
    {
        $entry = $this->labelledBatch();
        $order = $this->confirmedOrder('2000', '5000');

        // Two deliveries — one by scan (two cartons, 1200 pieces), one typed
        // (300) — and two invoices: one issued (500), one still draft (200).
        $scanned = $this->dispatchByScan($order, ['20260802-M01-001-C01', '20260802-M01-001-C02'])->json('data');
        $typed = $this->dispatchTyped($order, '300')->json('data');
        $issued = $this->invoice($order, '500', '2026-08-11')->json('data');
        $this->postJson("/api/v1/sales/invoices/{$issued['id']}/issue")->assertSuccessful();
        $draft = $this->invoice($order, '200', '2026-08-12')->json('data');

        $show = $this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertOk();
        $data = $show->json('data');

        $this->assertSame("SO-{$order->id}", $data['document_number']);
        $this->assertSame($this->customer->id, $data['customer']['id']);
        $this->assertSame('Aqua Traders', $data['customer']['name']);
        $this->assertSame(['ordered_quantity' => '2000.0000', 'delivered_quantity' => '1500.0000', 'invoiced_quantity' => '700.0000'], $data['totals']);
        $this->assertSame(2, $data['deliveries_count']);
        $this->assertSame(2, $data['invoices_count']);
        $this->assertFalse($data['can_cancel']);
        $this->assertSame('partially_delivered', $data['status']);
        $this->assertCount(1, $data['lines']);

        // --- trace.deliveries: both, oldest first, each with lines, cartons and its Delivery Note link
        $deliveries = $data['trace']['deliveries'];
        $this->assertSame([$scanned['id'], $typed['id']], array_column($deliveries, 'id'));

        $first = $deliveries[0];
        $this->assertSame("DN-{$scanned['id']}", $first['document_number']);
        $this->assertSame(['id' => $this->fg->id, 'name' => 'FG Store'], $first['warehouse']);
        $this->assertSame('2026-08-10T00:00:00+00:00', $first['delivered_date']);
        $this->assertSame([['item' => ['id' => $this->bottle->id, 'name' => '500ml PET Bottle'], 'quantity' => '1200.0000']], $first['lines']);
        $this->assertSame(
            [
                ['carton_no' => '20260802-M01-001-C01', 'pieces' => '600.0000', 'shift_production_entry_id' => $entry->id, 'batch_no' => '20260802-M01-001'],
                ['carton_no' => '20260802-M01-001-C02', 'pieces' => '600.0000', 'shift_production_entry_id' => $entry->id, 'batch_no' => '20260802-M01-001'],
            ],
            $first['cartons'],
        );
        $this->assertTallyLink($first['tally'], 'Delivery Note', "DN-{$scanned['id']}", 'pending');

        $second = $deliveries[1];
        $this->assertSame([], $second['cartons'], 'A typed delivery has no scanned cartons');
        $this->assertSame([['item' => ['id' => $this->bottle->id, 'name' => '500ml PET Bottle'], 'quantity' => '300.0000']], $second['lines']);
        $this->assertTallyLink($second['tally'], 'Delivery Note', "DN-{$typed['id']}", 'pending');

        // --- trace.invoices: the issued one carries a Sales link with the
        // DEC-20260809-003 warning; the draft has no entry and says null.
        $invoices = $data['trace']['invoices'];
        $this->assertSame([$issued['id'], $draft['id']], array_column($invoices, 'id'));
        $this->assertSame("INV-{$issued['id']}", $invoices[0]['document_number']);
        $this->assertSame('issued', $invoices[0]['status']);
        $this->assertSame('2026-08-11', $invoices[0]['invoice_date']);
        $this->assertSame([['item' => ['id' => $this->bottle->id, 'name' => '500ml PET Bottle'], 'quantity' => '500.0000', 'unit_price' => '4.5000']], $invoices[0]['lines']);
        $this->assertTallyLink($invoices[0]['tally'], 'Sales', "INV-{$issued['id']}", 'pending');
        $this->assertSame('DEC-20260831-007', $invoices[0]['tally']['flags']['unvalidated_builder']['decision']);
        $this->assertSame('draft', $invoices[1]['status']);
        $this->assertNull($invoices[1]['tally']);

        // Nothing of the voucher payload rides a link: no party GSTIN, no
        // rate, no godown, no error text — status + flags + link only.
        $links = json_encode(array_merge(array_column($deliveries, 'tally'), array_column($invoices, 'tally')));
        foreach (['payload', '33AAACA1111A1Z5', 'party_ledger', 'godown', 'error_message', '"rate"', 'total_amount'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $links);
        }

        // The LIST carries the same header fields (and no trace).
        $row = collect($this->getJson('/api/v1/sales/sales-orders')->assertOk()->json('data'))->firstWhere('id', $order->id);
        $this->assertSame("SO-{$order->id}", $row['document_number']);
        $this->assertSame('1500.0000', $row['totals']['delivered_quantity']);
        $this->assertSame(2, $row['deliveries_count']);
        $this->assertFalse($row['can_cancel']);
        $this->assertArrayNotHasKey('trace', $row);
    }

    public function test_a_fresh_order_shows_zero_totals_an_empty_trace_and_can_cancel_true(): void
    {
        $order = $this->confirmedOrder('2000', '0');
        $draft = SalesOrder::create(['customer_id' => $this->customer->id, 'status' => SalesOrderStatus::Draft, 'order_date' => '2026-08-02']);

        foreach ([$order, $draft] as $document) {
            $data = $this->getJson("/api/v1/sales/sales-orders/{$document->id}")->assertOk()->json('data');

            $this->assertSame(['ordered_quantity' => $document->is($order) ? '2000.0000' : '0.0000', 'delivered_quantity' => '0.0000', 'invoiced_quantity' => '0.0000'], $data['totals']);
            $this->assertSame(0, $data['deliveries_count']);
            $this->assertSame(0, $data['invoices_count']);
            $this->assertTrue($data['can_cancel']);
            $this->assertSame(['deliveries' => [], 'invoices' => []], $data['trace']);
        }

        // Every create/confirm response keeps the shape too — the same
        // resource answers them.
        $created = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $this->customer->id,
            'order_date' => '2026-08-03',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '10', 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data');
        $this->assertSame("SO-{$created['id']}", $created['document_number']);
        $this->assertSame('10.0000', $created['totals']['ordered_quantity']);
        $this->assertTrue($created['can_cancel']);
        $this->assertArrayNotHasKey('trace', $created);
    }

    // ---- delivery ---------------------------------------------------------

    public function test_a_delivery_show_and_list_carry_order_customer_carton_count_and_tally_link(): void
    {
        $this->labelledBatch();
        $order = $this->confirmedOrder('2000', '5000');
        $delivery = $this->dispatchByScan($order, ['20260802-M01-001-C01', '20260802-M01-001-C02'])->json('data');

        // The dispatch response itself already carries the link (the
        // Delivery Note is queued inside the same transaction).
        $this->assertSame("DN-{$delivery['id']}", $delivery['document_number']);
        $this->assertSame(2, $delivery['carton_count']);
        $this->assertTallyLink($delivery['tally'], 'Delivery Note', "DN-{$delivery['id']}", 'pending');

        $data = $this->getJson("/api/v1/sales/deliveries/{$delivery['id']}")->assertOk()->json('data');
        $this->assertSame("DN-{$delivery['id']}", $data['document_number']);
        $this->assertSame(['id' => $order->id, 'document_number' => "SO-{$order->id}", 'status' => 'partially_delivered'], $data['sales_order']);
        $this->assertSame(['id' => $this->customer->id, 'code' => 'CUST-1', 'name' => 'Aqua Traders'], $data['customer']);
        $this->assertSame(2, $data['carton_count']);
        $this->assertTallyLink($data['tally'], 'Delivery Note', "DN-{$delivery['id']}", 'pending');
        $this->assertArrayHasKey('unvalidated_builder', $data['tally']['flags']);

        $trace = $data['trace'];
        $this->assertSame(['id', 'document_number', 'status', 'customer'], array_keys($trace['sales_order']));
        $this->assertSame(['id' => $this->customer->id, 'code' => 'CUST-1', 'name' => 'Aqua Traders'], $trace['sales_order']['customer']);
        $this->assertSame(['20260802-M01-001-C01', '20260802-M01-001-C02'], array_column($trace['cartons'], 'carton_no'));
        $this->assertSame('20260802-M01-001', $trace['cartons'][0]['batch_no']);
        $this->assertSame($data['tally'], $trace['tally']);

        // The agent acks it → the link reads synced, with the timestamp.
        $entry = TallySyncEntry::query()->sole();
        app(TallySyncService::class)->markSynced($entry);

        $after = $this->getJson("/api/v1/sales/deliveries/{$delivery['id']}")->assertOk()->json('data.tally');
        $this->assertSame('synced', $after['status']);
        $this->assertNotNull($after['synced_at']);
        $this->assertSame($entry->id, $after['entry_id']);

        $row = collect($this->getJson('/api/v1/sales/deliveries')->assertOk()->json('data'))->firstWhere('id', $delivery['id']);
        $this->assertSame(2, $row['carton_count']);
        $this->assertSame('synced', $row['tally']['status']);
        $this->assertSame(['id' => $order->id, 'document_number' => "SO-{$order->id}", 'status' => 'partially_delivered'], $row['sales_order']);
        $this->assertSame('Aqua Traders', $row['customer']['name']);
        $this->assertArrayNotHasKey('trace', $row);
    }

    public function test_a_typed_delivery_has_no_cartons_and_a_delivery_without_an_entry_links_null(): void
    {
        $order = $this->confirmedOrder('2000', '5000');
        $delivery = $this->dispatchTyped($order, '300')->json('data');

        $data = $this->getJson("/api/v1/sales/deliveries/{$delivery['id']}")->assertOk()->json('data');
        $this->assertSame(0, $data['carton_count']);
        $this->assertSame([], $data['trace']['cartons']);
        $this->assertTallyLink($data['tally'], 'Delivery Note', "DN-{$delivery['id']}", 'pending');

        // A delivery whose entry was never written (pre-Phase-2 backfill
        // gap) has nothing to link to — null, never a fabricated status.
        TallySyncEntry::query()->delete();
        $this->assertNull($this->getJson("/api/v1/sales/deliveries/{$delivery['id']}")->assertOk()->json('data.tally'));
        $this->assertNull($this->getJson("/api/v1/sales/deliveries/{$delivery['id']}")->assertOk()->json('data.trace.tally'));
    }

    // ---- invoice ----------------------------------------------------------

    public function test_an_invoice_links_null_while_draft_and_to_its_sales_entry_once_issued(): void
    {
        $order = $this->confirmedOrder('2000', '0');
        $invoice = $this->invoice($order, '500', '2026-08-11')->json('data');

        $this->assertSame("INV-{$invoice['id']}", $invoice['document_number']);
        $this->assertNull($invoice['tally']);

        $draft = $this->getJson("/api/v1/sales/invoices/{$invoice['id']}")->assertOk()->json('data');
        $this->assertSame(['id' => $order->id, 'document_number' => "SO-{$order->id}", 'status' => 'confirmed'], $draft['sales_order']);
        $this->assertNull($draft['tally']);
        $this->assertSame(['id', 'document_number', 'status', 'customer'], array_keys($draft['trace']['sales_order']));
        $this->assertNull($draft['trace']['tally']);
        $this->assertSame('Aqua Traders', $draft['customer']['name']);

        $issued = $this->postJson("/api/v1/sales/invoices/{$invoice['id']}/issue")->assertSuccessful()->json('data');
        $this->assertTallyLink($issued['tally'], 'Sales', "INV-{$invoice['id']}", 'pending');

        $shown = $this->getJson("/api/v1/sales/invoices/{$invoice['id']}")->assertOk()->json('data');
        $this->assertTallyLink($shown['tally'], 'Sales', "INV-{$invoice['id']}", 'pending');
        $this->assertSame('DEC-20260831-007', $shown['tally']['flags']['unvalidated_builder']['decision']);
        $this->assertSame($shown['tally'], $shown['trace']['tally']);

        $row = collect($this->getJson('/api/v1/sales/invoices')->assertOk()->json('data'))->firstWhere('id', $invoice['id']);
        $this->assertSame("INV-{$invoice['id']}", $row['document_number']);
        $this->assertSame('pending', $row['tally']['status']);
        $this->assertSame("SO-{$order->id}", $row['sales_order']['document_number']);
        $this->assertArrayNotHasKey('trace', $row);
    }

    // ---- can_cancel is the cancel rule ------------------------------------

    public function test_can_cancel_reports_exactly_what_the_cancel_endpoint_allows(): void
    {
        // A draft and a confirmed order with nothing against them: cancellable.
        $draft = SalesOrder::create(['customer_id' => $this->customer->id, 'status' => SalesOrderStatus::Draft, 'order_date' => '2026-08-02']);
        $draft->lines()->create(['item_id' => $this->bottle->id, 'quantity' => '10', 'unit_price' => '4.50', 'quantity_delivered' => 0]);
        $confirmed = $this->confirmedOrder('2000', '5000');

        $this->assertTrue($this->getJson("/api/v1/sales/sales-orders/{$draft->id}")->json('data.can_cancel'));
        $cancelled = $this->postJson("/api/v1/sales/sales-orders/{$draft->id}/cancel")->assertOk()->json('data');
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertFalse($cancelled['can_cancel']);
        $this->assertSame(0, TallySyncEntry::query()->count(), 'Cancelling queues nothing for Tally');

        // Once cancelled: confirm, delivery and invoice creation all refuse (422).
        $this->postJson("/api/v1/sales/sales-orders/{$draft->id}/confirm")->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition sales order from "cancelled" to "confirmed".');
        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $draft->id, 'warehouse_id' => $this->fg->id,
            'lines' => [['sales_order_line_id' => $draft->lines()->first()->id, 'quantity' => '1']],
        ])->assertStatus(422)->assertJsonPath('message', 'Cannot transition sales order from "cancelled" to "delivered".');
        $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $draft->id, 'invoice_date' => '2026-08-11',
            'lines' => [['sales_order_line_id' => $draft->lines()->first()->id, 'quantity' => '1', 'unit_price' => '4.50']],
        ])->assertStatus(422)->assertJsonPath('message', 'Cannot transition sales order from "cancelled" to "invoiced".');
        $this->postJson("/api/v1/sales/sales-orders/{$draft->id}/cancel")->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition sales order from "cancelled" to "cancelled".');

        // A confirmed order with a DRAFT invoice against it: not cancellable
        // (an invoice is an invoice), and the endpoint says the same.
        $this->assertTrue($this->getJson("/api/v1/sales/sales-orders/{$confirmed->id}")->json('data.can_cancel'));
        $this->invoice($confirmed, '5', '2026-08-11');
        $this->assertFalse($this->getJson("/api/v1/sales/sales-orders/{$confirmed->id}")->json('data.can_cancel'));
        $this->postJson("/api/v1/sales/sales-orders/{$confirmed->id}/cancel")->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition sales order from "confirmed" to "cancelled".');

        // A partially delivered order: not cancellable either.
        $delivered = $this->confirmedOrder('2000', '0');
        $this->dispatchTyped($delivered, '300');
        $this->assertFalse($this->getJson("/api/v1/sales/sales-orders/{$delivered->id}")->json('data.can_cancel'));
        $this->postJson("/api/v1/sales/sales-orders/{$delivered->id}/cancel")->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition sales order from "partially_delivered" to "cancelled".');
        $this->assertSame(SalesOrderStatus::PartiallyDelivered, $delivered->fresh()->status);

        // Cancel is a write: sales.view alone is 403.
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('sales.view');
        Sanctum::actingAs($viewer);
        $another = SalesOrder::create(['customer_id' => $this->customer->id, 'status' => SalesOrderStatus::Draft, 'order_date' => '2026-08-02']);
        $this->postJson("/api/v1/sales/sales-orders/{$another->id}/cancel")->assertForbidden();
        $this->assertSame(SalesOrderStatus::Draft, $another->fresh()->status);
    }

    // ---- the honesty endpoint ---------------------------------------------

    public function test_the_tally_mirror_statement_is_served_verbatim_behind_sales_view(): void
    {
        $statement = $this->getJson('/api/v1/sales/tally-mirror')->assertOk()->json();

        $this->assertFalse($statement['mirrored']);
        $this->assertSame('DEC-20260831-007', $statement['decision']);
        $this->assertSame('Sales raised here post to Tally; Tally is not read back', $statement['headline']);
        $this->assertSame(
            'Tally-side Sales and Sales Order vouchers are not mirrored into this ERP. The documents on these pages are the ERP-originated subset only, and a sale keyed straight into Tally will not appear here. Reads from Tally are deliberate and human-triggered; none is scheduled.',
            $statement['body'],
        );
        $this->assertSame([
            // VALIDATED, NOT LIVE-POSTED. The old statement claimed the builder
            // was unvalidated and carried no GST; both became false when it was
            // rebuilt against the factory's own 55 real vouchers, and a false
            // honesty statement is worse than none.
            'validated' => true,
            'note' => 'The ERP\'s Sales voucher was checked field by field against 55 real Sales vouchers exported from this factory\'s Tally and emits CGST/SGST or IGST, Rounding Off and a per-line ledger. It has not yet been posted to a live Tally, and it refuses to stage at all when the customer ledger, HSN, rate, godown or allowed company is missing — a refusal never blocks the invoice.',
        ], $statement['erp_invoice_builder']);
        $this->assertFalse($statement['payments_recorded_here']);
        $this->assertSame('An invoice is never marked paid by this ERP — receipts live in Tally.', $statement['payments_note']);
        $this->assertSame(
            ['mirrored', 'decision', 'headline', 'body', 'erp_invoice_builder', 'payments_recorded_here', 'payments_note'],
            array_keys($statement),
        );

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->givePermissionTo('production.view');
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/sales/tally-mirror')->assertForbidden();
    }

    // ---- 404 / 403 --------------------------------------------------------

    public function test_a_missing_document_is_404_and_a_reader_without_sales_view_is_403(): void
    {
        $order = $this->confirmedOrder('2000', '5000');
        $delivery = $this->dispatchTyped($order, '300')->json('data');
        $invoice = $this->invoice($order, '500', '2026-08-11')->json('data');

        $this->getJson('/api/v1/sales/sales-orders/999999')->assertNotFound();
        $this->getJson('/api/v1/sales/deliveries/999999')->assertNotFound();
        $this->getJson('/api/v1/sales/invoices/999999')->assertNotFound();

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('sales.view');
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertOk();
        $this->getJson("/api/v1/sales/deliveries/{$delivery['id']}")->assertOk();
        $this->getJson("/api/v1/sales/invoices/{$invoice['id']}")->assertOk();

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->givePermissionTo('production.view');
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertForbidden();
        $this->getJson("/api/v1/sales/deliveries/{$delivery['id']}")->assertForbidden();
        $this->getJson("/api/v1/sales/invoices/{$invoice['id']}")->assertForbidden();
    }

    // ---- helpers ----------------------------------------------------------

    /** @param  array<string, mixed>|null  $link */
    private function assertTallyLink(?array $link, string $voucherType, string $voucherNumber, string $status): void
    {
        $this->assertNotNull($link, 'A TallyLink was expected');
        $this->assertSame(self::TALLY_LINK_KEYS, array_keys($link));
        $this->assertSame($voucherType, $link['voucher_type']);
        $this->assertSame($voucherNumber, $link['voucher_number']);
        $this->assertSame($status, $link['status']);
        $this->assertSame("/tally-sync?entry={$link['entry_id']}", $link['link']);
        $this->assertIsArray($link['flags']);
        $this->assertArrayHasKey('unvalidated_builder', $link['flags']);
        $this->assertSame(
            TallySyncEntry::query()->whereKey($link['entry_id'])->value('tally_voucher_type'),
            $voucherType,
        );
    }

    /** A completed, approved batch with its cartons labelled (3 × 600 + 360). */
    private function labelledBatch(string $batchNumber = '20260802-M01-001'): ShiftProductionEntry
    {
        $shift = Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-02',
            'batch_number' => $batchNumber,
            'status' => ShiftProductionEntryStatus::Pending,
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '2160',
            'nos_per_box' => '600',
        ]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")->assertSuccessful();
        $entry->update(['status' => ShiftProductionEntryStatus::Approved]);

        return $entry;
    }

    private function confirmedOrder(string $ordered, string $stock): SalesOrder
    {
        if (bccomp($stock, '0', 4) === 1) {
            app(StockMovementService::class)->recordReceipt(
                itemId: $this->bottle->id, warehouseId: $this->fg->id,
                quantity: $stock, unitCost: '2.50', reference: 'seed',
            );
        }

        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-02',
        ]);
        $order->lines()->create([
            'item_id' => $this->bottle->id, 'quantity' => $ordered,
            'unit_price' => '4.50', 'quantity_delivered' => 0,
        ]);

        // Dispatch is gated on internal quality approval (DEC-20260831-006).
        // This file's subject is the SHOW endpoints, not the gate, so the line
        // is signed off for its whole ordered quantity here — once, before any
        // delivery is posted against it. Only orders built HERE are signed off:
        // the hand-built order test_can_cancel cancels is left unapproved on
        // purpose, so its refused dispatch keeps proving the status guard.
        $this->approveQualityForOrder($order->id);

        return $order;
    }

    /** @param  list<string>  $cartons */
    private function dispatchByScan(SalesOrder $order, array $cartons): TestResponse
    {
        return $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-10',
            'carton_codes' => $cartons,
        ])->assertSuccessful();
    }

    private function dispatchTyped(SalesOrder $order, string $quantity): TestResponse
    {
        return $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-10',
            'lines' => [['sales_order_line_id' => $order->lines()->first()->id, 'quantity' => $quantity]],
        ])->assertSuccessful();
    }

    private function invoice(SalesOrder $order, string $quantity, string $date): TestResponse
    {
        return $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $order->id,
            'invoice_date' => $date,
            'lines' => [['sales_order_line_id' => $order->lines()->first()->id, 'quantity' => $quantity, 'unit_price' => '4.50']],
        ])->assertSuccessful();
    }
}
