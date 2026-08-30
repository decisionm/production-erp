<?php

namespace Tests\Feature\TallySync\PerType;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Models\TallySyncEntry;

/**
 * Receipt Note: raw material received against a PO (POST
 * /procurement/goods-receipts) → GoodsReceiptNoteReceived → Tally 'Receipt
 * Note'. Two things are this type's own beyond the shared lifecycle:
 *
 *   - DUPLICATE-REFUSED is the receipt_key replay (GoodsReceiptService::
 *     create): the same key with the same payload returns the SAME receipt
 *     and emits no event, so a retried POST from a flaky connection can
 *     never book the lorry twice — in stock or in Tally;
 *   - the payload carries the PO's tally_order_no and the arrival's
 *     tracking_number as recorded facts (audit §4.7: on the payload, not
 *     yet emitted by the agent's builder), and its rate/amount/total are
 *     the purchase rate — FC-06, finance-only on the wire, whole for the
 *     agent that has to build the voucher.
 */
class ReceiptNoteLifecycleTest extends PerTypeLifecycleTestCase
{
    private const RECEIPT_KEY = 'receipt-20260810-001';

    private PurchaseOrder $order;

    private PurchaseOrderLine $line;

    private Warehouse $rmStore;

    protected function setUp(): void
    {
        parent::setUp();

        $resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'itm-resin']);
        $this->rmStore = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'tally_guid' => 'gd-rm']);
        $vendor = Vendor::create(['code' => 'SUP-1', 'name' => 'Reliance Industries', 'gstin' => '27AAACR1234A1Z5', 'tally_ledger_name' => 'Reliance Industries']);
        $this->order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'status' => PurchaseOrderStatus::Sent,
            'order_date' => '2026-08-01',
            'tally_order_no' => 'PO/TALLY/77',
        ]);
        $this->line = $this->order->lines()->create([
            'item_id' => $resin->id, 'quantity' => '12000', 'unit_price' => '85', 'quantity_received' => '0',
        ]);
    }

    /** @return array<string, mixed> */
    private function receiptPayload(): array
    {
        return [
            'receipt_key' => self::RECEIPT_KEY,
            'purchase_order_id' => $this->order->id,
            'warehouse_id' => $this->rmStore->id,
            'received_date' => '2026-08-10',
            'receipt_note_reference' => 'RN-TEST-1',
            'tracking_number' => 'LR-4471',
            'lines' => [['purchase_order_line_id' => $this->line->id, 'quantity' => '12000', 'unit_cost' => '85']],
        ];
    }

    private function postReceipt()
    {
        return $this->asUser($this->staff('Store Keeper', ['procurement.view', 'procurement.manage']))
            ->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload());
    }

    protected function enqueueViaDomain(): TallySyncEntry
    {
        $this->postReceipt()->assertSuccessful()->assertJsonPath('data.receipt_key', self::RECEIPT_KEY);

        return TallySyncEntry::query()->sole();
    }

    protected function attemptDuplicateEnqueue(TallySyncEntry $entry): void
    {
        // The exact replay: same key, same payload — the receipt comes back,
        // nothing is booked again.
        $this->postReceipt()->assertSuccessful()->assertJsonPath('data.receipt_key', self::RECEIPT_KEY);

        $this->assertSame(1, GoodsReceiptNote::query()->count(), 'A replayed receipt key returns the same receipt');
        $this->assertSame('12000.0000', (string) $this->line->fresh()->quantity_received, 'A replay receives nothing twice');
    }

    protected function expectedCategoryKey(): string
    {
        return 'receipt_note';
    }

    protected function expectedVoucherType(): string
    {
        return 'Receipt Note';
    }

    protected function expectedDocumentNumber(TallySyncEntry $entry): string
    {
        // The Receipt Note reference recorded at physical arrival — the
        // number the accountant will match against the Tally PO.
        return 'RN-TEST-1';
    }

    protected function tallyRejection(): string
    {
        return "Stock Item 'PET Resin' does not exist!";
    }

    protected function expectedFixPath(): ?string
    {
        return '/production/standards?view=incomplete&missing_tally=1';
    }

    public function test_the_payload_carries_the_order_identities_and_the_rate_stays_finance_only(): void
    {
        $entry = $this->enqueueViaDomain();

        // Recorded facts on the payload (audit §4.7): the Tally PO number and
        // the arrival's tracking number ride the voucher whether or not the
        // agent's builder emits them yet.
        $this->assertSame('PO/TALLY/77', $entry->payload['tally_order_no']);
        $this->assertSame('LR-4471', $entry->payload['tracking_number']);
        $this->assertSame('Reliance Industries', $entry->payload['party_ledger']);
        $this->assertSame('RM Store', $entry->payload['godown']);
        $this->assertSame('PET Resin', $entry->payload['lines'][0]['item']);
        $this->assertSame('1020000.0000', $entry->payload['total_amount']);

        // FC-06 on the wire: a viewer without finance.* sees the line and its
        // quantity, never the rate, the amount or the total — keys OMITTED,
        // not nulled.
        $viewerRow = $this->listedRow($entry->id);
        $this->assertSame('12000.0000', $viewerRow['payload']['lines'][0]['quantity']);
        $this->assertArrayNotHasKey('rate', $viewerRow['payload']['lines'][0]);
        $this->assertArrayNotHasKey('amount', $viewerRow['payload']['lines'][0]);
        $this->assertArrayNotHasKey('total_amount', $viewerRow['payload']);
        $this->assertSame('PO/TALLY/77', $viewerRow['payload']['tally_order_no'], 'The order identity is not a price');

        // The agent must receive the whole payload — receiptNote.ts reads
        // line.rate and total_amount to build the voucher.
        $agentRow = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $entry->id);
        $this->assertSame('85.0000', $agentRow['payload']['lines'][0]['rate']);
        $this->assertSame('1020000.0000', $agentRow['payload']['total_amount']);
    }
}
