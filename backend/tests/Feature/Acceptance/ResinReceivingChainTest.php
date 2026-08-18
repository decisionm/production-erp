<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssueBagScan;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * RESIN, FROM THE PURCHASE ORDER TO THE PRODUCTION FLOOR.
 *
 *   Purchase Order -> GRN (bags scanned) -> Material Lot -> Bags -> RM Store
 *   -> Material Request -> Store Issue -> Production/WIP
 *
 * Every link is walked over HTTP, and the two balances that matter are printed
 * at each step (MATERIAL_FLOW_LEDGER_REPORT=1) so the chain can be READ, not
 * only asserted.
 *
 * The two rules this chain must never break:
 *
 *  · A STORE ISSUE IS CUSTODY, NOT CONSUMPTION. Material handed to production
 *    moves RM Store -> Production/WIP and is still stock. Nothing here books a
 *    consumption, and the test asserts the consumed total stays at zero
 *    throughout.
 *  · A BAG BELONGS TO NO MACHINE AND NO BATCH (FC-01). Bags carry identity,
 *    provenance and custody — a lot, a supplier, a GRN, a purchase order, a
 *    warehouse — and never a machine.
 */
class ResinReceivingChainTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{step: string, rm: string, wip: string, bags: int}> */
    private array $report = [];

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.manage', 'inventory.manage', 'production.manage', 'quality.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->resin = Item::create([
            'sku' => 'RM-RELPET', 'name' => 'Relpet PET Resin', 'uom' => 'Kgs',
            'is_active' => true, 'is_production_input' => true,
        ]);

        $this->store = Warehouse::create(['code' => 'RC-RM', 'name' => 'RC Raw Material Store', 'is_active' => true, 'tally_guid' => 'rc-gd']);
        $this->wip = Warehouse::create(['code' => 'RC-WIP', 'name' => 'RC Production WIP', 'is_active' => false]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);

        // FC-06: a synthetic vendor. No real supplier name reaches a test.
        $this->vendor = Vendor::create(['code' => 'RC-V1', 'name' => 'RC Test Supplier', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->report !== [] && getenv('MATERIAL_FLOW_LEDGER_REPORT')) {
            $w = fn (string $t, int $n) => str_pad($t, $n);
            fwrite(STDERR, "\n".$w('STEP', 44).$w('RM STORE', 14).$w('PROD/WIP', 14)."BAGS\n");
            fwrite(STDERR, str_repeat('-', 86)."\n");
            foreach ($this->report as $row) {
                fwrite(STDERR, $w($row['step'], 44).$w($row['rm'], 14).$w($row['wip'], 14).$row['bags']."\n");
            }
            fwrite(STDERR, "\n");
        }

        parent::tearDown();
    }

    public function test_relpet_walks_from_the_purchase_order_to_the_production_floor(): void
    {
        $this->record('0 · before anything');

        // --- PURCHASE ORDER ------------------------------------------------
        $orderId = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100', 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');

        $lineId = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}")
            ->assertOk()->json('data.lines.0.id');

        // A draft cannot be received against — the order has to have been SENT
        // to the supplier first, which is the state the goods arrive against.
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertOk();

        $this->record('1 · purchase order raised and sent');

        // --- GOODS RECEIPT, WITH FOUR BAGS SCANNED -------------------------
        // The supplier's own barcodes. Where a supplier prints none the server
        // mints them instead — either way one record per physical bag.
        $scanned = ['RELPET-B1', 'RELPET-B2', 'RELPET-B3', 'RELPET-B4'];

        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'rc-key-1',
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'reference' => 'RC-DC-1',
            'received_date' => '2026-08-18',
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'quantity' => '100',
                'lots' => [[
                    'supplier_lot_no' => 'RC-LOT-9',
                    'bag_count' => 4,
                    'bag_weight_kg' => '25',
                    'barcodes' => $scanned,
                ]],
            ]],
        ])->assertCreated();

        $this->record('2 · goods receipt — 4 bags scanned');

        // --- THE LOT AND ITS BAGS ------------------------------------------
        $lot = MaterialLot::query()->where('supplier_lot_no', 'RC-LOT-9')->sole();
        $this->assertSame($this->resin->id, (int) $lot->item_id);

        // THE PROVENANCE CHAIN, asserted through the records rather than
        // through the response body: the lot names its goods receipt, and that
        // receipt names the purchase order it arrived against. That is the
        // whole "where did this resin come from" question, in two hops.
        $this->assertNotNull($lot->grn_id, 'the lot links back to its GRN');
        $grn = GoodsReceiptNote::findOrFail($lot->grn_id);
        $this->assertSame((int) $orderId, (int) $grn->purchase_order_id, 'the GRN links back to its purchase order');
        $this->assertSame($this->vendor->id, (int) $grn->purchaseOrder->vendor_id, 'and the order names the supplier');

        $bags = MaterialBag::query()->where('material_lot_id', $lot->id)->get();
        $this->assertCount(4, $bags, 'one identifiable bag record per physical bag');
        $this->assertEqualsCanonicalizing($scanned, $bags->pluck('barcode')->all(),
            'the supplier barcodes scanned in the bay are the bags\' identities');
        $this->assertSame(0, bccomp((string) $bags->sum('original_kg'), '100', 4));

        // FC-01 — a bag names no machine and no batch, at the moment it exists.
        foreach ($bags as $bag) {
            $this->assertNull($bag->day_bin_work_center_id, 'a bag belongs to no machine (FC-01)');
        }

        // --- RM STORE STOCK -------------------------------------------------
        $this->assertSame(0, bccomp($this->balance($this->store), '100', 4),
            'the receipt puts the resin in the Raw Material Store');

        // --- THE FLOOR ASKS -------------------------------------------------
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '75']],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        $this->record('3 · request raised and submitted');

        // Raising an ask moves nothing.
        $this->assertSame(0, bccomp($this->balance($this->store), '100', 4));
        $this->assertSame(0, bccomp($this->balance($this->wip), '0', 4));

        // --- THE STORE HANDS OVER -------------------------------------------
        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'lines' => [[
                'material_request_line_id' => $request['lines'][0]['id'],
                'item_id' => $this->resin->id,
                'quantity' => '75',
            ]],
        ])->assertCreated();

        $this->record('4 · store issue — 75 kg handed over');

        // --- WHAT THE BOOKS NOW SAY -----------------------------------------
        $this->assertSame(0, bccomp($this->balance($this->store), '25', 4));
        $this->assertSame(0, bccomp($this->balance($this->wip), '75', 4),
            'the material stands in Production/WIP');

        // THE LINE THAT MUST NOT MOVE: a store issue is custody, not use.
        $consumed = StockMovement::query()
            ->where('purpose', StockMovementPurpose::Consumption->value)
            ->sum('quantity');
        $this->assertSame(0, bccomp((string) ($consumed ?: '0'), '0', 4),
            'nothing has been consumed — a store issue is custody, not production use');

        // ...and the floor panel says the same thing, from the balance.
        $floor = $this->getJson('/api/v1/inventory/production-floor-stock')->assertOk()->json('data');
        $this->assertCount(1, $floor);
        $this->assertSame('RM-RELPET', $floor[0]['sku']);
        $this->assertSame(0, bccomp((string) $floor[0]['quantity'], '75', 4));
    }

    /**
     * WHICH PHYSICAL BAGS WENT TO THE FLOOR, AND WHICH STAYED IN THE STORE.
     *
     * The aggregate chain proves kilograms. This proves IDENTITIES: four
     * barcoded bags arrive on one purchase order, quality releases them, three
     * are scanned onto a handover and one is not — and afterwards each bag's
     * own record says where it now stands.
     *
     * The full lineage asserted here, in one direction:
     *
     *   PO -> GRN -> Material Lot -> Bag barcode -> RM Store
     *      -> Material Request -> Store Issue -> Production/WIP
     *
     * FC-01 IS THE LINE THIS MUST NOT CROSS. Every claim below is about
     * CUSTODY — where a bag is and which handover moved it. Nothing here says a
     * bag belongs to a machine, and nothing says a batch consumed a particular
     * bag: batch consumption is calculated, and the trace deliberately stops at
     * the issue.
     */
    public function test_the_bags_that_moved_to_the_floor_are_identifiable_and_the_rest_stay_in_the_store(): void
    {
        [$orderId, $lineId] = $this->sentOrder();

        $scanned = ['RELPET-C1', 'RELPET-C2', 'RELPET-C3', 'RELPET-C4'];
        $this->receive($orderId, $lineId, $scanned, 'RC-LOT-C');

        // Quality releases the arrival — until then production cannot load a
        // bag at all, which is itself part of the chain.
        $grnLineId = GoodsReceiptNoteLine::query()->latest('id')->value('id');
        $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $grnLineId,
            'inspected_quantity' => '100',
            'accepted_quantity' => '100',
            'rejected_quantity' => '0',
            'inspection_date' => '2026-08-18',
        ])->assertCreated();

        // Every bag is CREATED in the store. (This column is written once, at
        // receipt, and not maintained afterwards — hence the scan-based
        // assertions below rather than a location lookup.)
        foreach (MaterialBag::all() as $bag) {
            $this->assertSame($this->store->id, (int) $bag->current_warehouse_id);
        }

        // --- the floor asks, the store hands over THREE of the four bags ----
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '75']],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'lines' => [],
        ])->assertCreated()->json('data');

        $handedOver = ['RELPET-C1', 'RELPET-C2', 'RELPET-C3'];
        foreach ($handedOver as $barcode) {
            $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
                'barcode' => $barcode,
                'material_request_line_id' => $request['lines'][0]['id'],
            ])->assertCreated();
        }

        $this->record('5 · three bags scanned onto the handover');

        // --- WHICH BAG WENT TO THE FLOOR -----------------------------------
        // Read from the SCAN RECORDS, not from a location column. A bag's
        // `current_warehouse_id` is not maintained after creation, and writing
        // it here broke two things (see StoreIssueService and Q57). What the
        // system does hold, exactly and permanently, is which bag was scanned
        // onto which handover — and that is the custody claim worth making.
        $movedBarcodes = StoreIssueBagScan::query()
            ->where('store_issue_id', $issue['id'])
            ->join('material_bags', 'material_bags.id', '=', 'store_issue_bag_scans.material_bag_id')
            ->pluck('material_bags.barcode')
            ->all();

        $neverScanned = MaterialBag::query()
            ->whereNotIn('id', StoreIssueBagScan::query()->select('material_bag_id'))
            ->pluck('barcode')
            ->all();

        $this->assertEqualsCanonicalizing($handedOver, $movedBarcodes,
            'exactly the three scanned bags were handed to production');
        $this->assertSame(['RELPET-C4'], $neverScanned,
            'the bag nobody scanned never left the Raw Material Store');

        // Each scan carries the kilograms that moved with it, so the identities
        // and the balance are two views of one fact rather than two claims.
        $this->assertSame(0, bccomp(
            (string) StoreIssueBagScan::query()->where('store_issue_id', $issue['id'])->sum('quantity_kg'),
            '75', 4,
        ));

        // ...and the kilograms agree with the identities: 3 x 25 = 75.
        $this->assertSame(0, bccomp($this->balance($this->wip), '75', 4));
        $this->assertSame(0, bccomp($this->balance($this->store), '25', 4));

        // --- THE LINEAGE, followed from a bag all the way back --------------
        $bag = MaterialBag::query()->where('barcode', 'RELPET-C1')->sole();
        $lot = MaterialLot::findOrFail($bag->material_lot_id);
        $grn = GoodsReceiptNote::findOrFail($lot->grn_id);

        $this->assertSame('RC-LOT-C', $lot->supplier_lot_no);
        $this->assertSame((int) $orderId, (int) $grn->purchase_order_id);
        $this->assertSame($this->vendor->id, (int) $grn->purchaseOrder->vendor_id);

        // ...and forward, to the handover that moved it.
        $scan = StoreIssueBagScan::query()->where('material_bag_id', $bag->id)->sole();
        $this->assertSame((int) $issue['id'], (int) $scan->store_issue_id);

        // --- FC-01 ----------------------------------------------------------
        foreach (MaterialBag::all() as $each) {
            $this->assertNull($each->day_bin_work_center_id,
                'custody may say WHERE a bag is; it may never say which machine it belongs to (FC-01)');
        }

        // --- A RETURN PUTS A BAG BACK ---------------------------------------
        $line = StoreIssueLine::query()->where('store_issue_id', $issue['id'])->sole();
        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $line->id, 'quantity' => '25']],
        ])->assertOk();

        $this->record('6 · 25 kg returned to the store');

        $this->assertSame(0, bccomp($this->balance($this->wip), '50', 4));
        $this->assertSame(0, bccomp($this->balance($this->store), '50', 4));
    }

    /** @return array{0: int, 1: int} */
    private function sentOrder(): array
    {
        $orderId = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100', 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');

        $lineId = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}")->assertOk()->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertOk();

        return [(int) $orderId, (int) $lineId];
    }

    /** @param  list<string>  $barcodes */
    private function receive(int $orderId, int $lineId, array $barcodes, string $lotNo): void
    {
        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'rc-key-'.$lotNo,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'reference' => 'RC-DC-'.$lotNo,
            'received_date' => '2026-08-18',
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'quantity' => '100',
                'lots' => [[
                    'supplier_lot_no' => $lotNo,
                    'bag_count' => count($barcodes),
                    'bag_weight_kg' => '25',
                    'barcodes' => $barcodes,
                ]],
            ]],
        ])->assertCreated();
    }

    private function balance(Warehouse $warehouse): string
    {
        return bcadd((string) (StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0'), '0', 4);
    }

    private function record(string $step): void
    {
        $this->report[] = [
            'step' => $step,
            'rm' => $this->balance($this->store),
            'wip' => $this->balance($this->wip),
            'bags' => MaterialBag::query()->count(),
        ];
    }
}
