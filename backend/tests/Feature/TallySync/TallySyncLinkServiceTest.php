<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\EntryPresenter;
use App\Modules\TallySync\Services\TallySyncLinkService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * Phase 3.5 — TallySyncLinkService is the ONE door Sales opens into
 * TallySync: for a syncable (or many at once) it answers "which queue entry
 * stands for this document, and how is it doing" as a TallyLink — status,
 * flags and a deep link, never the payload. And TallySyncService::enqueue()
 * — the generic path behind Sales / Delivery Note / Receipt Note / Journal —
 * becomes idempotent per (syncable, voucher type), which is what lets a
 * document have exactly one live link.
 *
 *   - for(): null when no entry exists; otherwise the link, shaped exactly
 *     {entry_id, voucher_type, status, voucher_number, synced_at, flags,
 *     link} — flags straight from EntryPresenter::flags (the Delivery Note
 *     and Sales builders' unvalidated_builder warning rides it);
 *   - forMany(): one query for a whole page, keyed by syncable_id, ids with
 *     no entry simply absent;
 *   - when several entries exist for one syncable the LIVE one wins
 *     (pending/synced/failed over dismissed), newest first among equals;
 *     a lone dismissed entry still links (its status says dismissed);
 *   - synced_at is carried once the agent acks;
 *   - enqueue() replay: re-firing DeliveryDispatched for the same delivery
 *     returns the existing entry and records NO second voucher.enqueued
 *     event; re-issuing an already-issued invoice's model event likewise;
 *     after a dismissal a fresh enqueue IS allowed (the only re-issue path).
 */
class TallySyncLinkServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private Item $bottle;

    private Warehouse $fg;

    private SalesOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
        $this->order = SalesOrder::create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Confirmed, 'order_date' => '2026-08-02']);
        $this->order->lines()->create(['item_id' => $this->bottle->id, 'quantity' => '2000', 'unit_price' => '4.50', 'quantity_delivered' => 0]);

        // The Sales voucher is this file's FIXTURE VEHICLE, not its subject:
        // issuedInvoice() below exists only to put a row in the queue for the
        // link to point at. SalesVoucherPayload now refuses to stage one — and
        // stages NOTHING — without the GST masters (registration, ledger roles,
        // an HSN per item, a Tally ledger name and state on the customer, one
        // resolvable godown), so they are seeded here, at the end of setUp(),
        // where the item, the customer and the order already exist and before
        // any test issues the invoice. No godown is added: $this->fg is already
        // the sole Tally-linked warehouse, and a second would make it ambiguous.
        $this->seedSalesTallyMasterData();
    }

    // ---- for() ------------------------------------------------------------

    public function test_for_is_null_without_an_entry_and_the_link_shape_once_one_exists(): void
    {
        $delivery = $this->delivery();
        $links = app(TallySyncLinkService::class);

        $this->assertNull($links->for($delivery));

        $entry = app(TallySyncService::class)->enqueueDelivery($delivery);
        $link = $links->for($delivery);

        $this->assertSame(['entry_id', 'voucher_type', 'status', 'voucher_number', 'synced_at', 'flags', 'link'], array_keys($link));
        $this->assertSame($entry->id, $link['entry_id']);
        $this->assertSame('Delivery Note', $link['voucher_type']);
        $this->assertSame('pending', $link['status']);
        $this->assertSame("DN-{$delivery->id}", $link['voucher_number']);
        $this->assertNull($link['synced_at']);
        $this->assertSame("/tally-sync?entry={$entry->id}", $link['link']);

        // Flags are EntryPresenter's own, as an object so an empty set wires
        // as {} — the Delivery Note builder's unvalidated warning is on it.
        $this->assertIsObject($link['flags']);
        $flags = (array) $link['flags'];
        $this->assertArrayHasKey('unvalidated_builder', $flags);
        $this->assertStringContainsString('deliveryNote.ts', $flags['unvalidated_builder']['builder']);

        // Nothing of the payload: no lines, no party, no godown, no rate.
        foreach (['payload', 'lines', 'party_ledger', 'party_gstin', 'godown', 'rate', 'amount', 'error_message'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $link);
        }
        $this->assertStringNotContainsString('33AAACA1111A1Z5', json_encode($link));
    }

    public function test_a_sales_link_carries_the_decision_and_synced_at_once_acked(): void
    {
        $invoice = $this->issuedInvoice();
        $entry = TallySyncEntry::query()->sole();
        $links = app(TallySyncLinkService::class);

        $link = $links->for($invoice);
        $this->assertSame('Sales', $link['voucher_type']);
        $this->assertSame("INV-{$invoice->id}", $link['voucher_number']);
        $this->assertSame('DEC-20260831-007', ((array) $link['flags'])['unvalidated_builder']['decision']);

        app(TallySyncService::class)->markSynced($entry);

        $synced = $links->for($invoice);
        $this->assertSame('synced', $synced['status']);
        $this->assertNotNull($synced['synced_at']);
        $this->assertSame($entry->fresh()->synced_at->toIso8601String(), $synced['synced_at']);
    }

    // ---- forMany() --------------------------------------------------------

    public function test_for_many_answers_a_page_in_one_query_keyed_by_syncable_id(): void
    {
        $first = $this->delivery();
        $second = $this->delivery();
        $third = $this->delivery(); // never enqueued
        $sync = app(TallySyncService::class);
        $firstEntry = $sync->enqueueDelivery($first);
        $secondEntry = $sync->enqueueDelivery($second);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $links = app(TallySyncLinkService::class)->forMany((new Delivery)->getMorphClass(), [$first->id, $second->id, $third->id]);

        $this->assertSame(1, $queries, 'forMany must be ONE query, whatever the page size');
        $this->assertSame([$first->id, $second->id], array_keys($links));
        $this->assertSame($firstEntry->id, $links[$first->id]['entry_id']);
        $this->assertSame($secondEntry->id, $links[$second->id]['entry_id']);
        $this->assertArrayNotHasKey($third->id, $links);

        $this->assertSame([], app(TallySyncLinkService::class)->forMany((new Delivery)->getMorphClass(), []));
        $this->assertSame([], app(TallySyncLinkService::class)->forMany((new Invoice)->getMorphClass(), [$first->id]), 'The morph class scopes the lookup');
    }

    public function test_the_live_entry_wins_over_a_dismissed_one_and_a_lone_dismissed_one_still_links(): void
    {
        $delivery = $this->delivery();
        $sync = app(TallySyncService::class);
        $links = app(TallySyncLinkService::class);

        // First entry dismissed → the link says so (dismissed is a state a
        // reader must see, not a hole).
        $dismissed = $sync->enqueueDelivery($delivery);
        $sync->markFailed($dismissed, "Godown 'FG Store' does not exist!");
        $sync->dismiss($dismissed);
        $this->assertSame('dismissed', $links->for($delivery)['status']);
        $this->assertSame($dismissed->id, $links->for($delivery)['entry_id']);

        // A fresh enqueue after dismissal is the live one and wins.
        $live = $sync->enqueueDelivery($delivery);
        $this->assertNotSame($dismissed->id, $live->id);
        $this->assertSame($live->id, $links->for($delivery)['entry_id']);
        $this->assertSame('pending', $links->for($delivery)['status']);

        // An OLDER live entry beats a NEWER dismissed one (rows written by
        // hand here — the guard would not produce this order today).
        $other = $this->delivery();
        $olderLive = TallySyncEntry::create([
            'syncable_type' => $other->getMorphClass(), 'syncable_id' => $other->id,
            'tally_voucher_type' => 'Delivery Note', 'payload' => ['voucher_number' => "DN-{$other->id}"],
            'status' => TallySyncStatus::Failed, 'attempts' => 1,
        ]);
        TallySyncEntry::create([
            'syncable_type' => $other->getMorphClass(), 'syncable_id' => $other->id,
            'tally_voucher_type' => 'Delivery Note', 'payload' => ['voucher_number' => "DN-{$other->id}"],
            'status' => TallySyncStatus::Dismissed, 'attempts' => 0,
        ]);
        $this->assertSame($olderLive->id, $links->for($other)['entry_id']);
        $this->assertSame('failed', $links->for($other)['status']);
    }

    /**
     * Phase 7 (P7-03 (c)) — among a document's live entries the link is the
     * one that stands for it in Tally: synced first, then pending/delivered,
     * then failed, newest among equals; `flags.superseded_count` says how
     * many other candidates were outranked. RED before: the newest live row
     * won outright, so the legacy pair (synced older + pending newer) linked
     * the pending one.
     */
    public function test_a_synced_older_entry_outranks_a_pending_newer_one_and_the_link_says_it_was_ranked(): void
    {
        $links = app(TallySyncLinkService::class);
        $delivery = $this->delivery();

        // The legacy pair, written by hand: a voucher that reached Tally,
        // then a second row for the same document from before enqueue()
        // became idempotent.
        $syncedOlder = $this->row($delivery, TallySyncStatus::Synced, ['synced_at' => '2026-08-10 12:00:00']);
        $pendingNewer = $this->row($delivery, TallySyncStatus::Pending);
        $this->assertGreaterThan($syncedOlder->id, $pendingNewer->id);

        $link = $links->for($delivery);
        $this->assertSame($syncedOlder->id, $link['entry_id']);
        $this->assertSame('synced', $link['status']);
        $this->assertNotNull($link['synced_at']);
        $this->assertSame(1, ((array) $link['flags'])['superseded_count']);
        // The shape is unchanged — the count rides INSIDE flags.
        $this->assertSame(['entry_id', 'voucher_type', 'status', 'voucher_number', 'synced_at', 'flags', 'link'], array_keys($link));

        // failed newer + synced older → synced.
        $other = $this->delivery();
        $syncedOld = $this->row($other, TallySyncStatus::Synced, ['synced_at' => '2026-08-10 12:00:00']);
        $this->row($other, TallySyncStatus::Failed, ['attempts' => 3]);
        $this->assertSame($syncedOld->id, $links->for($other)['entry_id']);
        $this->assertSame(1, ((array) $links->for($other)['flags'])['superseded_count']);

        // two pending → the newest; three candidates → superseded 2 when a
        // failed one sits beside them.
        $third = $this->delivery();
        $this->row($third, TallySyncStatus::Pending);
        $newestPending = $this->row($third, TallySyncStatus::Pending);
        $this->assertSame($newestPending->id, $links->for($third)['entry_id']);
        $this->assertSame(1, ((array) $links->for($third)['flags'])['superseded_count']);
        $this->row($third, TallySyncStatus::Failed, ['attempts' => 1]);
        $this->assertSame($newestPending->id, $links->for($third)['entry_id'], 'a newer FAILED row does not outrank a pending one');
        $this->assertSame(2, ((array) $links->for($third)['flags'])['superseded_count']);

        // pending newer + failed older → the pending one (rank before age).
        $fourth = $this->delivery();
        $this->row($fourth, TallySyncStatus::Failed, ['attempts' => 1]);
        $pending = $this->row($fourth, TallySyncStatus::Pending);
        $this->assertSame($pending->id, $links->for($fourth)['entry_id']);

        // A lone entry is a plain answer: no superseded_count at all, and
        // the flags are exactly EntryPresenter's.
        $lone = $this->delivery();
        $only = $this->row($lone, TallySyncStatus::Pending);
        $flags = (array) $links->for($lone)['flags'];
        $this->assertArrayNotHasKey('superseded_count', $flags);
        $this->assertSame(array_keys(app(EntryPresenter::class)->flags($only)), array_keys($flags));

        // Dismissed rows never compete while a live one exists (an older
        // live entry still beats a newer dismissed one, and the dismissed
        // one is not counted as superseded); with no live row the newest
        // dismissed speaks and the others count.
        $fifth = $this->delivery();
        $failedOlder = $this->row($fifth, TallySyncStatus::Failed, ['attempts' => 1]);
        $this->row($fifth, TallySyncStatus::Dismissed);
        $this->assertSame($failedOlder->id, $links->for($fifth)['entry_id']);
        $this->assertArrayNotHasKey('superseded_count', (array) $links->for($fifth)['flags']);
        $sixth = $this->delivery();
        $this->row($sixth, TallySyncStatus::Dismissed);
        $newestDismissed = $this->row($sixth, TallySyncStatus::Dismissed);
        $this->assertSame($newestDismissed->id, $links->for($sixth)['entry_id']);
        $this->assertSame('dismissed', $links->for($sixth)['status']);
        $this->assertSame(1, ((array) $links->for($sixth)['flags'])['superseded_count']);

        // forMany answers every one of them the same way, still in ONE query.
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $many = $links->forMany((new Delivery)->getMorphClass(), [$delivery->id, $other->id, $third->id, $fourth->id, $lone->id, $fifth->id, $sixth->id]);
        $this->assertSame(1, $queries);
        $this->assertSame(
            [$syncedOlder->id, $syncedOld->id, $newestPending->id, $pending->id, $only->id, $failedOlder->id, $newestDismissed->id],
            [$many[$delivery->id]['entry_id'], $many[$other->id]['entry_id'], $many[$third->id]['entry_id'], $many[$fourth->id]['entry_id'], $many[$lone->id]['entry_id'], $many[$fifth->id]['entry_id'], $many[$sixth->id]['entry_id']],
        );
    }

    // ---- enqueue() idempotency (the replay guard) ---------------------------

    public function test_refiring_delivery_dispatched_returns_the_one_entry_and_records_no_second_enqueue(): void
    {
        $delivery = $this->delivery();

        event(new DeliveryDispatched($delivery));
        $entry = TallySyncEntry::query()->sole();
        $this->assertSame(1, TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherEnqueued->value)->count());

        // The same domain event again — a replayed request, a stale page.
        event(new DeliveryDispatched($delivery));
        event(new DeliveryDispatched($delivery->fresh()));

        $this->assertSame(1, TallySyncEntry::query()->count(), 'One delivery, one Delivery Note');
        $this->assertSame($entry->id, app(TallySyncService::class)->enqueueDelivery($delivery)->id);
        $this->assertSame(1, TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherEnqueued->value)->count(), 'No second voucher.enqueued');

        // A FAILED entry is still the live one — a re-fire returns it rather
        // than opening a second row beside it.
        app(TallySyncService::class)->markFailed($entry, "Godown 'FG Store' does not exist!");
        event(new DeliveryDispatched($delivery));
        $this->assertSame(1, TallySyncEntry::query()->count());
        $this->assertSame('failed', app(TallySyncLinkService::class)->for($delivery)['status']);
    }

    public function test_after_a_dismissal_a_fresh_enqueue_is_allowed_and_becomes_the_link(): void
    {
        $delivery = $this->delivery();
        $sync = app(TallySyncService::class);

        $first = $sync->enqueueDelivery($delivery);
        $sync->markFailed($first, "Godown 'FG Store' does not exist!");
        $sync->dismiss($first);

        $second = $sync->enqueueDelivery($delivery);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, TallySyncEntry::query()->count());
        $this->assertSame(2, TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherEnqueued->value)->count());
        $this->assertSame($second->id, app(TallySyncLinkService::class)->for($delivery)['entry_id']);

        // …and the guard holds again for the new live one.
        $this->assertSame($second->id, $sync->enqueueDelivery($delivery)->id);
        $this->assertSame(2, TallySyncEntry::query()->count());
    }

    public function test_the_guard_is_per_voucher_type_so_two_types_on_one_syncable_stay_apart(): void
    {
        $delivery = $this->delivery();
        $sync = app(TallySyncService::class);
        $note = $sync->enqueueDelivery($delivery);

        // A hand-written entry of ANOTHER type on the same syncable (no ERP
        // path does this today; the guard's key is the pair, not the row).
        $other = TallySyncEntry::create([
            'syncable_type' => $delivery->getMorphClass(), 'syncable_id' => $delivery->id,
            'tally_voucher_type' => 'Sales', 'payload' => ['voucher_number' => 'X-1'],
            'status' => TallySyncStatus::Pending, 'attempts' => 0,
        ]);

        $this->assertSame($note->id, $sync->enqueueDelivery($delivery)->id, 'The Delivery Note guard ignores the Sales row');
        $this->assertSame(2, TallySyncEntry::query()->count());
        $this->assertNotSame($other->id, $note->id);
    }

    // ---- fixtures ---------------------------------------------------------

    private function delivery(): Delivery
    {
        $delivery = Delivery::create([
            'sales_order_id' => $this->order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-10 09:00:00',
        ]);
        $delivery->lines()->create(['sales_order_line_id' => $this->order->lines()->first()->id, 'item_id' => $this->bottle->id, 'quantity' => '100']);

        return $delivery;
    }

    /**
     * A queue row for a document, written by hand — the legacy shapes the
     * ranking exists for are exactly the ones the idempotent enqueue() no
     * longer produces.
     *
     * @param  array<string, mixed>  $extra
     */
    private function row(Delivery $delivery, TallySyncStatus $status, array $extra = []): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => $delivery->getMorphClass(), 'syncable_id' => $delivery->id,
            'tally_voucher_type' => 'Delivery Note', 'payload' => ['voucher_number' => "DN-{$delivery->id}"],
            'status' => $status, 'attempts' => 0,
        ] + $extra);
    }

    private function issuedInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'sales_order_id' => $this->order->id,
            'customer_id' => $this->order->customer_id,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => '2026-08-11',
        ]);
        $invoice->lines()->create(['sales_order_line_id' => $this->order->lines()->first()->id, 'item_id' => $this->bottle->id, 'quantity' => '500', 'unit_price' => '4.50']);
        $invoice->update(['status' => InvoiceStatus::Issued]);

        return $invoice;
    }
}
