<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Finance\Models\Enums\GLAccountType;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\TallyLedgerMappingService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * THE GENERIC ENQUEUE IS IDEMPOTENT PER (SYNCABLE, VOUCHER TYPE) — Phase 3.5
 * closes the "a Delivery has no replay key" gap recorded in Phase 3.
 *
 * Every non-production type (Delivery Note, Receipt Note, Sales, Journal)
 * reaches the queue through TallySyncService::enqueue(). Until now that
 * path guarded nothing: what stopped a double booking was the DOMAIN — the
 * order found completed, the receipt key replayed, the issue/post
 * transition refused (PerType/*LifecycleTest). Those guards stand; but a
 * re-FIRED domain event (a listener replayed, a stale process, a
 * backfill run twice) reached enqueue() unguarded and minted a second
 * voucher for the same source — two rows the agent would post twice.
 *
 * The rule now: if a NON-DISMISSED entry already exists for the same
 * syncable and voucher type, enqueue() returns it and records NOTHING —
 * no new row, no new voucher.enqueued event, whatever the existing row's
 * status (pending, failed, synced). Dismissed is the one exception: a
 * dismissed voucher is dead, and a fresh enqueue is the only road by which
 * that source can ever be re-issued today — kept exactly as it was.
 *
 * Nothing here changes what reaches Tally: the payloads, voucher numbers
 * and the pending() hand-out are untouched. Only the COUNT of rows is.
 */
class GenericEnqueueReplayTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fg;

    private Warehouse $rm;

    private SalesOrder $order;

    private SalesOrderLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'itm-resin']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        // The RM store is a LOCAL store, not a second Tally godown: this factory
        // has exactly ONE godown, and two Tally-linked warehouses would make the
        // Sales voucher's godown genuinely ambiguous — SalesVoucherPayload would
        // refuse with godown_unresolved and stage nothing to replay. The receipt
        // note below is unaffected: an unlinked warehouse posts under the one
        // linked godown (TallyGodownResolver rule 3), as the factory's bins do.
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id, warehouseId: $this->fg->id, quantity: '5000', unitCost: '2.50', reference: 'seed',
        );
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Sales->value => 'Sales - Local']);

        $customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
        $this->order = SalesOrder::create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Confirmed, 'order_date' => '2026-08-09']);
        $this->line = $this->order->lines()->create(['item_id' => $this->bottle->id, 'quantity' => '2000', 'unit_price' => '4.50', 'quantity_delivered' => 0]);

        // Dispatch is gated on Quality's internal sign-off (DEC-20260831-006),
        // and this file's subject is the ENQUEUE guard, not the gate: the
        // dispatches below are the ordinary approved ones they always were, so
        // the whole ordered 2000 is signed off once, here. The whole quantity
        // on purpose — the two-source test spends it as 800 + 1200, and the
        // cap is the approved quantity less what has already gone out.
        $this->line = $this->approveQualityForDispatch($this->line);

        // The Sales voucher is this file's FIXTURE VEHICLE, not its subject, and
        // SalesVoucherPayload now stages NOTHING without the GST masters behind it
        // (registration, ledger roles, an HSN and rate per item, the customer's
        // Tally name and state) — the invoice replay below would have no row to
        // watch. Last in setUp, so the items and the customer above are there to
        // complete; the FG store above is the single Tally-linked godown, so the
        // trait adds none.
        $this->seedSalesTallyMasterData();
    }

    // ---- actors -----------------------------------------------------------

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions, string $name): static
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $this;
    }

    private function salesDesk(): static
    {
        return $this->actingWith(['sales.view', 'sales.manage', 'inventory.manage'], 'Sales Desk'); // dispatch is the STORE's act (DEC-20260901-005)
    }

    // ---- sources, each through its own module's endpoint ------------------

    private function dispatch(string $quantity, string $reference): Delivery
    {
        $id = $this->salesDesk()->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $this->order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-10',
            'reference' => $reference,
            'lines' => [['sales_order_line_id' => $this->line->id, 'quantity' => $quantity]],
        ])->assertSuccessful()->json('data.id');

        return Delivery::query()->findOrFail($id);
    }

    private function receive(): GoodsReceiptNote
    {
        $vendor = Vendor::create(['code' => 'SUP-1', 'name' => 'Reliance Industries', 'gstin' => '27AAACR1234A1Z5', 'tally_ledger_name' => 'Reliance Industries']);
        $po = PurchaseOrder::create(['vendor_id' => $vendor->id, 'status' => PurchaseOrderStatus::Sent, 'order_date' => '2026-08-01']);
        $poLine = $po->lines()->create(['item_id' => $this->resin->id, 'quantity' => '12000', 'unit_price' => '85', 'quantity_received' => '0']);

        $id = $this->actingWith(['procurement.view', 'procurement.manage'], 'Store Keeper')
            ->postJson('/api/v1/procurement/goods-receipts', [
                'receipt_key' => 'receipt-20260810-001',
                'purchase_order_id' => $po->id,
                'warehouse_id' => $this->rm->id,
                'received_date' => '2026-08-10',
                'receipt_note_reference' => 'RN-TEST-1',
                'tracking_number' => 'LR-4471',
                'lines' => [['purchase_order_line_id' => $poLine->id, 'quantity' => '12000', 'unit_cost' => '85']],
            ])->assertSuccessful()->json('data.id');

        return GoodsReceiptNote::query()->findOrFail($id);
    }

    /**
     * An issued invoice, written and transitioned through the models: the
     * ERP raises no invoice any more (DEC-20260903-004), and the enqueue this
     * file replays hangs off Invoice::updated, never off the withdrawn route.
     */
    private function issuedInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'sales_order_id' => $this->order->id,
            'customer_id' => $this->order->customer_id,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => '2026-08-10',
            'notes' => 'August supply',
        ]);
        $invoice->lines()->create([
            'sales_order_line_id' => $this->line->id,
            'item_id' => $this->line->item_id,
            'quantity' => '2000',
            'unit_price' => '4.50',
        ]);
        $invoice->update(['status' => InvoiceStatus::Issued]);

        return $invoice->refresh();
    }

    private function postedJournal(): JournalEntry
    {
        $bank = GLAccount::create(['code' => '1100', 'name' => 'Bank', 'type' => GLAccountType::Asset, 'is_active' => true]);
        $sales = GLAccount::create(['code' => '4000', 'name' => 'Sales', 'type' => GLAccountType::Revenue, 'is_active' => true]);

        $id = $this->actingWith(['finance.view', 'finance.manage'], 'Finance Desk')
            ->postJson('/api/v1/finance/journal-entries', [
                'entry_date' => '2026-08-10',
                'reference' => 'JE-REF-9',
                'memo' => 'Cash sale banked',
                'lines' => [
                    ['gl_account_id' => $bank->id, 'debit' => '100', 'credit' => '0', 'memo' => 'to bank'],
                    ['gl_account_id' => $sales->id, 'debit' => '0', 'credit' => '100', 'memo' => 'from sales'],
                ],
            ])->assertSuccessful()->json('data.id');
        $this->postJson("/api/v1/finance/journal-entries/{$id}/post")->assertSuccessful()->assertJsonPath('data.status', 'posted');

        return JournalEntry::query()->findOrFail($id);
    }

    // ---- readers ------------------------------------------------------------

    private function enqueuedEvents(): int
    {
        return TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherEnqueued->value)->count();
    }

    /** ONE row for the source, ONE voucher.enqueued in the whole history, no other event. */
    private function assertExactlyOneVoucher(TallySyncEntry $entry, string $voucherType, string $status = 'pending'): void
    {
        $this->assertSame(1, TallySyncEntry::query()->count(), 'The same source must never mint a second voucher');
        $rows = TallySyncEntry::query()
            ->where('syncable_type', $entry->syncable_type)
            ->where('syncable_id', $entry->syncable_id)
            ->where('tally_voucher_type', $voucherType)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame($entry->id, $rows->first()->id, 'The surviving row is the original, not a replacement');
        $this->assertSame($status, $rows->first()->status->value);
        $this->assertSame(1, $this->enqueuedEvents(), 'A replay records no second voucher.enqueued');
    }

    // ---- the four generic types, re-fired -------------------------------------

    public function test_re_firing_delivery_dispatched_leaves_one_delivery_note_and_one_enqueued_event(): void
    {
        $delivery = $this->dispatch('2000', 'Truck A');
        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Delivery Note', $entry->tally_voucher_type);
        $this->assertSame(1, $this->enqueuedEvents());

        // The exact replay: the same domain event again, the listener runs
        // again, enqueueDelivery() runs again — nothing else in the domain
        // stands in the way (a Delivery has no replay key of its own).
        event(new DeliveryDispatched($delivery->fresh()));

        $this->assertExactlyOneVoucher($entry, 'Delivery Note');
        $this->assertSame(1, TallySyncEvent::query()->count(), 'Nothing at all is recorded for a no-op replay');
        // Same voucher number, same payload — the row is untouched.
        $this->assertSame("DN-{$delivery->id}", $entry->fresh()->payload['voucher_number']);
        $this->assertSame($entry->payload, $entry->fresh()->payload);
    }

    public function test_re_firing_goods_receipt_note_received_leaves_one_receipt_note(): void
    {
        $note = $this->receive();
        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Receipt Note', $entry->tally_voucher_type);

        event(new GoodsReceiptNoteReceived($note->fresh()));

        $this->assertExactlyOneVoucher($entry, 'Receipt Note');
        $this->assertSame(1, TallySyncEvent::query()->count());
    }

    public function test_re_saving_an_issued_invoice_with_a_note_change_leaves_one_sales_voucher(): void
    {
        $invoice = $this->issuedInvoice();
        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Sales', $entry->tally_voucher_type);

        // An issued invoice saved again with a note change — the model event
        // fires again, status still 'issued'. One voucher.
        $invoice->fresh()->update(['notes' => 'August supply — corrected']);
        $this->assertSame('issued', $invoice->fresh()->status->value);

        $this->assertExactlyOneVoucher($entry, 'Sales');

        // And the same source handed straight to the enqueue a second time
        // — the way a replayed listener or a backfill would — comes back
        // as the SAME entry, with nothing recorded.
        $again = app(TallySyncService::class)->enqueueSalesInvoice($invoice->fresh());
        $this->assertSame($entry->id, $again->id, 'A second enqueue of the same invoice returns the existing entry');
        $this->assertExactlyOneVoucher($entry, 'Sales');
        $this->assertSame(1, TallySyncEvent::query()->count());
    }

    public function test_re_saving_a_posted_journal_leaves_one_journal_voucher(): void
    {
        $journal = $this->postedJournal();
        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Journal', $entry->tally_voucher_type);

        $journal->fresh()->update(['memo' => 'Cash sale banked — corrected']);
        $this->assertSame('posted', $journal->fresh()->status->value);

        $this->assertExactlyOneVoucher($entry, 'Journal');

        $again = app(TallySyncService::class)->enqueueJournalEntry($journal->fresh());
        $this->assertSame($entry->id, $again->id, 'A second enqueue of the same journal returns the existing entry');
        $this->assertExactlyOneVoucher($entry, 'Journal');
        $this->assertSame(1, TallySyncEvent::query()->count());
    }

    // ---- whatever the live row's status ----------------------------------------

    public function test_a_replay_is_refused_while_the_existing_voucher_is_failed_and_after_it_is_synced(): void
    {
        $delivery = $this->dispatch('2000', 'Truck A');
        $entry = TallySyncEntry::query()->sole();
        $sync = app(TallySyncService::class);

        // Failed (Tally rejected it, a person may retry): the source is
        // still represented — a replay must not stand a second, pending
        // twin beside the failed one.
        $sync->markFailed($entry, "Godown 'FG Store' does not exist!");
        event(new DeliveryDispatched($delivery->fresh()));
        $this->assertExactlyOneVoucher($entry, 'Delivery Note', 'failed');
        $this->assertSame(['voucher.enqueued', 'voucher.failed'], TallySyncEvent::query()->orderBy('id')->pluck('event')->all());

        // Synced (in the books): the one replay that would post it TWICE
        // into live Tally. Refused, row untouched, history untouched.
        $sync->markSynced($entry);
        event(new DeliveryDispatched($delivery->fresh()));
        $this->assertExactlyOneVoucher($entry, 'Delivery Note', 'synced');
        $this->assertNotNull($entry->fresh()->synced_at);
        $this->assertSame(['voucher.enqueued', 'voucher.failed', 'voucher.synced'], TallySyncEvent::query()->orderBy('id')->pluck('event')->all());
    }

    // ---- dismissed: the one door left open ---------------------------------------

    public function test_after_dismissal_a_re_fire_creates_a_fresh_row(): void
    {
        $delivery = $this->dispatch('2000', 'Truck A');
        $dead = TallySyncEntry::query()->sole();
        $sync = app(TallySyncService::class);

        // Written off: failed first (only a failed voucher can be dismissed),
        // then dismissed — dead to the agent, never retried.
        $sync->markFailed($dead, "Godown 'FG Store' does not exist!");
        $sync->dismiss($dead);
        $this->assertSame(TallySyncStatus::Dismissed, $dead->fresh()->status);

        // The same source enqueued again is ALLOWED: a fresh, pending row
        // beside the dismissed one — the only road today by which a
        // dismissed voucher's source can be re-issued (unchanged on purpose).
        event(new DeliveryDispatched($delivery->fresh()));

        $rows = TallySyncEntry::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($dead->id, $rows[0]->id);
        $this->assertSame(TallySyncStatus::Dismissed, $rows[0]->status, 'The dismissed row is history, not resurrected');
        $fresh = $rows[1];
        $this->assertSame(TallySyncStatus::Pending, $fresh->status);
        $this->assertSame('Delivery Note', $fresh->tally_voucher_type);
        $this->assertSame((string) $delivery->id, (string) $fresh->syncable_id);
        $this->assertSame("DN-{$delivery->id}", $fresh->payload['voucher_number']);
        $this->assertNull($fresh->delivered_at);

        // Its own first row of history; the dead row's history untouched.
        $this->assertSame(2, $this->enqueuedEvents());
        $this->assertSame(['voucher.enqueued'], $fresh->events()->pluck('event')->all());
        $this->assertSame(['voucher.enqueued', 'voucher.failed', 'voucher.dismissed'], $dead->fresh()->events()->pluck('event')->all());

        // The agent is offered the fresh one only.
        $offered = $sync->pending()->pluck('id');
        $this->assertTrue($offered->contains($fresh->id));
        $this->assertFalse($offered->contains($dead->id));

        // And once a live row exists again, the guard closes again: a third
        // fire mints nothing.
        event(new DeliveryDispatched($delivery->fresh()));
        $this->assertSame(2, TallySyncEntry::query()->count());
        $this->assertSame(2, $this->enqueuedEvents());
    }

    // ---- per (syncable, voucher type), not per type ------------------------------

    public function test_a_different_source_of_the_same_type_still_gets_its_own_voucher(): void
    {
        $first = $this->dispatch('800', 'Truck A');
        $second = $this->dispatch('1200', 'Truck B');

        $entries = TallySyncEntry::query()->orderBy('id')->get();
        $this->assertCount(2, $entries, 'Two deliveries are two Delivery Notes — the guard is per source, not per type');
        $this->assertSame([(string) $first->id, (string) $second->id], $entries->map(fn ($entry) => (string) $entry->syncable_id)->all());
        $this->assertSame(["DN-{$first->id}", "DN-{$second->id}"], $entries->map(fn ($entry) => $entry->payload['voucher_number'])->all());
        $this->assertSame(2, $this->enqueuedEvents());

        // Re-firing one touches neither.
        event(new DeliveryDispatched($first->fresh()));
        $this->assertSame(2, TallySyncEntry::query()->count());
        $this->assertSame(2, $this->enqueuedEvents());
    }
}
