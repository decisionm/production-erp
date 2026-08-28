<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * goods_receipt_notes.tally_staging — the receiving desk's answer to "what
 * did Tally make of this arrival?", recorded by the GoodsReceiptNoteReceived
 * listener exactly as the PO listener records purchase_orders.tally_staging.
 *
 * The 28-Aug live walk found GRN pages showing NOTHING about the Receipt
 * Note's fate (audit finding 5), and the rehearsal found the two failures
 * that make the record matter: an item name Tally did not carry, and a
 * voucher that reached an obsolete Tally company. Both are now refused at
 * enqueue with named reasons (findings 6 and 7), and every branch —
 * disabled, refused, enqueued — lands on the receipt where its page reads
 * it.
 *
 * The receipt is created through the REAL endpoint, so what is pinned is
 * the actual arrival path: stock posts, THEN staging concludes, and a
 * refusal never fails the arrival (the material has physically arrived —
 * paperwork must not undo that).
 *
 * All values synthetic (FC-06). The reason details are asserted to name the
 * item by id+name and the vendor by id only — never a rate, never a vendor
 * name, never a GSTIN — because the receipt (and so this record) is read
 * by store logins that must not learn supplier identity from a refusal.
 */
class ReceiptNoteStagingRecordTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resin = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'guid-item-a']);
        $this->store = Warehouse::create(['code' => 'WH-A', 'name' => 'Warehouse A', 'is_active' => true, 'tally_guid' => 'guid-wh-a']);
        $this->vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha', 'tally_ledger_name' => 'Vendor Alpha']);
    }

    public function test_with_the_flag_on_and_every_identity_mapped_the_receipt_records_enqueued_with_the_entry_id(): void
    {
        config(['tally-sync.receipt_notes_enabled' => true]);

        $grn = $this->receive();

        $staging = $grn->fresh()->tally_staging;
        $this->assertSame('enqueued', $staging['state']);
        $this->assertSame([], $staging['reasons']);
        $this->assertSame(TallySyncEntry::query()->where('tally_voucher_type', 'Receipt Note')->sole()->id, $staging['entry_id']);
    }

    public function test_with_the_flag_off_the_receipt_records_disabled_and_names_the_open_question(): void
    {
        config(['tally-sync.receipt_notes_enabled' => false]);

        $grn = $this->receive();

        $staging = $grn->fresh()->tally_staging;
        $this->assertSame('disabled', $staging['state']);
        $this->assertSame('receipt_notes_disabled', $staging['reasons'][0]['code']);
        $this->assertStringContainsString('Q63', $staging['reasons'][0]['detail']);
        $this->assertSame(0, TallySyncEntry::query()->count(), 'disabled stages nothing');
    }

    public function test_an_unmapped_item_and_vendor_refuse_with_named_reasons_and_the_arrival_itself_still_succeeds(): void
    {
        config(['tally-sync.receipt_notes_enabled' => true]);

        // Strip the identities: the item Tally never sent, the vendor whose
        // ledger Accounts has not typed. The arrival must still post stock.
        $this->resin->forceFill(['tally_stock_item_guid' => null])->save();
        $this->vendor->forceFill(['tally_ledger_name' => null])->save();

        $grn = $this->receive();

        $this->assertNotNull($grn->id, 'the arrival posted');
        $staging = $grn->fresh()->tally_staging;
        $this->assertSame('refused', $staging['state']);

        $codes = array_column($staging['reasons'], 'code');
        $this->assertContains('item_unmapped', $codes);
        $this->assertContains('party_unmapped', $codes);

        // FC-06: the vendor reason names the vendor by id, never by name.
        $party = collect($staging['reasons'])->firstWhere('code', 'party_unmapped');
        $this->assertStringContainsString("vendor #{$this->vendor->id}", $party['detail']);
        $this->assertStringNotContainsString('Vendor Alpha', $party['detail']);

        $this->assertSame(0, TallySyncEntry::query()->count(), 'a refusal stages nothing');
    }

    public function test_a_blank_allowed_company_refuses_while_the_flag_is_on(): void
    {
        config(['tally-sync.receipt_notes_enabled' => true, 'tally-sync.receipt_notes_allowed_company' => '  ']);

        $grn = $this->receive();

        $staging = $grn->fresh()->tally_staging;
        $this->assertSame('refused', $staging['state']);
        $this->assertContains('allowed_company_unconfigured', array_column($staging['reasons'], 'code'));
    }

    public function test_the_staged_payload_carries_the_allowed_company_and_the_vendor_ledger_name_not_the_erp_label(): void
    {
        config(['tally-sync.receipt_notes_enabled' => true]);
        // The ERP label and the Tally ledger deliberately differ: the payload
        // must carry the LEDGER name, because the ERP's own label matches
        // Tally only by luck.
        $this->vendor->forceFill(['name' => 'Vendor Alpha (ERP label)', 'tally_ledger_name' => 'Vendor Alpha Ledger'])->save();

        $this->receive();

        $payload = TallySyncEntry::query()->where('tally_voucher_type', 'Receipt Note')->sole()->payload;
        $this->assertSame('Vendor Alpha Ledger', $payload['party_ledger']);
        $this->assertSame('Testing Tally Company Alpha', $payload['allowed_company']);
    }

    public function test_the_receipt_resource_returns_the_staging_record(): void
    {
        config(['tally-sync.receipt_notes_enabled' => false]);

        $grn = $this->receive();

        $data = $this->getJson("/api/v1/procurement/goods-receipts/{$grn->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('disabled', $data['tally_staging']['state']);
    }

    /** One arrival through the real endpoint, as Store Keeper. */
    private function receive(): GoodsReceiptNote
    {
        $po = PurchaseOrder::create(['vendor_id' => $this->vendor->id, 'status' => PurchaseOrderStatus::Sent, 'order_date' => '2026-08-01']);
        $poLine = $po->lines()->create(['item_id' => $this->resin->id, 'quantity' => '1000', 'unit_price' => '1', 'quantity_received' => '0']);

        $user = User::factory()->create(['name' => 'Store Keeper', 'is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/procurement/goods-receipts', [
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-08-10',
            'lines' => [['purchase_order_line_id' => $poLine->id, 'quantity' => '1000', 'unit_cost' => '1']],
        ])->assertSuccessful()->json('data.id');

        return GoodsReceiptNote::query()->findOrFail($id);
    }
}
