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
use App\Modules\Procurement\Models\PurchaseOrder;
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
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use App\Modules\TallySync\Services\TransactionClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * TALLY-SYNC-CHAIN.md §3 "Classification, derived not stored": every entry
 * the real enqueue paths produce classifies to exactly one
 * TallyTransactionCategory from tally_voucher_type + syncable_type, the
 * catalogue names what lives in Tally without the ERP (source 'tally',
 * count owed by the query side, never 0), and the resource lifts the
 * voucher's own facts (business date, document number, party, items) out
 * of the payload without a query. Nothing here reads from Tally, and an
 * entry the table cannot place is Unknown — never a best guess.
 */
class TransactionClassifierTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    protected function setUp(): void
    {
        parent::setUp();

        // Classification is downstream of the approval gates and the release
        // gate; both are pinned in their own suites and neutralised here so
        // the shift-mode fixture (a long-past production date) enqueues
        // exactly as ShiftVoucherGranularityTest's does.
        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 0]);
    }

    public function test_a_sales_invoice_classifies_as_sales_invoice(): void
    {
        // SalesVoucherPayload refuses — and stages NOTHING — without the GST
        // registration, the ledger mappings, a single Tally-linked godown, and
        // an HSN / state / Tally ledger name on this invoice's own item and
        // customer. So those two are REAL ROWS here, unlike the stand-ins the
        // other fixtures below keep: the trait completes rows, not in-memory
        // models. Seeded HERE, immediately before the invoice is issued,
        // rather than in setUp(): the godown it adds would otherwise become
        // the sole Tally-linked warehouse for the delivery and production
        // vouchers too, which have no need of it. Re-read after the seeding —
        // it fills the rows through its own instances, so these would be stale.
        $item = Item::firstOrCreate(['sku' => 'BTL-500'], ['name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $customer = Customer::firstOrCreate(['code' => 'CUST-1'], ['name' => 'Sri Aurobindo Beverages']);
        $this->seedSalesTallyMasterData();

        $line = new InvoiceLine(['quantity' => '1000.0000', 'unit_price' => '4.5000']);
        $line->setRelation('item', $item->fresh());

        $invoice = $this->existing(new Invoice(['invoice_date' => '2026-08-01']), 5);
        $invoice->setRelation('lines', collect([$line]));
        $invoice->setRelation('customer', $customer->fresh());

        $entry = app(TallySyncService::class)->enqueueSalesInvoice($invoice);

        $this->assertCategory(TallyTransactionCategory::SalesInvoice, $entry, [
            'label' => 'Sales — Invoice',
            'wire_voucher_type' => 'Sales',
            'source' => 'erp',
            'erp_build' => 'built',
            'direction' => 'erp_to_tally',
            'source_module' => 'sales',
            'erp_label_differs_from_wire' => false,
        ]);

        $classifier = app(TransactionClassifier::class);
        $this->assertSame('2026-08-01', $classifier->businessDate($entry));
        $this->assertSame('INV-5', $classifier->documentNumber($entry));
        $this->assertSame('Sri Aurobindo Beverages', $classifier->party($entry));
        $this->assertSame(['first' => '500ml PET Bottle', 'count' => 1], $classifier->itemSummary($entry));
    }

    public function test_a_journal_entry_classifies_as_journal_with_no_party_and_no_items(): void
    {
        $line = new JournalEntryLine(['debit' => '100.0000', 'credit' => '0.0000']);
        $line->setRelation('glAccount', new GLAccount(['code' => '4000', 'name' => 'Sales']));

        $journal = $this->existing(new JournalEntry(['entry_date' => '2026-08-02', 'reference' => 'JE-REF-9']), 4);
        $journal->setRelation('lines', collect([$line]));

        $entry = app(TallySyncService::class)->enqueueJournalEntry($journal);

        $this->assertCategory(TallyTransactionCategory::Journal, $entry, [
            'label' => 'Finance — Journal',
            'wire_voucher_type' => 'Journal',
            'source' => 'erp',
            'erp_build' => 'built',
            'direction' => 'erp_to_tally',
            'source_module' => 'finance',
            'erp_label_differs_from_wire' => false,
        ]);

        // A journal's lines are ledgers, not stock items, and it has no
        // counter-party: both are null rather than borrowed from elsewhere.
        $classifier = app(TransactionClassifier::class);
        $this->assertSame('JE-REF-9', $classifier->documentNumber($entry));
        $this->assertNull($classifier->party($entry));
        $this->assertNull($classifier->itemSummary($entry));
    }

    public function test_a_goods_receipt_classifies_as_receipt_note(): void
    {
        $entry = $this->enqueueGoodsReceipt();

        $this->assertCategory(TallyTransactionCategory::ReceiptNote, $entry, [
            'label' => 'Procurement — Receipt Note',
            'wire_voucher_type' => 'Receipt Note',
            'source' => 'erp',
            'erp_build' => 'built',
            'direction' => 'erp_to_tally',
            'source_module' => 'procurement',
            'erp_label_differs_from_wire' => false,
        ]);
    }

    public function test_a_delivery_classifies_as_delivery_note(): void
    {
        $so = new SalesOrder;
        $so->setRelation('customer', new Customer(['name' => 'Sri Aurobindo Beverages']));

        $line = new DeliveryLine(['quantity' => '2000.0000']);
        $line->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $delivery = $this->existing(new Delivery(['delivered_date' => '2026-08-03']), 3);
        $delivery->setRelation('lines', collect([$line]));
        $delivery->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $delivery->setRelation('salesOrder', $so);

        event(new DeliveryDispatched($delivery));

        $entry = TallySyncEntry::query()->sole();
        $this->assertCategory(TallyTransactionCategory::DeliveryNote, $entry, [
            'label' => 'Sales — Delivery Note',
            'wire_voucher_type' => 'Delivery Note',
            'source' => 'erp',
            'erp_build' => 'built',
            'direction' => 'erp_to_tally',
            'source_module' => 'sales',
            'erp_label_differs_from_wire' => false,
        ]);
        $this->assertSame('Sri Aurobindo Beverages', app(TransactionClassifier::class)->party($entry));
    }

    public function test_batch_mode_production_is_a_per_batch_stock_journal_and_says_its_label_differs(): void
    {
        config(['tally-sync.voucher_granularity' => 'batch']);

        $consumption = new ShiftMaterialConsumption(['quantity_issued_kg' => '250.0000']);
        $consumption->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin', 'tally_stock_item_guid' => 'itm-resin']));
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

        $entry = TallySyncEntry::query()->sole();
        // The entry keeps the ERP's own label; the category says the wire is
        // a Stock Journal — the honesty flag is true here and nowhere else.
        $this->assertSame('Manufacturing Journal', $entry->tally_voucher_type);
        $this->assertCategory(TallyTransactionCategory::ProductionStockJournalBatch, $entry, [
            'label' => 'Production — Stock Journal (per batch, ERP label "Manufacturing Journal")',
            'wire_voucher_type' => 'Stock Journal',
            'source' => 'erp',
            'erp_build' => 'built',
            'direction' => 'erp_to_tally',
            'source_module' => 'production',
            'erp_label_differs_from_wire' => true,
        ]);

        $classifier = app(TransactionClassifier::class);
        $this->assertSame('SPE-9', $classifier->documentNumber($entry));
        $this->assertNull($classifier->party($entry));
        // Produced first (the finished good headlines), then consumed.
        $this->assertSame(['first' => '500ml PET Bottle', 'count' => 2], $classifier->itemSummary($entry));
    }

    public function test_shift_mode_production_is_a_per_shift_stock_journal(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        $entry = $this->approveShiftProduction();

        $this->assertSame('Stock Journal', $entry->tally_voucher_type);
        $this->assertCategory(TallyTransactionCategory::ProductionStockJournalShift, $entry, [
            'label' => 'Production — Stock Journal (per shift)',
            'wire_voucher_type' => 'Stock Journal',
            'source' => 'erp',
            'erp_build' => 'built',
            'direction' => 'erp_to_tally',
            'source_module' => 'production',
            'erp_label_differs_from_wire' => false,
        ]);
    }

    public function test_the_morph_decides_between_the_two_production_labels(): void
    {
        // History, not leniency: the first shift vouchers were relabelled
        // 'Manufacturing Journal' server-side to ride the older agent's
        // builder (PR #149), and some synced under that label. The Shift
        // morph is what always said they were shift vouchers.
        $relabelled = $this->rawEntry((new Shift)->getMorphClass(), 'Manufacturing Journal');
        $this->assertSame(
            TallyTransactionCategory::ProductionStockJournalShift,
            app(TransactionClassifier::class)->classify($relabelled),
        );

        // A production label on a morph that is neither Shift nor
        // ShiftProductionEntry is nobody's production voucher.
        $orphan = $this->rawEntry('nonexistent-model', 'Stock Journal');
        $this->assertSame(TallyTransactionCategory::Unknown, app(TransactionClassifier::class)->classify($orphan));
    }

    public function test_an_unexpected_voucher_type_or_a_mismatched_pair_is_unknown_never_guessed(): void
    {
        $classifier = app(TransactionClassifier::class);

        // A label no enqueue path writes — even on a real morph.
        $purchase = $this->rawEntry((new Invoice)->getMorphClass(), 'Purchase');
        $this->assertSame(TallyTransactionCategory::Unknown, $classifier->classify($purchase));

        // A known label on the wrong morph: 'Sales' is Invoice's label, and
        // a Sales-labelled receipt is not quietly read as either.
        $mismatch = $this->rawEntry((new GoodsReceiptNote)->getMorphClass(), 'Sales');
        $this->assertSame(TallyTransactionCategory::Unknown, $classifier->classify($mismatch));

        $this->assertSame([
            'key' => 'unknown',
            'label' => 'Unknown',
            'wire_voucher_type' => null,
            'source' => 'unknown',
            'erp_build' => 'none',
            'direction' => 'erp_to_tally',
            'source_module' => null,
            'erp_label_differs_from_wire' => false,
        ], TallyTransactionCategory::Unknown->describe());
    }

    public function test_the_catalogue_names_every_category_once_in_a_stable_order(): void
    {
        $catalogue = TallyTransactionCategory::catalogue();
        $keys = array_column($catalogue, 'key');

        // Every case, exactly once, in declaration order.
        $this->assertSame(array_map(fn (TallyTransactionCategory $c) => $c->value, TallyTransactionCategory::cases()), $keys);
        $this->assertSame(count(TallyTransactionCategory::cases()), count(array_unique($keys)));

        // erp → tally → absent → unknown, never interleaved.
        $sources = array_values(array_unique(array_column($catalogue, 'source')));
        $this->assertSame(['erp', 'tally', 'absent', 'unknown'], $sources);

        $rows = collect($catalogue)->keyBy('key');
        foreach ($rows as $row) {
            $this->assertSame(
                ['key', 'label', 'wire_voucher_type', 'source', 'erp_build', 'direction', 'source_module', 'erp_label_differs_from_wire'],
                array_keys($row),
            );
        }

        // What lives in Tally without the ERP: source 'tally', direction
        // 'none', nothing built, no ERP module, and the wire voucher type is
        // the name from the 12-Aug Statistics census (TALLY-EVIDENCE-2026-08-12
        // §A). Purchase Order is no longer among them (Phase 6, below).
        foreach ([
            'purchase' => 'Purchase',
            'payment' => 'Payment',
            'receipt' => 'Receipt',
            'contra' => 'Contra',
            'credit_note' => 'Credit Note',
            'debit_note' => 'Debit Note',
        ] as $key => $wire) {
            $this->assertSame('tally', $rows[$key]['source'], $key);
            $this->assertSame('none', $rows[$key]['direction'], $key);
            $this->assertSame($wire, $rows[$key]['wire_voucher_type'], $key);
            $this->assertFalse($rows[$key]['erp_label_differs_from_wire'], $key);
            $this->assertSame('none', $rows[$key]['erp_build'], $key);
            $this->assertNull($rows[$key]['source_module'], $key);
        }

        // The ERP-originated Purchase Order is BUILT and STAGED
        // (DEC-20260812-002, Phase 6; enqueuePurchaseOrder — live posting
        // owner-gated behind tally-sync.purchase_orders_enabled, Q35): like
        // Journal, it is both in the 12-Aug census (92, the accountant's)
        // and ERP-built, and reads source 'erp', direction 'erp_to_tally',
        // module procurement, wire type 'Purchase Order' — the label says
        // it is staged and owner-gated, so nobody reads the row as live.
        $this->assertSame('erp', $rows['purchase_order']['source']);
        $this->assertSame('built', $rows['purchase_order']['erp_build']);
        $this->assertSame('erp_to_tally', $rows['purchase_order']['direction']);
        $this->assertSame('procurement', $rows['purchase_order']['source_module']);
        $this->assertSame('Purchase Order', $rows['purchase_order']['wire_voucher_type']);
        $this->assertFalse($rows['purchase_order']['erp_label_differs_from_wire']);
        $this->assertStringContainsString('owner-gated', $rows['purchase_order']['label']);
        $this->assertStringContainsString('Q35', $rows['purchase_order']['label']);

        // No Sales Order voucher type exists in the books (DEC-20260809-003:
        // sales are invoiced in Tally): source 'absent' — NOT 'tally', which
        // would list it as living in Tally beside voucher types that do —
        // no wire type to name, nothing built, no module.
        $this->assertSame('absent', $rows['sales_order']['source']);
        $this->assertSame('none', $rows['sales_order']['direction']);
        $this->assertSame('none', $rows['sales_order']['erp_build']);
        $this->assertNull($rows['sales_order']['wire_voucher_type']);
        $this->assertNull($rows['sales_order']['source_module']);
        $this->assertStringContainsString('DEC-20260809-003', $rows['sales_order']['label']);

        // Only the seven ERP-built rows are built (Purchase Order joined in
        // Phase 6); nothing is 'planned' today — the value stays for the
        // next decided-but-unbuilt category.
        $this->assertSame(
            ['production_stock_journal_shift', 'production_stock_journal_batch', 'sales_invoice', 'delivery_note', 'receipt_note', 'journal', 'purchase_order'],
            collect($catalogue)->where('erp_build', 'built')->pluck('key')->all(),
        );
        $this->assertSame([], collect($catalogue)->where('erp_build', 'planned')->pluck('key')->all());

        // The honesty flag is raised on exactly one row.
        $this->assertSame(
            ['production_stock_journal_batch'],
            collect($catalogue)->where('erp_label_differs_from_wire', true)->pluck('key')->all(),
        );
        // And both production rows name the same wire voucher type.
        $this->assertSame('Stock Journal', $rows['production_stock_journal_shift']['wire_voucher_type']);
        $this->assertSame('Stock Journal', $rows['production_stock_journal_batch']['wire_voucher_type']);
    }

    /**
     * Every voucher type on the accountant's Statistics screen
     * (TALLY-EVIDENCE-2026-08-12 §A, 1-Apr-26 to 10-Aug-26) has a catalogue
     * case that names it by its exact wire voucher type — so the Control
     * Center can say where each of the accountant's transactions lives
     * without a gap. All of them live in Tally except Journal and, since
     * Phase 6, Purchase Order — both of which the ERP ALSO builds and counts
     * only its own postings of (the 418 and the 92 are the accountant's;
     * see those cases). The figures are the census's, quoted here as the
     * evidence for the row's existence and never as a count this ERP took.
     */
    public function test_every_voucher_type_in_the_12_aug_census_maps_to_a_catalogue_case(): void
    {
        $census = [
            'Purchase' => 351,
            'Purchase Order' => 92,
            'Payment' => 925,
            'Receipt' => 553,
            'Journal' => 418,
            'Contra' => 60,
            'Credit Note' => 17,
            'Debit Note' => 5,
        ];

        $byWire = collect(TallyTransactionCategory::catalogue())
            ->filter(fn (array $row) => $row['wire_voucher_type'] !== null)
            ->groupBy('wire_voucher_type');

        foreach ($census as $wire => $count) {
            $this->assertGreaterThan(0, $count, "{$wire}: the census figure is evidence the type exists");
            $this->assertTrue($byWire->has($wire), "{$wire} ({$count} in the books) has no catalogue case");

            $sources = $byWire[$wire]->pluck('source')->unique()->values()->all();
            $this->assertSame(
                in_array($wire, ['Journal', 'Purchase Order'], true) ? ['erp'] : ['tally'],
                $sources,
                "{$wire}: source",
            );
        }
    }

    public function test_the_resource_exposes_the_derived_fields_without_touching_the_raw_keys(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);
        $this->actAsStaff();

        $grn = $this->enqueueGoodsReceipt();
        $shiftVoucher = $this->approveShiftProduction();

        $rows = collect($this->getJson('/api/v1/tally-sync/entries')
            ->assertOk()
            ->json('data'))->keyBy('id');

        // The GRN: party is the vendor — FC-06's "who supplied it", so null
        // for this tally-sync-only reader (SupplierIdentityVisibilityTest
        // pins the finance reader seeing it) — items are the received lines.
        $grnRow = $rows[$grn->id];
        $this->assertSame('receipt_note', $grnRow['category']['key']);
        $this->assertSame('Receipt Note', $grnRow['category']['wire_voucher_type']);
        $this->assertSame('erp', $grnRow['category']['source']);
        $this->assertSame('2026-08-04', $grnRow['business_date']);
        $this->assertSame('GRN-7', $grnRow['document_number']);
        $this->assertNull($grnRow['party'], 'the vendor is withheld from a reader without finance (FC-06)');
        $this->assertSame(['first' => 'PET Resin', 'count' => 1], $grnRow['item_summary']);

        // The shift voucher: no party; the item count is DISTINCT names across
        // produced + consumed (bottle, resin, masterbatch — resin issued twice
        // is still one item), headlined by the finished good.
        $shiftRow = $rows[$shiftVoucher->id];
        $this->assertSame('production_stock_journal_shift', $shiftRow['category']['key']);
        $this->assertSame('Stock Journal', $shiftRow['category']['wire_voucher_type']);
        $this->assertFalse($shiftRow['category']['erp_label_differs_from_wire']);
        $this->assertSame('2026-07-23', $shiftRow['business_date']);
        $this->assertSame($shiftVoucher->payload['voucher_number'], $shiftRow['document_number']);
        $this->assertNull($shiftRow['party']);
        $this->assertSame(['first' => '500ml PET Bottle', 'count' => 3], $shiftRow['item_summary']);

        // The keys the frontend and the sibling suites already read are
        // still there, byte-for-byte: additive only.
        foreach (['id', 'syncable_type', 'syncable_id', 'tally_voucher_type', 'payload', 'status', 'attempts',
            'error_message', 'synced_at', 'delivered_at', 'released_at', 'hold', 'created_at', 'resolution_log', 'fix'] as $key) {
            $this->assertArrayHasKey($key, $shiftRow);
        }
        $this->assertSame('Shift', $shiftRow['syncable_type']);
        $this->assertSame('Stock Journal', $shiftRow['tally_voucher_type']);
    }

    /** @param  array<string, mixed>  $expected  the row minus its key */
    private function assertCategory(TallyTransactionCategory $category, TallySyncEntry $entry, array $expected): void
    {
        $this->assertSame($category, app(TransactionClassifier::class)->classify($entry));
        $this->assertSame(['key' => $category->value] + $expected, $category->describe());
    }

    /** The GRN of OutboundVoucherTest, through the real event → listener → enqueue chain. */
    private function enqueueGoodsReceipt(): TallySyncEntry
    {
        $po = new PurchaseOrder;
        $po->setRelation('vendor', new Vendor(['name' => 'Reliance Industries', 'gstin' => '27AAACR1234A1Z5', 'tally_ledger_name' => 'Reliance Industries']));

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '85.0000']);
        $line->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin', 'tally_stock_item_guid' => 'itm-resin']));
        $line->setRelation('scheduleAllocations', collect());

        $grn = $this->existing(new GoodsReceiptNote(['received_date' => '2026-08-04']), 7);
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', new Warehouse(['name' => 'RM Store', 'tally_guid' => 'gd-rm']));
        $grn->setRelation('purchaseOrder', $po);

        event(new GoodsReceiptNoteReceived($grn));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Receipt Note')->sole();
    }

    /**
     * One shift's production approved through the real four-eyes path
     * (ShiftVoucherGranularityTest's fixture): a bottle produced, resin
     * issued twice and masterbatch once, so the voucher carries three
     * distinct items across four lines.
     */
    private function approveShiftProduction(): TallySyncEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG']);
        $masterbatch = Item::create(['sku' => 'MB-AMB', 'name' => 'Masterbatch Amber', 'uom' => 'KG']);
        $fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $fgStore->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'batch_number' => 'B-1',
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        foreach ([[$resin, '250.0000'], [$masterbatch, '2.0000'], [$resin, '10.0000']] as [$item, $kg]) {
            $entry->materialConsumptions()->create([
                'item_id' => $item->id,
                'warehouse_id' => $rmStore->id,
                'quantity_issued_kg' => $kg,
            ]);
        }

        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, User::factory()->create()->id);
        $service->accountantApprove($entry->fresh(), User::factory()->create()->id);

        return TallySyncEntry::query()->where('syncable_type', (new Shift)->getMorphClass())->sole();
    }

    private function rawEntry(string $morph, string $voucherType): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => $morph,
            'syncable_id' => 999,
            'tally_voucher_type' => $voucherType,
            'payload' => ['voucher_type' => $voucherType],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);
    }

    private function actAsStaff(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['tally-sync.view', 'tally-sync.manage'] as $permission) {
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
