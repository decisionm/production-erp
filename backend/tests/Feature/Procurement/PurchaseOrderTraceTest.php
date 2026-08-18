<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueBagScan;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Events\PurchaseOrderSent;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\DayBinLedgerService;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE PURCHASE CHAIN READ BACK (Phase 6, P6-02): GET purchase-orders/{po}
 * (lines, schedules, revisions, receipts summary, the Tally link) and
 * GET purchase-orders/{po}/trace — PO → GRNs (receipt_key, lines,
 * quantities) → material lots → bags → day-bin loads → the production
 * entries those loads fed and their consumption (TraceabilityService::
 * consumptionFor) → the stock movements with their purpose (Receipt in,
 * Consumption out) — every hop read through the Inventory / Production
 * SERVICES, never their models. Plus GET goods-receipts/{grn}.
 *
 * FC-06 on the trace: a procurement-only reader never meets a rate key
 * (unit_price / unit_cost / receipt_rate_per_kg — the same walk
 * ProcurementRateVisibilityTest does) and instead reads a `rate_withheld`
 * note where the number would be; a finance.view reader sees the number and
 * no note. FC-01: a bag belongs to no machine and no batch — a load names
 * the segment it was RECORDED under and the machine (or the common input,
 * null), and the consumption block is the segment's figure, not the bag's.
 *
 * Synthetic values only: "Vendor Alpha", ITEM_A, rate 1.00. No Tally is
 * read or written; the one TallySyncEntry is a fixture row.
 */
class PurchaseOrderTraceTest extends TestCase
{
    use RefreshDatabase;

    private const RATE_KEYS = ['unit_price', 'unit_cost', 'receipt_rate_per_kg', 'current_rate_per_kg', 'amount', 'rate'];

    private Vendor $vendor;

    private Item $itemA;

    private Item $bottle;

    private Warehouse $store;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);
        Event::fake([GoodsReceiptNoteReceived::class, PurchaseOrderSent::class]);

        $this->vendor = Vendor::create(['code' => 'V-ALPHA', 'name' => 'Vendor Alpha']);
        $this->itemA = Item::create(['sku' => 'ITEM_A', 'name' => 'Item A', 'uom' => 'Kgs']);
        $this->bottle = Item::create(['sku' => 'FG_A', 'name' => 'Finished A', 'uom' => 'Nos']);
        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
    }

    /** @param list<string> $permissions */
    private function actAs(array $permissions = ['procurement.view', 'procurement.manage']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * The chain: a Draft with a schedule, amended once, sent, received in
     * ONE arrival of 50 kg as 2 bags of 25 kg.
     *
     * @return array{0: PurchaseOrder, 1: GoodsReceiptNote}
     */
    private function chain(): array
    {
        $id = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => '80', 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/procurement/purchase-orders/{$id}/amend", [
            'reason' => 'split into two windows',
            'lines' => [[
                'item_id' => $this->itemA->id, 'quantity' => '100', 'unit_price' => '1.00',
                'schedules' => [
                    ['due_date' => '2026-08-18', 'quantity' => '50'],
                    ['due_date' => '2026-08-25', 'quantity' => '50'],
                ],
            ]],
        ])->assertOk();
        $this->postJson("/api/v1/procurement/purchase-orders/{$id}/send")->assertOk();

        $order = PurchaseOrder::findOrFail($id);
        $line = $order->lines()->firstOrFail();

        $grnId = $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'rk-trace-1',
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->store->id,
            'reference' => 'DC-0001',
            'received_date' => '2026-08-17',
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'quantity' => '50',
                'lots' => [[
                    'supplier_lot_no' => 'LOT-A1',
                    'bag_count' => 2,
                    'bag_weight_kg' => '25',
                    'barcodes' => ['BAG-A1-1', 'BAG-A1-2'],
                ]],
            ]],
        ])->assertCreated()->json('data.id');

        return [$order->fresh(), GoodsReceiptNote::findOrFail($grnId)];
    }

    private function inProgressEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-08-17',
            'batch_number' => '20260817-MC01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    /** The queue row TallySyncLinkService links a document to — a fixture; no Tally is touched. */
    private function tallyEntry(Model $document, string $voucherType, TallySyncStatus $status): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => $document->getMorphClass(),
            'syncable_id' => $document->getKey(),
            'tally_voucher_type' => $voucherType,
            'payload' => ['voucher_number' => 'X-'.$document->getKey()],
            'status' => $status,
            'attempts' => 0,
        ]);
    }

    /**
     * Every key path in the payload, at any depth, whose name is a rate key —
     * ProcurementRateVisibilityTest's walk, widened to the lot's rate keys.
     *
     * @return list<string>
     */
    private function rateKeyPaths(mixed $node, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }
        $found = [];
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";
            if (in_array($key, self::RATE_KEYS, true)) {
                $found[] = $here;
            }
            $found = [...$found, ...$this->rateKeyPaths($value, $here)];
        }

        return $found;
    }

    /** @return list<string> */
    private function keyPaths(mixed $node, string $wanted, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }
        $found = [];
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";
            if ($key === $wanted) {
                $found[] = $here;
            }
            $found = [...$found, ...$this->keyPaths($value, $wanted, $here)];
        }

        return $found;
    }

    // ---- show -----------------------------------------------------------------------

    public function test_show_carries_lines_schedules_revisions_receipts_and_the_tally_link(): void
    {
        $this->actAs();
        [$order, $grn] = $this->chain();
        $this->tallyEntry($order, 'Purchase Order', TallySyncStatus::Pending);

        $data = $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.document_number', "PO-{$order->id}")
            ->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.receipts_count', 1)
            ->assertJsonPath('data.revisions_count', 1)
            ->assertJsonPath('data.lines.0.quantity', '100.0000')
            ->assertJsonPath('data.lines.0.quantity_received', '50.0000')
            ->assertJsonCount(2, 'data.lines.0.schedules')
            // Oldest window filled first by the arrival.
            ->assertJsonPath('data.lines.0.schedules.0.quantity_received', '50.0000')
            ->assertJsonPath('data.lines.0.schedules.1.quantity_received', '0.0000')
            ->assertJsonPath('data.revisions.0.revision_no', 1)
            ->assertJsonPath('data.revisions.0.kind', 'amend')
            ->assertJsonPath('data.revisions.0.reason', 'split into two windows')
            ->assertJsonPath('data.revisions.0.lines.0.quantity', '80.0000')
            ->assertJsonPath('data.receipts.0.id', $grn->id)
            ->assertJsonPath('data.receipts.0.document_number', "GRN-{$grn->id}")
            ->assertJsonPath('data.receipts.0.receipt_key', 'rk-trace-1')
            ->assertJsonPath('data.receipts.0.quantity', '50.0000')
            ->assertJsonPath('data.receipts.0.lines_count', 1)
            // The link is status + flags + link only (TallySyncLinkService).
            ->assertJsonPath('data.tally.voucher_type', 'Purchase Order')
            ->assertJsonPath('data.tally.status', 'pending')
            ->assertJsonPath('data.can', ['amend' => false, 'close' => true, 'cancel' => false, 'send' => false])
            ->json('data');

        $this->assertSame(['entry_id', 'voucher_type', 'status', 'voucher_number', 'synced_at', 'flags', 'link'], array_keys($data['tally']));
        // The list row does not carry the show-only blocks.
        $row = collect($this->getJson('/api/v1/procurement/purchase-orders')->assertOk()->json('data'))->firstWhere('id', $order->id);
        $this->assertArrayNotHasKey('revisions', $row);
        $this->assertArrayNotHasKey('receipts', $row);
        $this->assertSame(1, $row['receipts_count']);
        $this->assertSame('pending', $row['tally']['status']);
    }

    // ---- trace ----------------------------------------------------------------------

    public function test_trace_walks_order_receipt_lot_bag_load_consumption_and_stock_purpose(): void
    {
        $user = $this->actAs(['procurement.view', 'procurement.manage', 'finance.view']);
        [$order, $grn] = $this->chain();
        $this->tallyEntry($grn, 'Receipt Note', TallySyncStatus::Synced);

        // One bag is loaded into the machine's day bin during a segment
        // (partial 10 kg), the other into the COMMON input (no machine —
        // FC-01); the segment then counts 4 kg left → 6 kg consumed.
        $entry = $this->inProgressEntry();
        // As if Incoming QC had released the arrival (fixture write; the
        // QC flow itself is another test's subject). FIFO likewise: the
        // second bag goes to the common input while the first is still
        // part-loaded, which the FIFO policy would refuse — that policy is
        // TraceabilityTest's subject, not this trace's.
        MaterialBag::query()->update(['status' => MaterialBagStatus::InStore->value]);
        config(['production.traceability.fifo_enforced' => false]);
        $trace = app(TraceabilityService::class);
        // LEGACY MACHINE-STAMPED LOAD — a historical audit row
        // (DEC-20260807-006 retired the machine-stamped load: the floor's
        // only resin flow is the centralized day bin, and rows written under
        // the previous understanding are preserved untouched). It is written
        // here as the database holds it, NOT through the live service,
        // because the trace's job is to READ such a row correctly; the live
        // write door at POST production/day-bin/load is a Phase 7/8
        // retirement item.
        $bagOne = MaterialBag::query()->where('barcode', 'BAG-A1-1')->firstOrFail();
        $bagOne->update(['remaining_kg' => bcsub((string) $bagOne->remaining_kg, '10', 4)]);
        DayBinMovement::create([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->itemA->id,
            'shift_production_entry_id' => $entry->id,
            'type' => DayBinMovementType::Load,
            'material_bag_id' => $bagOne->id,
            'quantity_kg' => '10.0000',
            'recorded_by' => $user->id,
            'recorded_at' => now(),
        ]);
        $trace->loadBagToDayBin([
            'work_center_id' => null,
            'barcode' => 'BAG-A1-2',
        ], $user->id);
        app(DayBinLedgerService::class)->record([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->itemA->id,
            'shift_production_entry_id' => $entry->id,
            'type' => 'count',
            'quantity_kg' => '4',
        ]);
        // The batch's consumption issue as completeBatch records it.
        app(StockMovementService::class)->recordIssue(
            itemId: $this->itemA->id,
            warehouseId: $this->store->id,
            quantity: '6',
            reference: "SPE #{$entry->id}",
            purpose: StockMovementPurpose::Consumption,
        );

        $data = $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}/trace")
            ->assertOk()
            ->json('data');

        // The order header.
        $this->assertSame("PO-{$order->id}", $data['purchase_order']['document_number']);
        $this->assertSame('partially_received', $data['purchase_order']['status']);
        $this->assertSame(['id' => $this->vendor->id, 'code' => 'V-ALPHA', 'name' => 'Vendor Alpha'], $data['purchase_order']['vendor']);
        $this->assertNull($data['purchase_order']['tally']);
        $this->assertNull($data['purchase_order']['tally_staging']);
        $this->assertSame('100.0000', $data['lines'][0]['quantity']);
        $this->assertSame('50.0000', $data['lines'][0]['remaining']);
        $this->assertSame('1.0000', $data['lines'][0]['unit_price']);
        $this->assertCount(2, $data['lines'][0]['schedules']);

        // PO → GRN.
        $this->assertCount(1, $data['receipts']);
        $receipt = $data['receipts'][0];
        $this->assertSame($grn->id, $receipt['id']);
        $this->assertSame("GRN-{$grn->id}", $receipt['document_number']);
        $this->assertSame('rk-trace-1', $receipt['receipt_key']);
        $this->assertSame('DC-0001', $receipt['reference']);
        $this->assertSame(['id' => $this->store->id, 'code' => 'RM-STORE', 'name' => 'RM Store'], $receipt['warehouse']);
        $this->assertSame('synced', $receipt['tally']['status']);
        $this->assertSame('Receipt Note', $receipt['tally']['voucher_type']);

        // GRN → line → stock movement (purpose Receipt) and lot.
        $line = $receipt['lines'][0];
        $this->assertSame('50.0000', $line['quantity']);
        $this->assertSame('1.0000', $line['unit_cost']);
        $this->assertSame(['id' => $this->itemA->id, 'sku' => 'ITEM_A', 'name' => 'Item A', 'uom' => 'Kgs'], $line['item']);
        $this->assertCount(1, $line['stock_movements']);
        $this->assertSame('receipt', $line['stock_movements'][0]['type']);
        $this->assertSame('receipt', $line['stock_movements'][0]['purpose']);
        $this->assertSame('50.0000', $line['stock_movements'][0]['quantity']);
        $this->assertSame('DC-0001', $line['stock_movements'][0]['reference']);

        // lot → bags → loads.
        $lot = $line['material_lots'][0];
        $this->assertSame('LOT-A1', $lot['supplier_lot_no']);
        $this->assertSame(2, $lot['bag_count']);
        $this->assertSame('50.0000', $lot['total_received_kg']);
        $this->assertSame('1.0000', $lot['receipt_rate_per_kg']);
        $bags = collect($lot['bags'])->keyBy('barcode');
        $this->assertSame(['BAG-A1-1', 'BAG-A1-2'], $bags->keys()->sort()->values()->all());
        $bagOne = $bags['BAG-A1-1'];
        $this->assertSame('15.0000', $bagOne['remaining_kg']);
        $this->assertCount(1, $bagOne['loads']);
        $this->assertSame('10.0000', $bagOne['loads'][0]['quantity_kg']);
        $this->assertSame($entry->id, $bagOne['loads'][0]['shift_production_entry_id']);
        $this->assertSame('20260817-MC01-001', $bagOne['loads'][0]['batch_number']);
        $this->assertSame(['id' => $this->machine->id, 'code' => 'MC-01', 'name' => 'Machine 1'], $bagOne['loads'][0]['work_center']);
        $bagTwo = $bags['BAG-A1-2'];
        $this->assertSame('0.0000', $bagTwo['remaining_kg']);
        // The common input: no machine, no segment — a bag belongs to neither.
        $this->assertNull($bagTwo['loads'][0]['work_center']);
        $this->assertNull($bagTwo['loads'][0]['shift_production_entry_id']);

        // → the production entry, its segment consumption, its issue.
        $this->assertCount(1, $data['consumption']);
        $consumption = $data['consumption'][0];
        $this->assertSame($entry->id, $consumption['shift_production_entry']['id']);
        $this->assertSame('20260817-MC01-001', $consumption['shift_production_entry']['batch_number']);
        $this->assertSame('in_progress', $consumption['shift_production_entry']['batch_status']);
        $this->assertSame('MC-01', $consumption['shift_production_entry']['work_center']['code']);
        $this->assertSame($this->itemA->id, $consumption['item']['id']);
        $this->assertSame('10.0000', $consumption['loaded_kg_from_this_order']);
        $this->assertSame([
            'opening_kg' => '0.0000', 'loaded_kg' => '10.0000', 'returned_kg' => '0.0000',
            'closing_kg' => '4.0000', 'consumed_kg' => '6.0000',
        ], $consumption['day_bin']);
        $this->assertCount(1, $consumption['stock_issues']);
        $this->assertSame('consumption', $consumption['stock_issues'][0]['purpose']);
        $this->assertSame('6.0000', $consumption['stock_issues'][0]['quantity']);

        // A finance reader met no withheld note anywhere.
        $this->assertSame([], $this->keyPaths($data, 'rate_withheld'));
    }

    public function test_trace_withholds_every_rate_from_a_procurement_only_reader(): void
    {
        $this->actAs();
        [$order] = $this->chain();

        $data = $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}/trace")->assertOk()->json('data');

        $this->assertSame([], $this->rateKeyPaths($data), 'the trace leaked a rate key to a procurement-only reader');
        // Where a rate would stand, the reader is told it is withheld — on
        // the order line, the receipt line, its stock movement and its lot.
        $this->assertSame([
            'lines.0.rate_withheld',
            'receipts.0.lines.0.rate_withheld',
            'receipts.0.lines.0.stock_movements.0.rate_withheld',
            'receipts.0.lines.0.material_lots.0.rate_withheld',
        ], $this->keyPaths($data, 'rate_withheld'));
        $this->assertStringContainsString('FC-06', $data['lines'][0]['rate_withheld']);

        // The show endpoint (and its revisions, which snapshot the rate) too.
        $show = $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame([], $this->rateKeyPaths($show), 'show leaked a rate key to a procurement-only reader');
        $this->assertArrayHasKey('rate_withheld', $show['revisions'][0]['lines'][0]);
        $this->assertSame('80.0000', $show['revisions'][0]['lines'][0]['quantity']);

        // And the finance reader gets the number and no note.
        $this->actAs(['procurement.view', 'finance.view']);
        $show = $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")->assertOk()->json('data');
        $this->assertSame('1.0000', $show['revisions'][0]['lines'][0]['unit_price']);
        $this->assertSame([], $this->keyPaths($show, 'rate_withheld'));
    }

    public function test_trace_of_an_order_with_no_receipts_is_the_bare_chain(): void
    {
        $this->actAs();
        $id = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => '10', 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');

        $data = $this->getJson("/api/v1/procurement/purchase-orders/{$id}/trace")->assertOk()->json('data');

        $this->assertSame('draft', $data['purchase_order']['status']);
        $this->assertSame([], $data['receipts']);
        $this->assertSame([], $data['consumption']);
        $this->assertSame('10.0000', $data['lines'][0]['remaining']);
    }

    /**
     * TWO ARRIVALS ON ONE ORDER, NEITHER CARRYING A REFERENCE. Both stock
     * movements are then stamped with the same fallback string
     * ("GRN for PO #{id}"), so a trace that matched by that string alone
     * showed BOTH movements under BOTH receipts — 30 and 20 each read as
     * [30, 20]. The receipt line now carries the movement's own id, so each
     * arrival shows exactly the ledger row it wrote, and the row says HOW it
     * was resolved (`match`).
     */
    public function test_two_referenceless_receipts_on_one_order_each_show_only_their_own_stock_movement(): void
    {
        $this->actAs(['procurement.view', 'procurement.manage', 'finance.view']);
        [$orderId, $lineId] = $this->sentOrderForTwoArrivals();

        $first = $this->receiveWithoutReference($orderId, $lineId, '30', 'rk-two-1');
        $second = $this->receiveWithoutReference($orderId, $lineId, '20', 'rk-two-2');

        $data = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}/trace")->assertOk()->json('data');

        $receipts = collect($data['receipts'])->keyBy('id');
        $this->assertSame([$first, $second], $receipts->keys()->all());

        $firstLine = $receipts[$first]['lines'][0];
        $this->assertCount(1, $firstLine['stock_movements'], "the first arrival showed another arrival's movement");
        $this->assertSame('30.0000', $firstLine['stock_movements'][0]['quantity']);
        $this->assertSame('by_id', $firstLine['match']);

        $secondLine = $receipts[$second]['lines'][0];
        $this->assertCount(1, $secondLine['stock_movements'], "the second arrival showed another arrival's movement");
        $this->assertSame('20.0000', $secondLine['stock_movements'][0]['quantity']);
        $this->assertSame('by_id', $secondLine['match']);

        // A LEGACY line — booked before the column existed, so it carries no
        // movement id (fixture write, said out loud). The old reference walk
        // still answers it, and the row says that is what happened.
        GoodsReceiptNoteLine::query()
            ->where('goods_receipt_note_id', $second)
            ->update(['stock_movement_id' => null]);

        $data = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}/trace")->assertOk()->json('data');
        $legacy = collect($data['receipts'])->firstWhere('id', $second)['lines'][0];
        $this->assertSame('by_reference', $legacy['match']);
        $this->assertNotSame([], $legacy['stock_movements'], 'a legacy line still resolves its ledger rows');
        // The line that DOES carry an id is unaffected by its neighbour.
        $stillById = collect($data['receipts'])->firstWhere('id', $first)['lines'][0];
        $this->assertSame('by_id', $stillById['match']);
        $this->assertCount(1, $stillById['stock_movements']);
    }

    /**
     * A sent order of 100 with one line — the fixture the two-arrival test
     * receives against.
     *
     * @return array{0: int, 1: int}
     */
    private function sentOrderForTwoArrivals(): array
    {
        $orderId = (int) $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => '100', 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertOk();

        return [$orderId, (int) PurchaseOrder::findOrFail($orderId)->lines()->firstOrFail()->id];
    }

    /** One arrival with NO reference of its own — the case that used to cross-attribute. */
    private function receiveWithoutReference(int $orderId, int $lineId, string $quantity, string $receiptKey): int
    {
        return (int) $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => $receiptKey,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-08-17',
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => $quantity]],
        ])->assertCreated()->json('data.id');
    }

    // ---- GRN show -------------------------------------------------------------------

    public function test_goods_receipt_show_carries_lines_lots_and_the_tally_link(): void
    {
        $this->actAs();
        [$order, $grn] = $this->chain();
        $this->tallyEntry($grn, 'Receipt Note', TallySyncStatus::Failed);

        $data = $this->getJson("/api/v1/procurement/goods-receipts/{$grn->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $grn->id)
            ->assertJsonPath('data.purchase_order_id', $order->id)
            ->assertJsonPath('data.receipt_key', 'rk-trace-1')
            ->assertJsonPath('data.lines.0.quantity', '50.0000')
            ->assertJsonPath('data.lines.0.material_lots.0.supplier_lot_no', 'LOT-A1')
            ->assertJsonCount(2, 'data.lines.0.material_lots.0.bags')
            ->assertJsonPath('data.tally.status', 'failed')
            ->assertJsonPath('data.tally.voucher_type', 'Receipt Note')
            ->json('data');

        // No error text, no payload rides the link (FC-06 lives behind it).
        $this->assertSame(['entry_id', 'voucher_type', 'status', 'voucher_number', 'synced_at', 'flags', 'link'], array_keys($data['tally']));
        $this->assertSame([], $this->rateKeyPaths($data));

        // The list rows carry the same link.
        $row = collect($this->getJson('/api/v1/procurement/goods-receipts')->assertOk()->json('data'))->firstWhere('id', $grn->id);
        $this->assertSame('failed', $row['tally']['status']);
    }

    // ---- 404 / 403 ------------------------------------------------------------------

    public function test_a_missing_order_or_receipt_is_a_404(): void
    {
        $this->actAs();

        $this->getJson('/api/v1/procurement/purchase-orders/999999')->assertNotFound();
        $this->getJson('/api/v1/procurement/purchase-orders/999999/trace')->assertNotFound();
        $this->getJson('/api/v1/procurement/goods-receipts/999999')->assertNotFound();
    }

    public function test_show_and_trace_need_procurement_view(): void
    {
        $this->actAs();
        [$order, $grn] = $this->chain();

        $this->actAs(['inventory.view']);
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")->assertForbidden();
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}/trace")->assertForbidden();
        $this->getJson("/api/v1/procurement/goods-receipts/{$grn->id}")->assertForbidden();

        $this->actAs(['procurement.view']);
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")->assertOk();
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}/trace")->assertOk();
        $this->getJson("/api/v1/procurement/goods-receipts/{$grn->id}")->assertOk();
    }

    // ---- the store-issue half of the trace (Phase 7.5, WS-C) ------------------------

    /**
     * THE TRACE FOLLOWS THIS ORDER'S BAGS INTO THE STORE ISSUE, AND STOPS
     * THERE.
     *
     * The `consumption` block is the HISTORICAL machine/segment answer and
     * only ever fills from machine-stamped day-bin rows; those write doors
     * are closed, so for material received from here on it is legitimately
     * empty. `issued_to_production` is what replaced it: which of this
     * order's bags the store handed over, how many kg, by whom, received by
     * whom, when, against which request.
     *
     * FC-01 is the assertion that matters most — NO machine and NO batch
     * anywhere in that block. The ERP says which bags were issued to
     * production; it never says which batch used them.
     */
    public function test_the_trace_follows_this_orders_bags_into_a_store_issue_and_names_no_batch(): void
    {
        $user = $this->actAs(['procurement.view', 'procurement.manage', 'finance.view']);
        [$order] = $this->chain();

        $bag = MaterialBag::query()->where('barcode', 'BAG-A1-1')->firstOrFail();
        $issue = StoreIssue::create([
            'issue_number' => 'SI-2026-0007',
            'status' => StoreIssueStatus::Issued,
            'issued_by' => $user->id,
            'received_by' => $user->id,
            'issued_at' => now(),
        ]);
        $line = StoreIssueLine::create([
            'store_issue_id' => $issue->id,
            'item_id' => $this->itemA->id,
            'from_warehouse_id' => $this->store->id,
            'to_warehouse_id' => Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress'])->id,
            'quantity_issued' => '25',
            'quantity_returned' => '0',
            'uom' => 'Kgs',
        ]);
        StoreIssueBagScan::create([
            'store_issue_id' => $issue->id,
            'store_issue_line_id' => $line->id,
            'material_bag_id' => $bag->id,
            'material_lot_id' => $bag->material_lot_id,
            'quantity_kg' => '25',
            'issued_by' => $user->id,
            'received_by' => $user->id,
            'scanned_at' => now(),
        ]);

        $trace = $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}/trace")->assertOk()->json('data');

        $this->assertCount(1, $trace['issued_to_production']);
        $handover = $trace['issued_to_production'][0];

        $this->assertSame('SI-2026-0007', $handover['issue_number']);
        $this->assertSame('issued', $handover['status']);
        $this->assertSame('25.0000', $handover['issued_kg_from_this_order']);
        $this->assertSame($user->id, $handover['issued_by']['id']);
        $this->assertSame($user->id, $handover['received_by']['id']);
        $this->assertCount(1, $handover['bags']);
        $this->assertSame(
            [$bag->id, 'BAG-A1-1', '25.0000'],
            [$handover['bags'][0]['material_bag_id'], $handover['bags'][0]['barcode'], $handover['bags'][0]['quantity_kg']],
        );

        // FC-01: the trace stops at the issue. FC-06: no rate rides it — the
        // store-issue ledger carries none at all.
        foreach (['machine', 'work_center', 'segment', 'batch_number', 'shift_production_entry'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $handover);
        }
        foreach (self::RATE_KEYS as $rateKey) {
            $this->assertArrayNotHasKey($rateKey, $handover);
        }

        // The historical block is untouched and legitimately empty: nothing
        // was ever loaded into a machine's day bin for this order.
        $this->assertSame([], $trace['consumption']);

        // A CANCELLED issue was reversed in full, and stops being an answer.
        $issue->update(['status' => StoreIssueStatus::Cancelled]);
        $this->assertSame(
            [],
            $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}/trace")->assertOk()->json('data.issued_to_production'),
        );
    }
}
