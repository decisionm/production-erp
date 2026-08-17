<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Http\Resources\TallySyncSnapshotResource;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\AgentTokenService;
use App\Modules\TallySync\Services\PayloadHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FC-06 ON THE SNAPSHOTS (Phase 4, P4-02): who may read the XML the agent
 * sent, and Tally's own words about it, on GET /tally-sync/entries/{id}.
 *
 * Which XML carries what — Receipt Note: supplier party + RATE/AMOUNT (both
 * halves of FC-06); Sales: customer party + selling RATE/AMOUNT (rates on
 * the same resource, gated alike); Delivery Note: customer party,
 * quantities; Journal: ledger names + DEBIT/CREDIT; Stock Journal (both
 * production categories): items, godowns, quantities, batch — no rate, no
 * party. The rule, fail-closed: the FULL XML is shown to a reader for whom
 * AgentIdentity::mayReadPurchaseDetails() is true, and to every
 * tally-sync.view reader for a Stock Journal; for every other voucher type
 * it is WITHHELD whole (never a partial redaction of XML text) with a note
 * saying why. Tally's message follows the SAME rule as error_message today:
 * withheld iff the voucher's party is a supplier and the reader may not.
 *
 * Four callers, four voucher families:
 *   tally-sync.view only → Receipt Note: neither xml nor message; Sales /
 *       Delivery Note / Journal: xml withheld, message shown; Stock
 *       Journal: xml SHOWN;
 *   finance.view / finance.manage → all of it, exactly as stored;
 *   THE AGENT (real PAT) → reads back exactly what it sent, every type;
 *   no permission → 403 on show, and nothing on the wire.
 * What every tally-sync.view reader always gets: sha256, byte size, agent
 * version, attempt, when, payload_matches, and Tally's {success, created,
 * errors}.
 */
class SnapshotVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const VENDOR = 'Reliance Industries';

    private const VENDOR_GSTIN = '27AAACR1234A1Z5';

    private const CUSTOMER = 'Sri Aurobindo Beverages';

    /** A Receipt Note as receiptNote.ts writes it: the vendor's ledger and GSTIN, RATE and AMOUNT. */
    private const RECEIPT_NOTE_XML = '<ENVELOPE><BODY><IMPORTDATA><REQUESTDATA><TALLYMESSAGE><VOUCHER VCHTYPE="Receipt Note"><VOUCHERTYPENAME>Receipt Note</VOUCHERTYPENAME><VOUCHERNUMBER>GRN-7</VOUCHERNUMBER><PARTYLEDGERNAME>Reliance Industries</PARTYLEDGERNAME><PARTYGSTIN>27AAACR1234A1Z5</PARTYGSTIN><ALLINVENTORYENTRIES.LIST><STOCKITEMNAME>PET Resin</STOCKITEMNAME><RATE>85.0000/kg</RATE><AMOUNT>-8500.0000</AMOUNT><ACTUALQTY>100 kg</ACTUALQTY></ALLINVENTORYENTRIES.LIST></VOUCHER></TALLYMESSAGE></REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';

    private const SALES_XML = '<ENVELOPE><BODY><IMPORTDATA><REQUESTDATA><TALLYMESSAGE><VOUCHER VCHTYPE="Sales"><VOUCHERTYPENAME>Sales</VOUCHERTYPENAME><VOUCHERNUMBER>INV-12</VOUCHERNUMBER><PARTYLEDGERNAME>Sri Aurobindo Beverages</PARTYLEDGERNAME><ALLINVENTORYENTRIES.LIST><STOCKITEMNAME>500ml PET Bottle</STOCKITEMNAME><RATE>4.2500/nos</RATE><AMOUNT>8500.0000</AMOUNT></ALLINVENTORYENTRIES.LIST></VOUCHER></TALLYMESSAGE></REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';

    private const DELIVERY_NOTE_XML = '<ENVELOPE><BODY><IMPORTDATA><REQUESTDATA><TALLYMESSAGE><VOUCHER VCHTYPE="Delivery Note"><VOUCHERTYPENAME>Delivery Note</VOUCHERTYPENAME><VOUCHERNUMBER>DN-3</VOUCHERNUMBER><PARTYLEDGERNAME>Sri Aurobindo Beverages</PARTYLEDGERNAME><ALLINVENTORYENTRIES.LIST><STOCKITEMNAME>500ml PET Bottle</STOCKITEMNAME><ACTUALQTY>2000 nos</ACTUALQTY></ALLINVENTORYENTRIES.LIST></VOUCHER></TALLYMESSAGE></REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';

    private const JOURNAL_XML = '<ENVELOPE><BODY><IMPORTDATA><REQUESTDATA><TALLYMESSAGE><VOUCHER VCHTYPE="Journal"><VOUCHERTYPENAME>Journal</VOUCHERTYPENAME><VOUCHERNUMBER>JE-REF-9</VOUCHERNUMBER><ALLLEDGERENTRIES.LIST><LEDGERNAME>4000 - Sales</LEDGERNAME><ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE><AMOUNT>-100.0000</AMOUNT></ALLLEDGERENTRIES.LIST><ALLLEDGERENTRIES.LIST><LEDGERNAME>1200 - Debtors</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>100.0000</AMOUNT></ALLLEDGERENTRIES.LIST></VOUCHER></TALLYMESSAGE></REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';

    private const STOCK_JOURNAL_XML = '<ENVELOPE><BODY><IMPORTDATA><REQUESTDATA><TALLYMESSAGE><VOUCHER VCHTYPE="Stock Journal"><VOUCHERTYPENAME>Stock Journal</VOUCHERTYPENAME><VOUCHERNUMBER>SJ-20260810-S1</VOUCHERNUMBER><INVENTORYENTRIESIN.LIST><STOCKITEMNAME>500ml PET Bottle</STOCKITEMNAME><GODOWNNAME>FG Store</GODOWNNAME><ACTUALQTY>2000 nos</ACTUALQTY></INVENTORYENTRIESIN.LIST><INVENTORYENTRIESOUT.LIST><STOCKITEMNAME>Relpet</STOCKITEMNAME><GODOWNNAME>RM Store</GODOWNNAME><ACTUALQTY>50 kg</ACTUALQTY></INVENTORYENTRIESOUT.LIST></VOUCHER></TALLYMESSAGE></REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';

    private ?string $agentToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tally-sync.release_idle_minutes' => 0]);
    }

    // ---- (a) tally-sync.view only, on a Receipt Note: neither the XML nor Tally's words ----

    public function test_a_tally_sync_only_reader_on_a_receipt_note_gets_the_facts_and_neither_the_xml_nor_tallys_words(): void
    {
        $grn = $this->receiptNote();
        $this->uploaded($grn, self::RECEIPT_NOTE_XML, [
            'success' => false, 'created' => 0, 'errors' => 1,
            'message' => 'Ledger does not exist : '.self::VENDOR,
            'raw' => '<RESPONSE><LINEERROR>Ledger does not exist : '.self::VENDOR.'</LINEERROR></RESPONSE>',
        ]);

        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk();
        $snapshots = $shown->json('data.snapshots');
        $this->assertCount(1, $snapshots);
        $row = $snapshots[0];

        // The facts every reader gets.
        $this->assertSame(1, $row['attempt']);
        $this->assertSame('0.3.8', $row['agent_version']);
        $this->assertSame(hash('sha256', self::RECEIPT_NOTE_XML), $row['xml_sha256']);
        $this->assertSame(strlen(self::RECEIPT_NOTE_XML), $row['xml_bytes']);
        $this->assertTrue($row['payload_matches']);
        $this->assertNotNull($row['created_at']);
        $this->assertFalse($row['tally']['success']);
        $this->assertSame(0, $row['tally']['created']);
        $this->assertSame(1, $row['tally']['errors']);

        // The XML: withheld whole, and said so — never a bare null.
        $this->assertNull($row['xml']);
        $this->assertSame(TallySyncSnapshotResource::XML_WITHHELD_NOTE, $row['xml_withheld']);
        $this->assertStringContainsString('FC-06', $row['xml_withheld']);

        // Tally's words: withheld with a note, raw included — the same rule
        // as error_message.
        $this->assertNull($row['tally']['message']);
        $this->assertSame(TallySyncSnapshotResource::MESSAGE_WITHHELD_NOTE, $row['tally']['message_withheld']);
        $this->assertNull($row['tally']['raw']);
        $this->assertSame(
            ['success', 'created', 'errors', 'message', 'message_withheld', 'raw'],
            array_keys($row['tally']),
        );

        // And nowhere on the wire — not in the snapshot, not in the history
        // row, not in the timeline.
        $raw = $shown->getContent();
        $this->assertStringNotContainsString(self::VENDOR, $raw, 'the vendor leaked on show');
        $this->assertStringNotContainsString(self::VENDOR_GSTIN, $raw);
        $this->assertStringNotContainsString('<ENVELOPE', $raw, 'the XML leaked on show');
        $this->assertStringNotContainsString('85.0000', $raw, 'a rate leaked on show');
        $this->assertStringNotContainsString('LINEERROR', $raw, 'Tally\'s raw response leaked on show');
    }

    // ---- (b) finance reads all of it, exactly as stored ------------------------

    public function test_a_finance_reader_sees_the_receipt_notes_xml_and_tallys_words_whole(): void
    {
        $grn = $this->receiptNote();
        $this->uploaded($grn, self::RECEIPT_NOTE_XML, [
            'success' => false, 'created' => 0, 'errors' => 1,
            'message' => 'Ledger does not exist : '.self::VENDOR,
            'raw' => '<RESPONSE><LINEERROR>Ledger does not exist : '.self::VENDOR.'</LINEERROR></RESPONSE>',
        ]);

        foreach (['finance.view', 'finance.manage'] as $standing) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($this->staff(['tally-sync.view', $standing]));
            $row = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json('data.snapshots.0');

            $this->assertSame(self::RECEIPT_NOTE_XML, $row['xml'], "{$standing} reads the XML byte for byte");
            $this->assertNull($row['xml_withheld']);
            $this->assertSame('Ledger does not exist : '.self::VENDOR, $row['tally']['message']);
            $this->assertArrayNotHasKey('message_withheld', $row['tally']);
            $this->assertSame('<RESPONSE><LINEERROR>Ledger does not exist : '.self::VENDOR.'</LINEERROR></RESPONSE>', $row['tally']['raw']);
            $this->assertSame(['success', 'created', 'errors', 'message', 'raw'], array_keys($row['tally']));
        }
    }

    // ---- (c) the agent reads back exactly what it sent, on every type -----------

    public function test_the_agent_reads_back_what_it_sent_for_every_voucher_type(): void
    {
        $cases = [
            [$this->receiptNote(), self::RECEIPT_NOTE_XML, 'Ledger does not exist : '.self::VENDOR],
            [$this->sales(), self::SALES_XML, 'Ledger does not exist : '.self::CUSTOMER],
            [$this->deliveryNote(), self::DELIVERY_NOTE_XML, null],
            [$this->journal(), self::JOURNAL_XML, 'Ledger does not exist : 4000 - Sales'],
            [$this->stockJournalShift(), self::STOCK_JOURNAL_XML, null],
            [$this->stockJournalBatch(), self::STOCK_JOURNAL_XML, 'Stock Item does not exist : Relpet'],
        ];

        foreach ($cases as [$entry, $xml, $message]) {
            $data = $this->uploaded($entry, $xml, [
                'success' => $message === null, 'created' => $message === null ? 1 : 0, 'errors' => $message === null ? 0 : 1,
                'message' => $message, 'raw' => null,
            ])->json('data');

            $this->assertSame($xml, $data['xml'], "the agent reads back the {$entry->tally_voucher_type} XML it sent");
            $this->assertNull($data['xml_withheld']);
            $this->assertSame($message, $data['tally']['message']);
            $this->assertArrayNotHasKey('message_withheld', $data['tally']);
        }
    }

    // ---- (d) no permission: nothing ------------------------------------------------

    public function test_a_reader_without_tally_sync_view_gets_nothing(): void
    {
        $grn = $this->receiptNote();
        $this->uploaded($grn, self::RECEIPT_NOTE_XML);

        // A logged-in person holding none of the tally-sync permissions.
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertForbidden();
        $this->getJson('/api/v1/tally-sync/entries')->assertForbidden();

        // A person's token without the agent's abilities is not the agent
        // and may not report on its behalf either.
        $this->app['auth']->forgetGuards();
        $laptop = User::factory()->create(['is_active' => true])->createToken('laptop', [])->plainTextToken;
        $this->withToken($laptop)->postJson("/api/v1/tally-sync/entries/{$grn->id}/snapshot", $this->body($grn, self::RECEIPT_NOTE_XML))->assertForbidden();
        $this->withToken($laptop)->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertForbidden();
    }

    // ---- (e) per voucher family, for the tally-sync.view reader ---------------------

    public function test_a_sales_invoices_xml_is_withheld_but_tallys_words_about_the_customer_are_shown(): void
    {
        $invoice = $this->sales();
        $this->uploaded($invoice, self::SALES_XML, [
            'success' => false, 'created' => 0, 'errors' => 1,
            'message' => 'Ledger does not exist : '.self::CUSTOMER, 'raw' => '<RESPONSE>x</RESPONSE>',
        ]);

        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$invoice->id}")->assertOk();
        $row = $shown->json('data.snapshots.0');

        // Selling RATE / AMOUNT ride the same keys on the same resource as a
        // purchase rate — gated alike (TallySyncEntryResource LINE_RATE_KEYS).
        $this->assertNull($row['xml']);
        $this->assertSame(TallySyncSnapshotResource::XML_WITHHELD_NOTE, $row['xml_withheld']);
        $this->assertStringNotContainsString('4.2500', $shown->getContent());
        // A customer is not FC-06: Tally's words are shown.
        $this->assertSame('Ledger does not exist : '.self::CUSTOMER, $row['tally']['message']);
        $this->assertSame('<RESPONSE>x</RESPONSE>', $row['tally']['raw']);
        $this->assertArrayNotHasKey('message_withheld', $row['tally']);
    }

    public function test_a_delivery_notes_xml_is_withheld_and_its_customer_message_shown(): void
    {
        $delivery = $this->deliveryNote();
        $this->uploaded($delivery, self::DELIVERY_NOTE_XML, [
            'success' => false, 'created' => 0, 'errors' => 1,
            'message' => 'Ledger does not exist : '.self::CUSTOMER, 'raw' => null,
        ]);

        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $row = $this->getJson("/api/v1/tally-sync/entries/{$delivery->id}")->assertOk()->json('data.snapshots.0');

        // Quantities only, but a party rides in it — and no partial
        // redaction of XML text is attempted: fail closed.
        $this->assertNull($row['xml']);
        $this->assertSame(TallySyncSnapshotResource::XML_WITHHELD_NOTE, $row['xml_withheld']);
        $this->assertSame('Ledger does not exist : '.self::CUSTOMER, $row['tally']['message']);
    }

    public function test_a_journals_xml_is_withheld_and_its_ledger_message_shown(): void
    {
        $journal = $this->journal();
        $this->uploaded($journal, self::JOURNAL_XML, [
            'success' => false, 'created' => 0, 'errors' => 1,
            'message' => 'Ledger does not exist : 4000 - Sales', 'raw' => null,
        ]);

        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$journal->id}")->assertOk();
        $row = $shown->json('data.snapshots.0');

        // DEBIT / CREDIT amounts are money on the same resource.
        $this->assertNull($row['xml']);
        $this->assertSame(TallySyncSnapshotResource::XML_WITHHELD_NOTE, $row['xml_withheld']);
        $this->assertStringNotContainsString('100.0000', $shown->getContent());
        // No party at all: the message is shown.
        $this->assertSame('Ledger does not exist : 4000 - Sales', $row['tally']['message']);
        $this->assertArrayNotHasKey('message_withheld', $row['tally']);
    }

    public function test_a_stock_journals_xml_is_shown_to_a_tally_sync_only_reader_for_both_production_categories(): void
    {
        $shift = $this->stockJournalShift();
        $batch = $this->stockJournalBatch();
        $this->uploaded($shift, self::STOCK_JOURNAL_XML);
        $this->uploaded($batch, self::STOCK_JOURNAL_XML, [
            'success' => false, 'created' => 0, 'errors' => 1,
            'message' => 'Stock Item does not exist : Relpet', 'raw' => '<RESPONSE><LINEERROR>Stock Item does not exist : Relpet</LINEERROR></RESPONSE>',
        ]);

        Sanctum::actingAs($this->staff(['tally-sync.view']));

        // Per shift ('Stock Journal' on a Shift morph) — the live mode.
        $row = $this->getJson("/api/v1/tally-sync/entries/{$shift->id}")->assertOk()->json('data.snapshots.0');
        $this->assertSame(self::STOCK_JOURNAL_XML, $row['xml'], 'rate-free and party-free by construction: shown');
        $this->assertNull($row['xml_withheld']);
        $this->assertTrue($row['tally']['success']);
        $this->assertNull($row['tally']['message']);
        $this->assertArrayNotHasKey('message_withheld', $row['tally']);

        // Per batch ('Manufacturing Journal' label, Stock Journal on the wire).
        $this->assertSame(TallyTransactionCategory::ProductionStockJournalBatch->value, $this->getJson("/api/v1/tally-sync/entries/{$batch->id}")->json('data.category.key'));
        $row = $this->getJson("/api/v1/tally-sync/entries/{$batch->id}")->assertOk()->json('data.snapshots.0');
        $this->assertSame(self::STOCK_JOURNAL_XML, $row['xml']);
        $this->assertNull($row['xml_withheld']);
        $this->assertSame('Stock Item does not exist : Relpet', $row['tally']['message']);
        $this->assertSame('<RESPONSE><LINEERROR>Stock Item does not exist : Relpet</LINEERROR></RESPONSE>', $row['tally']['raw']);
    }

    public function test_an_entry_the_classifier_cannot_place_fails_closed(): void
    {
        // A 'Stock Journal' label on an Invoice morph is Unknown — never a
        // best guess — and Unknown withholds the XML.
        $odd = TallySyncEntry::create([
            'syncable_type' => (new Invoice)->getMorphClass(),
            'syncable_id' => 99,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => ['voucher_type' => 'Stock Journal', 'voucher_number' => 'ODD-1', 'voucher_date' => '2026-08-10'],
            'status' => TallySyncStatus::Pending,
            'attempts' => 1,
            'delivered_at' => now(),
        ]);
        $this->uploaded($odd, self::STOCK_JOURNAL_XML);

        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$odd->id}")->assertOk();
        $this->assertSame(TallyTransactionCategory::Unknown->value, $shown->json('data.category.key'));
        $row = $shown->json('data.snapshots.0');
        $this->assertNull($row['xml']);
        $this->assertSame(TallySyncSnapshotResource::XML_WITHHELD_NOTE, $row['xml_withheld']);
    }

    // ---- (f) the rule, as a table ------------------------------------------------------

    public function test_the_verdicts_table(): void
    {
        $open = TallyTransactionCategory::ProductionStockJournalShift;
        $batch = TallyTransactionCategory::ProductionStockJournalBatch;

        foreach (TallyTransactionCategory::cases() as $category) {
            // A reader who may read purchase details (finance, the agent):
            // everything, whatever the category.
            $this->assertSame([true, false], TallySyncSnapshotResource::verdicts($category, true), $category->value);

            // A reader who may not: the XML only for the two production
            // categories; Tally's text withheld exactly where error_message is.
            [$showsXml, $withholdsSupplier] = TallySyncSnapshotResource::verdicts($category, false);
            $this->assertSame(in_array($category, [$open, $batch], true), $showsXml, "xml for {$category->value}");
            $this->assertSame($category->partyIsSupplier(), $withholdsSupplier, "message for {$category->value}");
        }

        // Named, so a reader of this test needs no table lookup.
        $this->assertSame([false, true], TallySyncSnapshotResource::verdicts(TallyTransactionCategory::ReceiptNote, false));
        $this->assertSame([false, false], TallySyncSnapshotResource::verdicts(TallyTransactionCategory::SalesInvoice, false));
        $this->assertSame([false, false], TallySyncSnapshotResource::verdicts(TallyTransactionCategory::DeliveryNote, false));
        $this->assertSame([false, false], TallySyncSnapshotResource::verdicts(TallyTransactionCategory::Journal, false));
        $this->assertSame([true, false], TallySyncSnapshotResource::verdicts($open, false));
        $this->assertSame([true, false], TallySyncSnapshotResource::verdicts($batch, false));
        $this->assertSame([false, false], TallySyncSnapshotResource::verdicts(TallyTransactionCategory::Unknown, false));
    }

    // ---- (g) shape and placement ---------------------------------------------------------

    public function test_snapshots_ride_the_show_endpoint_only_newest_first_and_the_list_carries_no_xml(): void
    {
        $shift = $this->stockJournalShift();
        Carbon::setTestNow('2026-08-17 10:00:00');
        $first = $this->uploaded($shift, self::STOCK_JOURNAL_XML, ['success' => false, 'created' => 0, 'errors' => 1, 'message' => 'Godown does not exist : FG Store', 'raw' => null], attempt: 1)->json('data.id');
        Carbon::setTestNow('2026-08-17 10:05:00');
        $second = $this->uploaded($shift, self::STOCK_JOURNAL_XML.'<!-- v2 -->', null, attempt: 2)->json('data.id');

        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$shift->id}")->assertOk()->json('data');
        $this->assertSame([$second, $first], array_column($shown['snapshots'], 'id'), 'newest first');
        $this->assertSame([2, 1], array_column($shown['snapshots'], 'attempt'));
        // A snapshot with no answer reads tally: null — the inconclusive-
        // timeout record.
        $this->assertNull($shown['snapshots'][0]['tally']);
        $this->assertSame(
            ['id', 'attempt', 'created_at', 'agent_version', 'xml_sha256', 'xml_bytes', 'payload_matches', 'tally', 'xml', 'xml_withheld'],
            array_keys($shown['snapshots'][0]),
        );

        // The list stays light: no snapshots key, no XML anywhere on it.
        $list = $this->getJson('/api/v1/tally-sync/entries')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $shift->id);
        $this->assertArrayNotHasKey('snapshots', $row);
        $this->assertStringNotContainsString('<ENVELOPE', $list->getContent());

        // Nor do the action responses (retry re-queues, and returns the
        // resource without snapshots).
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->staff(['tally-sync.view', 'tally-sync.manage']));
        $this->assertArrayNotHasKey('snapshots', $this->postJson("/api/v1/tally-sync/entries/{$shift->id}/retry")->assertOk()->json('data'));
    }

    public function test_no_body_is_not_a_withholding_and_an_answer_without_text_needs_no_note(): void
    {
        $grn = $this->receiptNote();
        // The agent's XML was over the cap: sha and size only, and Tally
        // accepted it (no message).
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$grn->id}/snapshot", [
            'xml' => null,
            'xml_sha256' => hash('sha256', self::RECEIPT_NOTE_XML),
            'xml_bytes' => 3_000_000,
            'attempt' => 1,
            'tally' => ['success' => true, 'created' => 1, 'errors' => 0, 'message' => null, 'raw' => null],
            'agent_version' => '0.3.8',
        ])->assertCreated();

        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $row = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json('data.snapshots.0');

        $this->assertNull($row['xml']);
        $this->assertNull($row['xml_withheld'], 'there was no body to withhold — say so by saying nothing');
        $this->assertSame(3_000_000, $row['xml_bytes']);
        $this->assertSame(['success' => true, 'created' => 1, 'errors' => 0, 'message' => null, 'raw' => null], $row['tally']);
    }

    // ---- helpers ------------------------------------------------------------------------------

    /**
     * The agent uploads a snapshot for $entry over its real token: the XML,
     * its sha, Tally's answer — and the response (201) is returned for the
     * agent's own read-back.
     *
     * @param  array<string, mixed>|null  $tally
     */
    private function uploaded(TallySyncEntry $entry, string $xml, ?array $tally = ['success' => true, 'created' => 1, 'errors' => 0, 'message' => null, 'raw' => null], int $attempt = 1)
    {
        return $this->asAgent()
            ->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, $xml, $tally, $attempt))
            ->assertCreated();
    }

    /**
     * @param  array<string, mixed>|null  $tally
     * @return array<string, mixed>
     */
    private function body(TallySyncEntry $entry, string $xml, ?array $tally = null, int $attempt = 1): array
    {
        return [
            'xml' => $xml,
            'xml_sha256' => hash('sha256', $xml),
            'attempt' => $attempt,
            'tally' => $tally,
            'agent_version' => '0.3.8',
            'payload_hash' => PayloadHash::of($entry->fresh()->payload),
        ];
    }

    /** An entry as it stands once the agent has collected it — every type here has been delivered. */
    private function entry(string $morphClass, int $id, string $voucherType, array $payload): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => $morphClass,
            'syncable_id' => $id,
            'tally_voucher_type' => $voucherType,
            'payload' => ['voucher_type' => $voucherType] + $payload,
            'status' => TallySyncStatus::Pending,
            'attempts' => 1,
            'delivered_at' => now(),
        ]);
    }

    private function receiptNote(): TallySyncEntry
    {
        return $this->entry((new GoodsReceiptNote)->getMorphClass(), 7, 'Receipt Note', [
            'voucher_number' => 'GRN-7', 'voucher_date' => '2026-08-04',
            'party_ledger' => self::VENDOR, 'party_gstin' => self::VENDOR_GSTIN, 'godown' => 'RM Store',
            'lines' => [['item' => 'PET Resin', 'quantity' => '100.0000', 'rate' => '85.0000', 'amount' => '8500.0000']],
            'total_amount' => '8500.0000',
        ]);
    }

    private function sales(): TallySyncEntry
    {
        return $this->entry((new Invoice)->getMorphClass(), 12, 'Sales', [
            'voucher_number' => 'INV-12', 'voucher_date' => '2026-08-05',
            'party_ledger' => self::CUSTOMER, 'party_gstin' => '33AAACS1234A1Z9',
            'lines' => [['item' => '500ml PET Bottle', 'quantity' => '2000.0000', 'rate' => '4.2500', 'amount' => '8500.0000']],
            'total_amount' => '8500.0000',
        ]);
    }

    private function deliveryNote(): TallySyncEntry
    {
        return $this->entry((new Delivery)->getMorphClass(), 3, 'Delivery Note', [
            'voucher_number' => 'DN-3', 'voucher_date' => '2026-08-03',
            'party_ledger' => self::CUSTOMER, 'party_gstin' => '33AAACS1234A1Z9', 'godown' => 'FG Store',
            'lines' => [['item' => '500ml PET Bottle', 'quantity' => '2000.0000']],
        ]);
    }

    private function journal(): TallySyncEntry
    {
        return $this->entry((new JournalEntry)->getMorphClass(), 4, 'Journal', [
            'voucher_number' => 'JE-REF-9', 'voucher_date' => '2026-08-02',
            'lines' => [
                ['ledger' => '4000 - Sales', 'debit' => '100.0000', 'credit' => '0.0000', 'memo' => null],
                ['ledger' => '1200 - Debtors', 'debit' => '0.0000', 'credit' => '100.0000', 'memo' => null],
            ],
        ]);
    }

    /** The live mode: one Stock Journal per (date, shift), on a Shift morph. */
    private function stockJournalShift(): TallySyncEntry
    {
        return $this->entry((new Shift)->getMorphClass(), 1, 'Stock Journal', [
            'voucher_number' => 'SJ-20260810-S1', 'voucher_date' => '2026-08-10', 'shift' => 'Shift 1',
            'produced' => [['item' => '500ml PET Bottle', 'quantity' => '2000.0000', 'godown' => 'FG Store']],
            'consumed' => [['item' => 'Relpet', 'quantity' => '50.0000', 'godown' => 'RM Store']],
        ]);
    }

    /** Batch mode: one voucher per approved entry, labelled 'Manufacturing Journal', Stock Journal on the wire. */
    private function stockJournalBatch(): TallySyncEntry
    {
        return $this->entry((new ShiftProductionEntry)->getMorphClass(), 21, 'Manufacturing Journal', [
            'voucher_number' => 'SPE-21', 'voucher_date' => '2026-08-10', 'batch_number' => 'B-21',
            'produced' => [['item' => '500ml PET Bottle', 'quantity' => '2000.0000']],
            'consumed' => [['item' => 'Relpet', 'quantity' => '50.0000', 'godown' => 'RM Store']],
        ]);
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** Subsequent requests come from the factory PC, over its real bearer token (PerTypeLifecycleTestCase). */
    private function asAgent(): static
    {
        if ($this->agentToken === null) {
            $this->agentToken = app(AgentTokenService::class)->issueToken('factory-pc')['plainTextToken'];
        }

        $this->app['auth']->forgetGuards();

        return $this->withToken($this->agentToken);
    }
}
