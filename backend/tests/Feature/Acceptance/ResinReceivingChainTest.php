<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Procurement\Models\GoodsReceiptNote;
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
        foreach (['procurement.manage', 'inventory.manage', 'production.manage'] as $permission) {
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
