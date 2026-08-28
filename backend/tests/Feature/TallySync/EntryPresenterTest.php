<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalEntryLine;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\GrnScheduleAllocation;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderSchedule;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\EntryPresenter;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 3 (MASTER-PLAN P3-02 / P3-03): every entry the real enqueue paths
 * produce reads as a person would say it — one headline per category
 * (document, party or shift, business date, counts, status), the lines
 * beneath it, a timeline merged from the append-only history and the
 * entry's own timestamps, and honesty flags where the wire or the builder
 * differs from what the row implies.
 *
 * THE RULE THAT MUST NOT GO RED THE WRONG WAY: the summary is NOT gated by
 * finance, so it may carry quantities and counts and NEVER a rate, an
 * amount or a total (FC-06). A GRN is built here with rate 96.5 precisely
 * so the assertion "96.5 appears nowhere in the summary" is a real one.
 * The payload gate (SyncPayloadRateVisibilityTest) is untouched by this.
 */
class EntryPresenterTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL_MIGRATION = 'database/migrations/2026_08_16_100001_backfill_tally_sync_events.php';

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 0]);
        config(['tally-sync.factory_timezone' => 'UTC']);
    }

    // ---- summary per category, through the real enqueue paths --------------

    public function test_a_receipt_note_summary_names_the_document_party_date_and_count_and_carries_no_rate(): void
    {
        $grn = $this->enqueueGoodsReceipt(rate: '96.5000', quantity: '200.0000');
        $this->assertSame('96.5000', $grn->payload['lines'][0]['rate'], 'The fixture carries the rate the summary must not');
        $this->assertSame('19300.0000', $grn->payload['total_amount']);

        // For a reader who may see purchase details (finance / the agent —
        // the resource's one FC-06 verdict, passed in): the vendor is named.
        $summary = app(EntryPresenter::class)->summary($grn, mayReadPurchaseDetails: true);

        $this->assertSame(['headline', 'lines'], array_keys($summary));
        $this->assertSame(
            'Receipt Note GRN-7 · Reliance Industries · 04-Aug-2026 · 1 item × 200 · waiting for agent',
            $summary['headline'],
        );
        $this->assertSame(['PET Resin × 200 → RM Store'], $summary['lines']);

        // FC-06: no rate, no line amount, no bill total — anywhere in it.
        $text = json_encode($summary);
        $this->assertStringNotContainsString('96.5', $text);
        $this->assertStringNotContainsString('19300', $text);

        // FC-06, second half: for everyone else the vendor segment is left
        // out of the headline (not blanked) — and that is the DEFAULT, so a
        // caller that forgets to say withholds rather than leaks.
        foreach ([app(EntryPresenter::class)->summary($grn, mayReadPurchaseDetails: false), app(EntryPresenter::class)->summary($grn)] as $withheld) {
            $this->assertSame('Receipt Note GRN-7 · 04-Aug-2026 · 1 item × 200 · waiting for agent', $withheld['headline']);
            $this->assertStringNotContainsString('Reliance', json_encode($withheld));
        }
    }

    public function test_a_shift_stock_journal_summary_counts_batches_and_both_sides(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        $voucher = $this->approveShiftProduction();
        $summary = app(EntryPresenter::class)->summary($voucher);

        // Two batches (B-1, B-2) approved into ONE shift voucher: one
        // produced line (same bottle, same store, summed) and two consumed
        // lines (resin, masterbatch — resin issued twice merges).
        $this->assertSame(
            "Stock Journal {$voucher->payload['voucher_number']} · Shift Morning · 23-Jul-2026 · 2 batches · produced 1 item, consumed 2 · waiting for agent",
            $summary['headline'],
        );
        $this->assertSame([
            'Produced: 500ml PET Bottle × 7000 → FG Store',
            'Consumed: PET Resin × 520 ← RM Store',
            'Consumed: Masterbatch Amber × 4 ← RM Store',
        ], $summary['lines']);
    }

    public function test_a_batch_voucher_summary_names_the_batch_and_says_stock_journal_like_the_wire(): void
    {
        config(['tally-sync.voucher_granularity' => 'batch']);

        $entry = $this->approveBatchProduction();
        $this->assertSame('Manufacturing Journal', $entry->tally_voucher_type, 'precondition: the ERP label');

        $summary = app(EntryPresenter::class)->summary($entry);

        // The headline names what Tally RECEIVES (a Stock Journal); the
        // ERP's own label rides the flags, not the sentence.
        $this->assertSame(
            'Stock Journal SPE-9 · batch L1-N-20260723-1 · 23-Jul-2026 · produced 1 item, consumed 1 · waiting for agent',
            $summary['headline'],
        );
        $this->assertSame([
            'Produced: 500ml PET Bottle × 5000 → FG Store',
            'Consumed: PET Resin × 250 ← Raw Material Store',
        ], $summary['lines']);
    }

    public function test_a_sales_invoice_summary_carries_the_customer_and_no_price(): void
    {
        $entry = $this->enqueueSalesInvoice();
        $this->assertSame('4.5000', $entry->payload['lines'][0]['rate']);

        $summary = app(EntryPresenter::class)->summary($entry);

        $this->assertSame(
            'Sales INV-5 · Sri Aurobindo Beverages · 01-Aug-2026 · 1 item × 1000 · waiting for agent',
            $summary['headline'],
        );
        $this->assertSame(['500ml PET Bottle × 1000'], $summary['lines']);
        $text = json_encode($summary);
        $this->assertStringNotContainsString('4.5', $text);
        $this->assertStringNotContainsString('4500', $text);
    }

    public function test_a_journal_summary_counts_ledger_lines_and_names_no_amount(): void
    {
        $entry = app(TallySyncService::class)->enqueueJournalEntry($this->journal());

        $summary = app(EntryPresenter::class)->summary($entry);

        $this->assertSame('Journal JE-REF-9 · 02-Aug-2026 · 2 ledger lines · waiting for agent', $summary['headline']);
        // Sides and ledger names, never the debit/credit figures.
        $this->assertSame(['Dr 4000 - Sales', 'Cr 1200 - Debtors'], $summary['lines']);
        $this->assertStringNotContainsString('100', json_encode($summary));
    }

    public function test_a_delivery_note_summary_carries_the_customer_and_the_items(): void
    {
        $entry = $this->enqueueDelivery();

        // A CUSTOMER is not FC-06: named for every reader, purchase-detail
        // verdict or not (the default is the withholding one).
        $summary = app(EntryPresenter::class)->summary($entry);

        $this->assertSame(
            'Delivery Note DN-3 · Sri Aurobindo Beverages · 03-Aug-2026 · 2 items · waiting for agent',
            $summary['headline'],
        );
        $this->assertSame(['500ml PET Bottle × 2000 ← FG Store', '1L PET Bottle × 500 ← FG Store'], $summary['lines']);
    }

    public function test_an_unclassified_entry_is_summarised_honestly_not_guessed(): void
    {
        $entry = $this->rawEntry((new Invoice)->getMorphClass(), 'Purchase', ['voucher_number' => 'PUR-1', 'voucher_date' => '2026-08-05']);

        $summary = app(EntryPresenter::class)->summary($entry);

        $this->assertSame("Unclassified voucher type 'Purchase' PUR-1 · 05-Aug-2026 · waiting for agent", $summary['headline']);
        $this->assertSame([], $summary['lines']);
    }

    public function test_the_status_phrase_follows_the_voucher_through_its_life(): void
    {
        $sync = app(TallySyncService::class);
        $presenter = app(EntryPresenter::class);
        $agent = $this->agentUser('factory-pc');
        $entry = $sync->enqueueJournalEntry($this->journal());

        $phrase = fn () => last(explode(' · ', $presenter->summary($entry->fresh())['headline']));

        $this->assertSame('waiting for agent', $phrase());

        $sync->pending($agent);
        $this->assertSame('with the agent', $phrase());

        $sync->markFailed($entry->fresh(), 'Godown does not exist', $agent);
        $this->assertSame('failed (attempt 1)', $phrase());

        $sync->retry($entry->fresh(), null, $this->staff());
        $this->assertSame('waiting for agent', $phrase());

        $sync->pending($agent);
        $sync->markSynced($entry->fresh(), $agent);
        $this->assertSame('in Tally', $phrase());

        $dismissed = $this->rawEntry((new Invoice)->getMorphClass(), 'Sales', ['voucher_number' => 'INV-9'], [
            'status' => TallySyncStatus::Dismissed, 'error_message' => 'x', 'attempts' => 3,
        ]);
        $this->assertSame('dismissed', last(explode(' · ', $presenter->summary($dismissed)['headline'])));
    }

    // ---- timeline: events + timestamps, ordered, de-duplicated --------------

    public function test_the_timeline_merges_backfilled_and_live_events_in_time_order_without_duplicating_the_columns(): void
    {
        $sync = app(TallySyncService::class);
        $agent = $this->agentUser('factory-pc');
        $accountant = $this->staff('Priya Accounts');

        // A pre-history row: created and delivered before the recorder
        // existed, then reconstructed by the backfill migration.
        $this->travelTo(Carbon::parse('2026-07-20 08:00:00'));
        $entry = $this->rawEntry((new ShiftProductionEntry)->getMorphClass(), 'Manufacturing Journal', ['voucher_number' => 'SPE-1'], [
            'delivered_at' => '2026-07-20 08:05:00',
        ]);
        $this->travelTo(Carbon::parse('2026-08-16 10:00:00'));
        $this->backfill()->up();
        $this->assertSame(['voucher.enqueued', 'pending.delivered'], $entry->events()->pluck('event')->all(), 'precondition: two backfilled rows');

        // Then its live life: failed → retried by a person → delivered again.
        $this->travelTo(Carbon::parse('2026-08-16 11:00:00'));
        $sync->markFailed($entry->fresh(), 'Stock Item does not exist', $agent);
        $this->travelTo(Carbon::parse('2026-08-16 11:30:00'));
        $sync->retry($entry->fresh(), $accountant->id, $accountant);
        $this->travelTo(Carbon::parse('2026-08-16 11:31:00'));
        $sync->pending($agent);

        $timeline = app(EntryPresenter::class)->timeline($entry->fresh());

        $this->assertSame([
            ['2026-07-20T08:00:00+00:00', 'voucher.enqueued', 'backfill'],
            ['2026-07-20T08:05:00+00:00', 'pending.delivered', 'backfill'],
            ['2026-08-16T11:00:00+00:00', 'voucher.failed', 'event'],
            ['2026-08-16T11:30:00+00:00', 'voucher.retried', 'event'],
            ['2026-08-16T11:31:00+00:00', 'pending.delivered', 'event'],
        ], array_map(fn (array $row) => [$row['at'], $row['event'], $row['source']], $timeline));

        // One shape for every row, whatever produced it.
        foreach ($timeline as $row) {
            $this->assertSame(['at', 'event', 'actor_type', 'actor_label', 'detail', 'source', 'backfilled'], array_keys($row));
        }

        // Backfilled rows are flagged; live rows are not; the columns
        // (created_at, delivered_at) added NO extra row because an event
        // of that kind already stands at that instant.
        $this->assertSame([true, true, false, false, false], array_column($timeline, 'backfilled'));
        $this->assertSame(['backfill 2026-08-16', 'backfill 2026-08-16', 'factory-pc', 'Priya Accounts', 'factory-pc'], array_column($timeline, 'actor_label'));
        $this->assertSame(['system', 'system', 'agent', 'user', 'agent'], array_column($timeline, 'actor_type'));

        // The detail sentences carry what the columns lost: the error that
        // was overwritten and who retried with what.
        $this->assertStringContainsString('Stock Item does not exist', $timeline[2]['detail']);
        $this->assertStringContainsString('attempt 1', $timeline[2]['detail']);
        $this->assertStringContainsString('Stock Item does not exist', $timeline[3]['detail']);
    }

    public function test_a_timestamp_with_no_event_becomes_a_flagged_row_and_one_with_an_event_does_not(): void
    {
        // A row whose history began AFTER it was created (no enqueue event,
        // no backfill) and was then acked live: created_at has nothing to
        // vouch for it but the column itself; synced_at has a real event.
        $this->travelTo(Carbon::parse('2026-08-10 09:00:00'));
        $entry = $this->rawEntry((new ShiftProductionEntry)->getMorphClass(), 'Manufacturing Journal', ['voucher_number' => 'SPE-2']);
        $this->assertSame(0, $entry->events()->count());

        $this->travelTo(Carbon::parse('2026-08-10 09:20:00'));
        app(TallySyncService::class)->markSynced($entry->fresh(), $this->agentUser('factory-pc'));

        $timeline = app(EntryPresenter::class)->timeline($entry->fresh());

        $this->assertSame([
            ['2026-08-10T09:00:00+00:00', 'voucher.enqueued', 'timestamp', true, null],
            ['2026-08-10T09:20:00+00:00', 'voucher.synced', 'event', false, 'factory-pc'],
        ], array_map(fn (array $row) => [$row['at'], $row['event'], $row['source'], $row['backfilled'], $row['actor_label']], $timeline));

        $this->assertStringContainsString('created_at', $timeline[0]['detail'], 'a derived row says which column it came from');
    }

    // ---- flags -----------------------------------------------------------------

    public function test_every_unvalidated_builder_is_flagged_quoting_its_own_line_and_sales_names_the_gst_gap(): void
    {
        $presenter = app(EntryPresenter::class);
        $unvalidated = 'BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE';

        // Sales: the shared line, PLUS its GST gap (salesInvoice.ts:28-32)
        // and the decision that keeps real sales in Tally.
        $sales = $presenter->flags($this->enqueueSalesInvoice())['unvalidated_builder'];
        $this->assertStringContainsString($unvalidated, $sales['note']);
        $this->assertStringContainsString("doesn't yet emit GST tax ledger entries (CGST/SGST/IGST)", $sales['note']);
        $this->assertStringContainsString('DEC-20260809-003', $sales['note']);
        $this->assertSame('tally-sync-agent/src/tally/voucherBuilders/salesInvoice.ts', $sales['builder']);
        $this->assertSame('DEC-20260809-003', $sales['decision']);

        // Receipt Note (receiptNote.ts:17), Delivery Note (deliveryNote.ts:16)
        // and Journal (journalEntry.ts:13) carry the SAME line — each flag
        // quotes it and names its own builder; none borrows the Sales GST
        // note or the Sales decision.
        $receipt = $presenter->flags($this->enqueueGoodsReceipt())['unvalidated_builder'];
        $this->assertStringContainsString($unvalidated, $receipt['note']);
        $this->assertStringContainsString('Reverse-engineer against a real export before trusting it', $receipt['note']);
        $this->assertSame('tally-sync-agent/src/tally/voucherBuilders/receiptNote.ts', $receipt['builder']);
        $this->assertArrayNotHasKey('decision', $receipt);
        $this->assertStringNotContainsString('GST', $receipt['note']);

        $delivery = $presenter->flags($this->enqueueDelivery())['unvalidated_builder'];
        $this->assertStringContainsString($unvalidated, $delivery['note']);
        $this->assertStringContainsString('Validate the tag structure against a real export', $delivery['note']);
        $this->assertSame('tally-sync-agent/src/tally/voucherBuilders/deliveryNote.ts', $delivery['builder']);
        $this->assertArrayNotHasKey('decision', $delivery);

        $journal = $presenter->flags(app(TallySyncService::class)->enqueueJournalEntry($this->journal()))['unvalidated_builder'];
        $this->assertStringContainsString($unvalidated, $journal['note']);
        $this->assertStringContainsString('still confirm against a real export before trusting it in production', $journal['note']);
        $this->assertSame('tally-sync-agent/src/tally/voucherBuilders/journalEntry.ts', $journal['builder']);
        $this->assertArrayNotHasKey('decision', $journal);

        // The production builders carry no such line: not raised.
        config(['tally-sync.voucher_granularity' => 'batch']);
        $this->assertArrayNotHasKey('unvalidated_builder', $presenter->flags($this->approveBatchProduction()));
        TallySyncEntry::query()->where('tally_voucher_type', 'Manufacturing Journal')->delete();
        config(['tally-sync.voucher_granularity' => 'shift']);
        $this->assertArrayNotHasKey('unvalidated_builder', $presenter->flags($this->approveShiftProduction()));
    }

    public function test_a_receipt_note_carrying_an_order_reference_is_flagged_because_the_agent_does_not_emit_it(): void
    {
        $with = $this->enqueueGoodsReceipt(tallyOrderNo: 'PO/2026/17', dueDate: '2026-08-10');
        $this->assertSame('PO/2026/17', $with->payload['tally_order_no'], 'precondition: the reference IS on the payload');
        $this->assertCount(1, $with->payload['order_due_dates']);

        $flags = app(EntryPresenter::class)->flags($with);
        $this->assertArrayHasKey('order_reference_not_emitted', $flags);
        $this->assertSame('PO/2026/17', $flags['order_reference_not_emitted']['tally_order_no']);
        $this->assertSame(1, $flags['order_reference_not_emitted']['order_due_dates']);
        $this->assertStringContainsString('receiptNote.ts', $flags['order_reference_not_emitted']['note']);
        $this->assertStringContainsString('does not reach Tally', $flags['order_reference_not_emitted']['note']);

        // A receipt with no order reference has nothing un-emitted to flag.
        TallySyncEntry::query()->delete();
        $without = $this->enqueueGoodsReceipt();
        $this->assertNull($without->payload['tally_order_no']);
        $this->assertSame([], $without->payload['order_due_dates']);
        $this->assertArrayNotHasKey('order_reference_not_emitted', app(EntryPresenter::class)->flags($without));
    }

    public function test_a_batch_voucher_is_flagged_where_the_erp_label_differs_from_the_wire(): void
    {
        config(['tally-sync.voucher_granularity' => 'batch']);
        $batch = $this->approveBatchProduction();

        $flags = app(EntryPresenter::class)->flags($batch);
        $this->assertSame('Manufacturing Journal', $flags['label_differs_from_wire']['erp_label']);
        $this->assertSame('Stock Journal', $flags['label_differs_from_wire']['wire_voucher_type']);
        $this->assertStringContainsString('Stock Journal', $flags['label_differs_from_wire']['note']);

        // The shift voucher is labelled as it is posted: no flag.
        TallySyncEntry::query()->delete();
        config(['tally-sync.voucher_granularity' => 'shift']);
        $shift = $this->approveShiftProduction();
        $this->assertArrayNotHasKey('label_differs_from_wire', app(EntryPresenter::class)->flags($shift));
    }

    public function test_a_held_shift_voucher_is_flagged_by_the_real_gate_and_released_by_the_clock(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);
        config(['tally-sync.release_idle_minutes' => 15]);

        // Mid-shift (Morning ends 14:00, factory tz pinned to UTC above) the
        // gate holds it — 'collecting'; the second batch merges at 13:55, so
        // the quiet period runs to 14:10.
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $voucher = $this->approveShiftProduction(secondApprovalAt: Carbon::parse('2026-07-23 13:55:00'));

        $this->travelTo(Carbon::parse('2026-07-23 13:56:00'));
        $presenter = app(EntryPresenter::class);
        $flags = $presenter->flags($voucher->fresh());
        $this->assertSame('collecting', $flags['held']['phase']);
        $this->assertSame('2026-07-23T14:10:00+00:00', $flags['held']['releasable_at']);
        $this->assertStringContainsString('collecting', $flags['held']['note']);
        $this->assertStringEndsWith('held — shift still collecting', $presenter->summary($voucher->fresh())['headline']);

        // Just after shift end: the quiet period.
        $this->travelTo(Carbon::parse('2026-07-23 14:01:00'));
        $flags = $presenter->flags($voucher->fresh());
        $this->assertSame('quiet-period', $flags['held']['phase']);
        $this->assertSame('2026-07-23T14:10:00+00:00', $flags['held']['releasable_at']);
        $this->assertStringEndsWith('held — quiet period', $presenter->summary($voucher->fresh())['headline']);

        // Idle-hold satisfied: no flag, ordinary status.
        $this->travelTo(Carbon::parse('2026-07-23 14:20:00'));
        $this->assertArrayNotHasKey('held', $presenter->flags($voucher->fresh()));
        $this->assertStringEndsWith('waiting for agent', $presenter->summary($voucher->fresh())['headline']);
    }

    // ---- the resource: show carries all three; the list carries flags only ---

    public function test_show_carries_summary_timeline_and_flags_and_the_list_carries_flags_only(): void
    {
        $sync = app(TallySyncService::class);
        $invoice = $this->enqueueSalesInvoice();
        $sync->pending($this->agentUser('factory-pc'));

        // A reader WITHOUT finance: the summary must still be whole (it
        // carries no rate) while the payload is gated as before.
        $this->actAsStaff(['tally-sync.view', 'tally-sync.manage']);

        $shown = $this->getJson("/api/v1/tally-sync/entries/{$invoice->id}")->assertOk()->json('data');
        $this->assertSame(
            'Sales INV-5 · Sri Aurobindo Beverages · 01-Aug-2026 · 1 item × 1000 · with the agent',
            $shown['summary']['headline'],
        );
        $this->assertSame(['voucher.enqueued', 'pending.delivered'], array_column($shown['timeline'], 'event'));
        $this->assertArrayHasKey('unvalidated_builder', $shown['flags']);
        $this->assertArrayNotHasKey('rate', $shown['payload']['lines'][0], 'the payload gate is untouched');
        $this->assertStringNotContainsString('4.5', json_encode([$shown['summary'], $shown['timeline'], $shown['flags']]));

        // The three keys sit together, immediately before history.
        $keys = array_keys($shown);
        $this->assertSame(['summary', 'timeline', 'flags', 'history'], array_slice($keys, array_search('summary', $keys, true), 4));

        // The list: flags (cheap, the Sales banner needs them) but never the
        // summary or the timeline — a page of 200 vouchers stays light.
        $row = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))->firstWhere('id', $invoice->id);
        $this->assertArrayHasKey('unvalidated_builder', $row['flags']);
        $this->assertArrayNotHasKey('summary', $row);
        $this->assertArrayNotHasKey('timeline', $row);
        $this->assertArrayNotHasKey('history', $row);

        // Nor do the action responses (same resource, relation not loaded).
        $failed = $this->rawEntry((new Invoice)->getMorphClass(), 'Sales', ['voucher_number' => 'INV-8'], [
            'status' => TallySyncStatus::Failed, 'error_message' => 'x', 'attempts' => 1,
        ]);
        $retried = $this->postJson("/api/v1/tally-sync/entries/{$failed->id}/retry")->assertOk()->json('data');
        $this->assertArrayNotHasKey('summary', $retried);
        $this->assertArrayNotHasKey('timeline', $retried);
        $this->assertArrayHasKey('flags', $retried);
    }

    public function test_flags_serialise_as_an_object_on_the_wire_even_when_none_is_raised(): void
    {
        // A shift voucher past its gate raises no flag at all; a Sales
        // invoice raises one. PHP would encode the empty array as `[]` and
        // the other as `{}` — two shapes for one key — so the resource casts
        // to an object and a client typed Record<string, …> never meets a list.
        config(['tally-sync.voucher_granularity' => 'shift']);
        $shift = $this->approveShiftProduction();
        $this->assertSame([], app(EntryPresenter::class)->flags($shift), 'precondition: nothing raised');
        $invoice = $this->enqueueSalesInvoice();

        $this->actAsStaff(['tally-sync.view']);

        $list = $this->getJson('/api/v1/tally-sync/entries')->assertOk()->getContent();
        $this->assertStringContainsString('"flags":{}', $list);
        $this->assertStringNotContainsString('"flags":[]', $list);
        $this->assertStringContainsString('"flags":{"unvalidated_builder":', $list);

        $this->assertStringContainsString('"flags":{}', $this->getJson("/api/v1/tally-sync/entries/{$shift->id}")->assertOk()->getContent());
        $this->assertStringContainsString('"flags":{"unvalidated_builder":', $this->getJson("/api/v1/tally-sync/entries/{$invoice->id}")->assertOk()->getContent());
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * The GRN of OutboundVoucherTest through the real event → listener →
     * enqueue chain, with the rate and quantity as parameters (the rate is
     * what the summary must NOT show) and, optionally, the Tally order
     * reference and one schedule allocation the payload carries as
     * tally_order_no / order_due_dates.
     */
    private function enqueueGoodsReceipt(
        string $rate = '85.0000',
        string $quantity = '100.0000',
        ?string $tallyOrderNo = null,
        ?string $dueDate = null,
    ): TallySyncEntry {
        $po = new PurchaseOrder(['tally_order_no' => $tallyOrderNo]);
        // tally_ledger_name / tally_stock_item_guid / tally_guid: the enqueue
        // refuses unmapped identities since the 28-Aug rehearsal fix, so a
        // postable fixture carries them (the guid values are synthetic).
        $po->setRelation('vendor', new Vendor(['name' => 'Reliance Industries', 'gstin' => '27AAACR1234A1Z5', 'tally_ledger_name' => 'Reliance Industries']));

        $line = new GoodsReceiptNoteLine(['quantity' => $quantity, 'unit_cost' => $rate]);
        $line->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin', 'tally_stock_item_guid' => 'guid-res-1']));

        $allocations = collect();
        if ($dueDate !== null) {
            $allocation = new GrnScheduleAllocation(['quantity' => $quantity]);
            $allocation->setRelation('schedule', new PurchaseOrderSchedule(['due_date' => $dueDate, 'tally_reference' => 'SCH-1']));
            $allocations->push($allocation);
        }
        $line->setRelation('scheduleAllocations', $allocations);

        $grn = $this->existing(new GoodsReceiptNote(['received_date' => '2026-08-04']), 7);
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', new Warehouse(['name' => 'RM Store', 'tally_guid' => 'guid-wh-rm']));
        $grn->setRelation('purchaseOrder', $po);

        event(new GoodsReceiptNoteReceived($grn));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Receipt Note')->latest('id')->firstOrFail();
    }

    private function enqueueSalesInvoice(): TallySyncEntry
    {
        $line = new InvoiceLine(['quantity' => '1000.0000', 'unit_price' => '4.5000']);
        $line->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $invoice = $this->existing(new Invoice(['invoice_date' => '2026-08-01']), 5);
        $invoice->setRelation('lines', collect([$line]));
        $invoice->setRelation('customer', new Customer(['name' => 'Sri Aurobindo Beverages']));

        return app(TallySyncService::class)->enqueueSalesInvoice($invoice);
    }

    private function enqueueDelivery(): TallySyncEntry
    {
        $so = new SalesOrder;
        $so->setRelation('customer', new Customer(['name' => 'Sri Aurobindo Beverages']));

        $bottle = new DeliveryLine(['quantity' => '2000.0000']);
        $bottle->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));
        $litre = new DeliveryLine(['quantity' => '500.0000']);
        $litre->setRelation('item', new Item(['sku' => 'BTL-1000', 'name' => '1L PET Bottle']));

        $delivery = $this->existing(new Delivery(['delivered_date' => '2026-08-03']), 3);
        $delivery->setRelation('lines', collect([$bottle, $litre]));
        $delivery->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $delivery->setRelation('salesOrder', $so);

        event(new DeliveryDispatched($delivery));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Delivery Note')->sole();
    }

    /** A two-line journal (Dr Sales / Cr Debtors), in memory, for the real enqueue path. */
    private function journal(): JournalEntry
    {
        $debit = new JournalEntryLine(['debit' => '100.0000', 'credit' => '0.0000']);
        $debit->setRelation('glAccount', new GLAccount(['code' => '4000', 'name' => 'Sales']));
        $credit = new JournalEntryLine(['debit' => '0.0000', 'credit' => '100.0000']);
        $credit->setRelation('glAccount', new GLAccount(['code' => '1200', 'name' => 'Debtors']));

        $journal = $this->existing(new JournalEntry(['entry_date' => '2026-08-02', 'reference' => 'JE-REF-9']), 4);
        $journal->setRelation('lines', collect([$debit, $credit]));

        return $journal;
    }

    /** The batch-mode fixture of TransactionClassifierTest: one entry, one resin line. */
    private function approveBatchProduction(): TallySyncEntry
    {
        $consumption = new ShiftMaterialConsumption(['quantity_issued_kg' => '250.0000']);
        $consumption->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin']));
        $consumption->setRelation('warehouse', new Warehouse(['name' => 'Raw Material Store']));

        $spe = $this->existing(new ShiftProductionEntry([
            'production_date' => '2026-07-23',
            'quantity_produced' => '5000.0000',
            'batch_number' => 'L1-N-20260723-1',
        ]), 9);
        $spe->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));
        $spe->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $spe->setRelation('materialConsumptions', collect([$consumption]));

        event(new ShiftProductionEntryApproved($spe));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Manufacturing Journal')->sole();
    }

    /**
     * TWO batches of one shift approved through the real four-eyes path
     * into ONE shift voucher: B-1 (5000 bottles; resin 250 + 10, masterbatch
     * 2) and B-2 (2000 bottles; resin 260, masterbatch 2) — so the summary
     * has batches to count and lines that merge.
     */
    private function approveShiftProduction(?Carbon $secondApprovalAt = null): TallySyncEntry
    {
        $shift = Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::firstOrCreate(['code' => 'M-01'], ['name' => 'Machine 1']);
        $bottle = Item::firstOrCreate(['sku' => 'BTL-500'], ['name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $resin = Item::firstOrCreate(['sku' => 'RES-1'], ['name' => 'PET Resin', 'uom' => 'KG']);
        $masterbatch = Item::firstOrCreate(['sku' => 'MB-AMB'], ['name' => 'Masterbatch Amber', 'uom' => 'KG']);
        $fgStore = Warehouse::firstOrCreate(['code' => 'WH-FG'], ['name' => 'FG Store']);
        $rmStore = Warehouse::firstOrCreate(['code' => 'WH-RM'], ['name' => 'RM Store']);

        $service = app(ShiftProductionEntryService::class);

        foreach ([
            ['B-1', '5000', [[$resin, '250.0000'], [$masterbatch, '2.0000'], [$resin, '10.0000']]],
            ['B-2', '2000', [[$resin, '260.0000'], [$masterbatch, '2.0000']]],
        ] as [$batch, $produced, $consumptions]) {
            if ($batch === 'B-2' && $secondApprovalAt !== null) {
                $this->travelTo($secondApprovalAt);
            }

            $entry = ShiftProductionEntry::create([
                'shift_id' => $shift->id,
                'work_center_id' => $machine->id,
                'item_id' => $bottle->id,
                'warehouse_id' => $fgStore->id,
                'production_date' => '2026-07-23',
                'batch_status' => BatchStatus::Completed,
                'batch_number' => $batch,
                'quantity_produced' => $produced,
                'quantity_scrap' => '0',
                'status' => ShiftProductionEntryStatus::Pending,
            ]);

            foreach ($consumptions as [$item, $kg]) {
                $entry->materialConsumptions()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $rmStore->id,
                    'quantity_issued_kg' => $kg,
                ]);
            }

            $service->pmApprove($entry, User::factory()->create()->id);
            $service->accountantApprove($entry->fresh(), User::factory()->create()->id);
        }

        return TallySyncEntry::query()->where('syncable_type', (new Shift)->getMorphClass())->sole();
    }

    /** A row written straight to the table — no enqueue path, no event. */
    private function rawEntry(string $morph, string $voucherType, array $payload = [], array $attributes = []): TallySyncEntry
    {
        return TallySyncEntry::create(array_merge([
            'syncable_type' => $morph,
            'syncable_id' => 999,
            'tally_voucher_type' => $voucherType,
            'payload' => ['voucher_type' => $voucherType] + $payload,
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ], $attributes));
    }

    private function backfill(): Migration
    {
        return require base_path(self::BACKFILL_MIGRATION);
    }

    /** The agent as the SERVICE sees it: a user carrying its real, named token. */
    private function agentUser(string $tokenName): User
    {
        $user = User::factory()->create(['name' => 'Tally Sync Agent', 'is_active' => true]);
        $issued = $user->createToken($tokenName, ['tally-sync:poll', 'tally-sync:report']);

        return $user->withAccessToken($issued->accessToken);
    }

    private function staff(string $name = 'Priya Accounts'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach (['tally-sync.view', 'tally-sync.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** @param  list<string>  $permissions */
    private function actAsStaff(array $permissions): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    /** Mark an in-memory model as an existing (persisted) record without a DB write. */
    private function existing(object $model, int $id): object
    {
        $model->id = $id;
        $model->exists = true;

        return $model;
    }
}
