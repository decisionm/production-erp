<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Core\Exports\CsvStreamer;
use App\Modules\Core\Exports\ExportKind;
use App\Modules\Core\Exports\ExportRegistry;
use App\Modules\Core\Models\ExportRun;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Exports\CecExport;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\TallyMirrorStatementService;
use App\Modules\TallySync\Exports\TallySyncEntriesExport;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * PHASE 8 · CHAIN C — SALES VISIBILITY AND DOWNLOADS.
 *
 *   a sales document traced to its Tally entry (Layer A), with the honesty
 *   statement present (Layer B stated) → one export per kind from the
 *   Download Center, FC-06 holding on every file → the CEC slot still
 *   visibly BLOCKED
 *
 * The assertions are on the TRANSACTION MODEL, not the screen: a link is
 * checked against the tally_sync_entries ROW it stands for (syncable_type /
 * syncable_id / id), not against a status word; a file is checked against
 * the kind's own columns() verdict for the reader, not against a
 * hand-written column list.
 *
 * WHAT THIS TEST DOES NOT DO. It touches no live database and no Tally: the
 * fixture is written into the test database, and every "voucher" below is a
 * STAGED queue row that the ERP's own listeners enqueued. Nothing here
 * posts, delivers, acks or reads anything from Tally, and no purchase-order
 * Tally flag is turned on.
 *
 * THE TWO READERS. FC-06 cannot be proved by one reader — "the rate is
 * absent" passes for free on a fixture that never had a rate. So the SAME
 * fixture is read twice:
 *
 *   reader N  every registered kind's own permissions, MINUS anything
 *             finance.* — a module reader without finance standing
 *   reader F  the same, PLUS finance.view — the control
 *
 * and the file's FC-06 shape is DERIVED per kind rather than tabulated:
 * columns that exist for F and not for N are the ABSENT shape (a purchase
 * rate — a blank would read as "this resin cost nothing"); identical
 * columns whose cells differ are the WITHHELD shape (the supplier's
 * identity and Tally's rejection text — the cell says "withheld (FC-06)").
 * The one invariant that binds across both, and the one the brief names:
 * a reader without finance standing never gets a BLANK where a value was
 * withheld.
 *
 * The permission sets are derived from the registry, so a kind added by a
 * later phase is carried into this chain instead of escaping it.
 */
class SalesVisibilityChainTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    /** The rates. Owner/Accounts only (FC-06) — they must appear in NO file reader N downloads. */
    private const ORDER_RATE = '96.5000';

    private const RECEIPT_RATE = '102.7500';

    /** The supplier. Named on the purchase order to any procurement reader; withheld on the voucher. */
    private const VENDOR = 'Relpet Resins';

    private const REJECTION = 'Ledger does not exist : Relpet Resins';

    /** The customer. A customer is not FC-06 — it is named to every sales reader. */
    private const CUSTOMER = 'Aqua Traders';

    /** One day for the whole fixture, so a report kind's required date has an answer. */
    private const DAY = '2026-08-08';

    private SalesOrder $order;

    private Delivery $delivery;

    private Invoice $invoice;

    private GoodsReceiptNote $receipt;

    private TallySyncEntry $deliveryNote;

    private TallySyncEntry $salesVoucher;

    private TallySyncEntry $receiptNote;

    protected function setUp(): void
    {
        parent::setUp();

        $vendor = Vendor::create(['code' => 'SUP-RL', 'name' => self::VENDOR, 'gstin' => '27AAACR1234A1Z5', 'tally_ledger_name' => self::VENDOR]);
        $customer = Customer::create(['code' => 'CUST-AQ', 'name' => self::CUSTOMER, 'gstin' => '33AAACS1234A1Z9']);
        $resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'itm-resin']);
        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'tally_guid' => 'gd-rm']);
        $fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        // ---- the purchase side: a rate on the order and a rate on the receipt,
        // and a Receipt Note voucher whose party IS the supplier (FC-06).
        $purchaseOrder = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'status' => PurchaseOrderStatus::Sent,
            'order_date' => self::DAY,
            'expected_date' => self::DAY,
        ]);
        $poLine = $purchaseOrder->lines()->create([
            'item_id' => $resin->id,
            'quantity' => '12000',
            'unit_price' => self::ORDER_RATE,
            'quantity_received' => 0,
        ]);

        $this->receipt = GoodsReceiptNote::create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $rm->id,
            'reference' => 'DC-RL-1',
            'received_date' => self::DAY.' 09:00:00',
        ]);
        $this->receipt->lines()->create([
            'purchase_order_line_id' => $poLine->id,
            'item_id' => $resin->id,
            'quantity' => '1000',
            'unit_cost' => self::RECEIPT_RATE,
        ]);

        // The ERP's own listener stages the voucher — no Tally is touched.
        event(new GoodsReceiptNoteReceived($this->receipt->fresh()));
        $this->receiptNote = TallySyncEntry::query()->where('tally_voucher_type', 'Receipt Note')->sole();
        // Tally's own words, as the agent would have reported them: they NAME
        // the supplier, which is why they are withheld with the party.
        $this->receiptNote->update([
            'status' => TallySyncStatus::Failed,
            'attempts' => 1,
            'error_message' => self::REJECTION,
        ]);

        // ---- the sales side: order → delivery → invoice, each with its voucher.
        $this->order = SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => self::DAY,
            'expected_date' => self::DAY,
        ]);
        $soLine = $this->order->lines()->create([
            'item_id' => $bottle->id,
            'quantity' => '2000',
            'unit_price' => '4.5000',
            'quantity_delivered' => 0,
        ]);

        // The Delivery Note voucher is FAIL-CLOSED (DEC-20260831-007): it stages
        // nothing unless the customer carries a tally_ledger_name and a godown
        // resolves, so the masters go in HERE — before the dispatch — rather
        // than lower down. The Sales voucher below needs the same set, and both
        // purchase-side vouchers are already staged by now. The RM store above
        // is this fixture's one Tally-linked warehouse, so the trait adds no
        // second godown to argue with.
        $this->seedSalesTallyMasterData();

        $this->delivery = Delivery::create([
            'sales_order_id' => $this->order->id,
            'warehouse_id' => $fg->id,
            'reference' => 'TRUCK-A',
            'delivered_date' => self::DAY.' 14:00:00',
        ]);
        $this->delivery->lines()->create([
            'sales_order_line_id' => $soLine->id,
            'item_id' => $bottle->id,
            'quantity' => '500',
        ]);
        event(new DeliveryDispatched($this->delivery->fresh()));
        $this->deliveryNote = TallySyncEntry::query()->where('tally_voucher_type', 'Delivery Note')->sole();

        $this->invoice = Invoice::create([
            'sales_order_id' => $this->order->id,
            'customer_id' => $customer->id,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => self::DAY,
        ]);
        $this->invoice->lines()->create([
            'sales_order_line_id' => $soLine->id,
            'item_id' => $bottle->id,
            'quantity' => '500',
            'unit_price' => '4.5000',
        ]);
        // The Sales voucher is this chain's fixture VEHICLE, not its subject:
        // SalesVoucherPayload stages nothing without the GST masters behind it,
        // and they are already seeded above (the Delivery Note needs them too).
        //
        // Issuing is what stages the Sales voucher (the model observer).
        $this->invoice->update(['status' => InvoiceStatus::Issued]);
        $this->salesVoucher = TallySyncEntry::query()->where('tally_voucher_type', 'Sales')->sole();
    }

    // ---- C1 · the documents exist as ERP-originated transactions --------------

    public function test_c1_the_sales_documents_are_erp_originated_rows_with_their_lines(): void
    {
        $this->assertTrue($this->order->exists);
        $this->assertSame(SalesOrderStatus::Confirmed, $this->order->fresh()->status);
        $this->assertSame(InvoiceStatus::Issued, $this->invoice->fresh()->status);
        $this->assertSame(1, $this->delivery->lines()->count());
        $this->assertSame(1, $this->invoice->lines()->count());
        $this->assertSame($this->order->id, $this->delivery->sales_order_id);
        $this->assertSame($this->order->id, $this->invoice->sales_order_id);

        // The hard line, asserted rather than claimed: the PO->Tally flag stays
        // off and nothing here staged a Purchase Order voucher. (Chain B owns
        // the deeper Q35 assertion; this is only this fixture's own proof.)
        $this->assertSame(0, TallySyncEntry::query()->where('tally_voucher_type', 'Purchase Order')->count());
    }

    // ---- C2 · Layer A · each document traced to its OWN queue row -------------

    public function test_c2_a_sales_document_is_traced_to_the_tally_entry_row_that_stands_for_it(): void
    {
        // The model first: the voucher rows point back at the documents by
        // morph class and key — this is the trace, the API only reports it.
        $this->assertSame($this->delivery->getMorphClass(), $this->deliveryNote->syncable_type);
        $this->assertSame($this->delivery->id, $this->deliveryNote->syncable_id);
        $this->assertSame($this->invoice->getMorphClass(), $this->salesVoucher->syncable_type);
        $this->assertSame($this->invoice->id, $this->salesVoucher->syncable_id);

        $this->actAs($this->readerPermissions());

        $delivery = $this->getJson("/api/v1/sales/deliveries/{$this->delivery->id}")->assertOk()->json('data');
        $this->assertSame($this->deliveryNote->id, $delivery['tally']['entry_id'], 'the delivery links the row that stands for it');
        $this->assertSame('Delivery Note', $delivery['tally']['voucher_type']);
        $this->assertSame("/tally-sync?entry={$this->deliveryNote->id}", $delivery['tally']['link']);

        $invoice = $this->getJson("/api/v1/sales/invoices/{$this->invoice->id}")->assertOk()->json('data');
        $this->assertSame($this->salesVoucher->id, $invoice['tally']['entry_id']);
        $this->assertSame('Sales', $invoice['tally']['voucher_type']);
        $this->assertSame($this->salesVoucher->id, $invoice['trace']['tally']['entry_id'], 'the trace names the same row');
        $this->assertSame($this->order->id, $invoice['trace']['sales_order']['id']);

        // The order's own trace reaches both documents, and each carries its row.
        $order = $this->getJson("/api/v1/sales/sales-orders/{$this->order->id}")->assertOk()->json('data');
        $this->assertSame([$this->deliveryNote->id], array_column(array_column($order['trace']['deliveries'], 'tally'), 'entry_id'));
        $this->assertSame([$this->salesVoucher->id], array_column(array_column($order['trace']['invoices'], 'tally'), 'entry_id'));
    }

    public function test_c2_the_link_carries_no_payload_no_rate_and_no_lot(): void
    {
        $this->actAs($this->readerPermissions());

        $order = $this->getJson("/api/v1/sales/sales-orders/{$this->order->id}")->assertOk();
        $delivery = $this->getJson("/api/v1/sales/deliveries/{$this->delivery->id}")->assertOk();
        $invoice = $this->getJson("/api/v1/sales/invoices/{$this->invoice->id}")->assertOk();

        foreach ([$order, $delivery, $invoice] as $response) {
            $link = $this->flatten($response->json('data'));
            foreach (['payload', 'lot_id', 'lot_code', 'unit_cost', 'material_lot'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $link, "a sales document never carries {$forbidden}");
            }
            $body = $response->getContent();
            $this->assertStringNotContainsString(self::ORDER_RATE, $body, 'no purchase rate rides a sales trace (FC-06)');
            $this->assertStringNotContainsString(self::RECEIPT_RATE, $body, 'no purchase rate rides a sales trace (FC-06)');
            // FC-06 has two halves. The second one — supplier identity — is the
            // half this repo has been caught missing before.
            $this->assertStringNotContainsString(self::VENDOR, $body, 'no supplier rides a sales trace (FC-06, second half)');
        }
    }

    // ---- C3 · Layer B · the honesty statement is present ----------------------

    public function test_c3_the_honesty_statement_is_served_and_says_sales_stays_tally_originated(): void
    {
        $this->actAs($this->readerPermissions());

        // A bare object, not a resource — it describes no row.
        $statement = $this->getJson('/api/v1/sales/tally-mirror')->assertOk()->json();

        // The endpoint IS the service — the page never writes its own words.
        $this->assertSame(app(TallyMirrorStatementService::class)->statement(), $statement);

        // The four facts the pages branch on.
        $this->assertFalse($statement['mirrored'], 'Tally-side sales are NOT mirrored here');
        $this->assertSame(TallyMirrorStatementService::DECISION, $statement['decision']);
        // TRUE since the Sales voucher was rebuilt against the factory's own 55
        // real exports (DEC-20260831-007). Still never LIVE-POSTED — that is the
        // note's job to say, not this flag's.
        $this->assertTrue($statement['erp_invoice_builder']['validated']);
        $this->assertFalse($statement['payments_recorded_here']);
        $this->assertNotSame('', trim($statement['headline']));
        $this->assertNotSame('', trim($statement['body']));
    }

    // ---- C4 · one export per kind, and every kind accounted for ---------------

    public function test_c4_every_registered_kind_either_streams_a_file_or_is_blocked_and_answers_409(): void
    {
        $this->actAs($this->readerPermissions());

        $catalogue = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->keyBy('key');
        $registered = array_map(fn (ExportKind $kind) => $kind->key(), app(ExportRegistry::class)->all());

        sort($registered);
        $offered = $catalogue->keys()->sort()->values()->all();
        $this->assertSame(
            $registered,
            $offered,
            'every registered kind is offered to this chain\'s reader — a new kind needs its permission added to readerPermissions(), not to be skipped',
        );

        $streamed = [];
        $blocked = [];

        foreach (app(ExportRegistry::class)->all() as $kind) {
            $key = $kind->key();
            $body = $this->bodyFor($kind);
            $card = $catalogue[$key];

            if ($card['status'] === ExportKind::STATUS_BLOCKED) {
                $response = $this->postJson("/api/v1/exports/{$key}", $body)->assertStatus(409);
                $this->assertSame(['message' => $card['blocked_reason'], 'kind' => $key], $response->json(), $key);
                $this->assertNotSame('', (string) $card['blocked_reason'], "{$key} states why it is blocked");
                $blocked[] = $key;

                continue;
            }

            $this->assertSame(ExportKind::STATUS_AVAILABLE, $card['status'], $key);
            $csv = $this->csv($this->postJson("/api/v1/exports/{$key}", $body)->assertOk(), $key);
            $this->assertSame(array_keys($kind->columns($this->user())), $csv['headers'], "{$key}: the file's header IS the kind's columns() for this reader");
            $streamed[] = $key;
        }

        $this->assertSame($registered, collect([...$streamed, ...$blocked])->sort()->values()->all(), 'no kind fell between the two states');
        $this->assertNotEmpty($streamed);
        $this->assertContains('cec', $blocked);

        // The audit half of the Center: every POST left a run, refusals too.
        $runs = ExportRun::query()->where('user_id', $this->user()->id)->pluck('kind')->sort()->values()->all();
        $this->assertSame($registered, $runs, 'every download attempt is recorded, blocked ones included');
        $this->assertNotNull(ExportRun::query()->where('kind', 'cec')->sole()->refusal_reason);
    }

    // ---- C5 · FC-06 holds on every file --------------------------------------

    public function test_c5_no_purchase_rate_reaches_any_file_a_reader_without_finance_standing_downloads(): void
    {
        $this->actAs($this->readerPermissions());
        $filesN = $this->runEveryAvailableKind();

        foreach ($filesN as $key => $csv) {
            $this->assertStringNotContainsString(self::ORDER_RATE, $csv['raw'], "the purchase rate is nowhere in {$key}");
            $this->assertStringNotContainsString(self::RECEIPT_RATE, $csv['raw'], "the receipt rate is nowhere in {$key}");
        }

        // The control: the same fixture, read by finance, DOES carry the rate —
        // without this leg the assertions above would pass on an empty fixture.
        $this->app['auth']->forgetGuards();
        $this->actAs([...$this->readerPermissions(), 'finance.view']);
        $filesF = $this->runEveryAvailableKind();

        $carried = collect($filesF)->filter(fn (array $csv) => str_contains($csv['raw'], self::ORDER_RATE) || str_contains($csv['raw'], self::RECEIPT_RATE));
        $this->assertNotEmpty(
            $carried,
            'the fixture must put a purchase rate on at least one file for finance, else the absence above proves nothing',
        );
        $this->assertSame(array_keys($filesN), array_keys($filesF), 'both readers ran the same kinds');
    }

    public function test_c5_where_the_file_withholds_it_says_so_and_is_never_blank(): void
    {
        $this->actAs($this->readerPermissions());
        $filesN = $this->runEveryAvailableKind();

        $this->app['auth']->forgetGuards();
        $this->actAs([...$this->readerPermissions(), 'finance.view']);
        $filesF = $this->runEveryAvailableKind();

        $absenceGated = [];
        $withheldCells = [];

        foreach ($filesN as $key => $csvN) {
            $csvF = $filesF[$key];

            // Shape 1 — the column is ABSENT for the reader without standing.
            $gone = array_values(array_diff($csvF['headers'], $csvN['headers']));
            if ($gone !== []) {
                $absenceGated[$key] = $gone;
                foreach ($gone as $column) {
                    $this->assertNotContains($column, $csvN['headers'], "{$key}: {$column} is absent, not blank");
                }
            }

            // Shape 2 — the column STAYS and the cell says what happened.
            $shared = array_values(array_intersect($csvN['headers'], $csvF['headers']));
            $this->assertCount(count($csvF['rows']), $csvN['rows'], "{$key}: both readers see the same rows");

            foreach ($csvN['rows'] as $index => $rowN) {
                $rowF = $csvF['rows'][$index];
                foreach ($shared as $column) {
                    if ($rowN[$column] === $rowF[$column]) {
                        continue;
                    }
                    // A cell that differs between the two readers is a cell the
                    // file withheld — and it must SAY so, never read as "no value".
                    // THE BINDING INVARIANT — the brief's own sentence. The exact
                    // wording of a withheld cell is a per-kind spelling and is
                    // pinned where it is owned (the tally_sync_entries test
                    // below), not policed Center-wide from here: a later kind
                    // may withhold honestly in its own words.
                    $this->assertNotSame('', $rowN[$column], "{$key}.{$column}: withheld, but blank — a blank reads as 'no value'");
                    $withheldCells[] = "{$key}.{$column}";
                }
            }
        }

        // Both shapes must actually have fired, or this test proved nothing.
        $this->assertNotEmpty($absenceGated, 'no column was finance-gated on any file — the fixture is not exercising FC-06');
        $this->assertNotEmpty($withheldCells, 'no cell was withheld on any file — the fixture is not exercising FC-06');
    }

    public function test_c5_the_supplier_and_tallys_words_are_withheld_on_the_voucher_and_named_to_finance(): void
    {
        $this->actAs($this->readerPermissions());
        $withoutFinance = $this->csv($this->postJson('/api/v1/exports/tally_sync_entries', [])->assertOk(), 'tally_sync_entries');
        $receiptRowN = collect($withoutFinance['rows'])->firstWhere('id', (string) $this->receiptNote->id);

        $this->assertNotNull($receiptRowN);
        $this->assertSame(TallySyncEntriesExport::WITHHELD_CELL, $receiptRowN['party']);
        $this->assertSame(TallySyncEntriesExport::WITHHELD_CELL, $receiptRowN['error_message']);
        $this->assertStringNotContainsString(self::VENDOR, $withoutFinance['raw'], 'the supplier is named nowhere in this file');

        // A customer is not FC-06: the Delivery Note names it to the same reader.
        $deliveryRowN = collect($withoutFinance['rows'])->firstWhere('id', (string) $this->deliveryNote->id);
        $this->assertSame(self::CUSTOMER, $deliveryRowN['party']);

        $this->app['auth']->forgetGuards();
        $this->actAs([...$this->readerPermissions(), 'finance.view']);
        $withFinance = $this->csv($this->postJson('/api/v1/exports/tally_sync_entries', [])->assertOk(), 'tally_sync_entries');
        $receiptRowF = collect($withFinance['rows'])->firstWhere('id', (string) $this->receiptNote->id);

        $this->assertSame(self::VENDOR, $receiptRowF['party'], 'finance reads the supplier');
        $this->assertSame(self::REJECTION, $receiptRowF['error_message'], 'finance reads Tally\'s words');
    }

    // ---- C6 · the CEC slot is still visibly BLOCKED ---------------------------

    public function test_c6_the_cec_slot_is_catalogued_blocked_with_its_reason_and_never_produces_a_file(): void
    {
        $this->actAs($this->readerPermissions());

        $card = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->firstWhere('key', 'cec');
        $this->assertNotNull($card, 'the slot is shown, not hidden — an owner gate is stated, not silently dropped');
        $this->assertSame(ExportKind::STATUS_BLOCKED, $card['status']);
        $this->assertSame(CecExport::BLOCKED_REASON, $card['blocked_reason']);

        // No layout was quietly invented while the owner's sample is missing.
        $this->assertSame([], app(ExportRegistry::class)->find('cec')->columns($this->user()));

        foreach ([[], ['date' => self::DAY], ['date' => self::DAY, 'shift_id' => null]] as $body) {
            $response = $this->postJson('/api/v1/exports/cec', $body)->assertStatus(409);
            $this->assertSame(['message' => CecExport::BLOCKED_REASON, 'kind' => 'cec'], $response->json());
        }

        $runs = ExportRun::query()->where('kind', 'cec')->get();
        $this->assertCount(3, $runs);
        foreach ($runs as $run) {
            $this->assertSame(CecExport::BLOCKED_REASON, $run->refusal_reason);
            $this->assertFalse((bool) $run->completed, 'a blocked slot never completes a file');
            $this->assertNull($run->sha256);
        }
    }

    // ---- helpers --------------------------------------------------------------

    /**
     * Run every AVAILABLE kind once for the current reader, keyed by kind.
     *
     * @return array<string, array{raw: string, headers: list<string>, rows: list<array<string, string>>}>
     */
    private function runEveryAvailableKind(): array
    {
        $files = [];

        foreach (app(ExportRegistry::class)->all() as $kind) {
            if ($kind->status() === ExportKind::STATUS_BLOCKED) {
                continue;
            }
            $key = $kind->key();
            $files[$key] = $this->csv($this->postJson("/api/v1/exports/{$key}", $this->bodyFor($kind))->assertOk(), $key);
        }

        return $files;
    }

    /**
     * The smallest body a kind's own rules accept. Derived from
     * filterRules(): a required filter that is a date gets the fixture day;
     * a required filter of any other kind is not guessed — the test says so
     * and fails, because inventing an answer is how a chain starts lying.
     *
     * @return array<string, string>
     */
    private function bodyFor(ExportKind $kind): array
    {
        $body = [];

        foreach ($kind->filterRules() as $name => $ruleSet) {
            $rules = is_string($ruleSet) ? explode('|', $ruleSet) : (array) $ruleSet;

            if (! in_array('required', $rules, true)) {
                continue;
            }

            $this->assertContains(
                'date',
                $rules,
                "{$kind->key()}: required filter '{$name}' is not a date — this chain has no value for it and will not invent one",
            );
            $body[$name] = self::DAY;
        }

        return $body;
    }

    /**
     * Every permission any registered kind asks for, MINUS finance.* — a
     * module reader across the whole Center, without finance standing.
     *
     * @return list<string>
     */
    private function readerPermissions(): array
    {
        $permissions = ['sales.view'];

        foreach (app(ExportRegistry::class)->all() as $kind) {
            foreach ($kind->permissionAny() as $permission) {
                if (! str_starts_with($permission, 'finance.')) {
                    $permissions[] = $permission;
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    /** Every key of a nested array, flattened, so a forbidden key cannot hide in a branch. */
    private function flatten(mixed $value, array $keys = []): array
    {
        if (! is_array($value)) {
            return $keys;
        }

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[$key] = true;
            }
            $keys = $this->flatten($item, $keys);
        }

        return $keys;
    }

    /**
     * The streamed file, parsed: BOM off, CRLF rows, cells by header.
     *
     * @return array{raw: string, headers: list<string>, rows: list<array<string, string>>}
     */
    private function csv(TestResponse $response, string $label): array
    {
        $raw = $response->streamedContent();
        $this->assertStringStartsWith(CsvStreamer::BOM, $raw, $label);
        $body = substr($raw, strlen(CsvStreamer::BOM));
        $this->assertStringEndsWith("\r\n", $body, $label);

        $lines = explode("\r\n", rtrim($body, "\r\n"));
        $headers = str_getcsv(array_shift($lines), ',', '"', '');
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = array_combine($headers, str_getcsv($line, ',', '"', ''));
        }

        return ['raw' => $raw, 'headers' => $headers, 'rows' => $rows];
    }

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function user(): User
    {
        return auth()->user();
    }
}
