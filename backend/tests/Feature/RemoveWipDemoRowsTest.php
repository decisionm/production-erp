<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DEC-20260903-002 — the 23-Jul-2026 demo stock standing on the
 * Production/WIP row is removed BY EXACT RECORD, dry run first.
 *
 * Everything worth testing here is a refusal. The command deletes from a
 * ledger that is append-only for everything the factory actually did, so
 * the only thing standing between it and a real transaction is the
 * candidate rule and the refusals below. Each one is a way a careless run
 * could destroy history:
 *
 *   - an id from somewhere else, pasted by mistake;
 *   - a row another record points at (a goods-receipt line);
 *   - one leg of a transfer pair, which would conjure stock in the other
 *     location and break the ledger invariant silently.
 *
 * The happy path is tested too, and it is tested by the invariant
 * inventory:check-ledger signs by: after a removal, every touched balance
 * equals the exact signed sum of what survives.
 */
class RemoveWipDemoRowsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wip;

    private Warehouse $store;

    private Item $label;

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'RM', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production WIP', 'is_active' => true]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);

        $this->label = Item::create(['sku' => 'LBL-1', 'name' => 'Bottle Label', 'uom' => 'Nos', 'is_active' => true]);
        $this->resin = Item::create(['sku' => 'PET-VIRGIN', 'name' => 'PET Resin (Virgin Grade)', 'uom' => 'Kgs', 'is_active' => true]);
    }

    /**
     * A demo row exactly as the seeder left it on live: on the WIP row, the
     * 23-Jul UTC day, no purpose stamp, a seeder reference.
     */
    private function demoRow(Item $item, string $quantity, string $reference, StockMovementType $type = StockMovementType::Receipt): int
    {
        $id = DB::table('stock_movements')->insertGetId([
            'item_id' => $item->id,
            'warehouse_id' => $this->wip->id,
            'type' => $type->value,
            'purpose' => null,
            'quantity' => $quantity,
            'unit_cost' => '1.0000',
            'reference' => $reference,
            'movement_date' => '2026-07-23 04:44:00',
            'created_at' => '2026-07-23 04:44:00',
            'updated_at' => '2026-07-23 04:44:00',
        ]);

        $this->rebalance($item, $this->wip);

        return (int) $id;
    }

    /** The balance the seeder would have left: the signed sum of what is there. */
    private function rebalance(Item $item, Warehouse $warehouse): void
    {
        $sum = '0.0000';

        foreach (DB::table('stock_movements')->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->get(['type', 'quantity']) as $row) {
            $sign = in_array((string) $row->type, ['receipt', 'transfer_in'], true) ? '1' : '-1';
            $sum = bcadd($sum, bcmul((string) $row->quantity, $sign, 4), 4);
        }

        StockBalance::updateOrCreate(
            ['item_id' => $item->id, 'warehouse_id' => $warehouse->id],
            ['quantity' => $sum, 'average_cost' => '1.0000'],
        );
    }

    /** Every (item, warehouse) balance equals the signed sum of its movements — the check-ledger rule. */
    private function assertLedgerSigns(): void
    {
        foreach (StockBalance::all() as $balance) {
            $sum = '0.0000';

            foreach (DB::table('stock_movements')->where('item_id', $balance->item_id)->where('warehouse_id', $balance->warehouse_id)->get(['type', 'quantity']) as $row) {
                $sign = in_array((string) $row->type, ['receipt', 'transfer_in'], true) ? '1' : '-1';
                $sum = bcadd($sum, bcmul((string) $row->quantity, $sign, 4), 4);
            }

            $this->assertSame(
                bcadd($sum, '0', 4),
                bcadd((string) $balance->quantity, '0', 4),
                "balance drifted from the ledger for item {$balance->item_id} at warehouse {$balance->warehouse_id}",
            );
        }
    }

    public function test_the_dry_run_lists_the_candidates_and_removes_nothing(): void
    {
        $labels = $this->demoRow($this->label, '6000', 'Issue to Line 1');
        $virgin = $this->demoRow($this->resin, '860', 'QC release for lot A');

        $this->artisan('inventory:remove-wip-demo-rows')
            ->expectsOutputToContain('candidates 2')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('stock_movements')->whereIn('id', [$labels, $virgin])->count());
    }

    public function test_a_stamped_row_on_the_same_day_is_never_a_candidate(): void
    {
        $this->demoRow($this->label, '6000', 'Issue to Line 1');

        // A real transfer into production, written the same day, purpose
        // stamped: the factory's own row, and never a candidate.
        DB::table('stock_movements')->insert([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->wip->id,
            'type' => StockMovementType::TransferIn->value,
            'purpose' => 'issue_to_production',
            'quantity' => '1000',
            'reference' => 'SI-000002',
            'movement_date' => '2026-07-23 09:00:00',
            'created_at' => '2026-07-23 09:00:00',
            'updated_at' => '2026-07-23 09:00:00',
        ]);

        $this->artisan('inventory:remove-wip-demo-rows')
            ->expectsOutputToContain('candidates 1')
            ->assertSuccessful();
    }

    public function test_a_row_in_another_warehouse_or_on_another_day_is_never_a_candidate(): void
    {
        $this->demoRow($this->label, '6000', 'Issue to Line 1');

        // Same shape, the Store's row — a different location.
        DB::table('stock_movements')->insert([
            'item_id' => $this->label->id,
            'warehouse_id' => $this->store->id,
            'type' => StockMovementType::Receipt->value,
            'purpose' => null,
            'quantity' => '10',
            'reference' => 'Issue to Line 2',
            'movement_date' => '2026-07-23 04:44:00',
            'created_at' => '2026-07-23 04:44:00',
            'updated_at' => '2026-07-23 04:44:00',
        ]);

        // Same shape, the WIP row, a different day.
        DB::table('stock_movements')->insert([
            'item_id' => $this->label->id,
            'warehouse_id' => $this->wip->id,
            'type' => StockMovementType::Receipt->value,
            'purpose' => null,
            'quantity' => '10',
            'reference' => 'Issue to Line 3',
            'movement_date' => '2026-07-24 04:44:00',
            'created_at' => '2026-07-24 04:44:00',
            'updated_at' => '2026-07-24 04:44:00',
        ]);

        $this->artisan('inventory:remove-wip-demo-rows')
            ->expectsOutputToContain('candidates 1')
            ->assertSuccessful();
    }

    public function test_write_removes_only_the_named_ids_and_recomputes_the_balances(): void
    {
        $labels = $this->demoRow($this->label, '6000', 'Issue to Line 1');
        $virgin = $this->demoRow($this->resin, '860', 'QC release for lot A');

        $this->assertSame('6000.0000', $this->balance($this->label));
        $this->assertSame('860.0000', $this->balance($this->resin));

        $this->artisan("inventory:remove-wip-demo-rows --write --ids={$labels}")
            ->expectsOutputToContain('REMOVED 1')
            ->assertSuccessful();

        $this->assertNull(DB::table('stock_movements')->find($labels));
        $this->assertNotNull(DB::table('stock_movements')->find($virgin), 'a row nobody named stays');
        $this->assertSame('0.0000', $this->balance($this->label));
        $this->assertSame('860.0000', $this->balance($this->resin));
        $this->assertLedgerSigns();
    }

    public function test_a_write_without_ids_is_refused(): void
    {
        $this->demoRow($this->label, '6000', 'Issue to Line 1');

        $this->artisan('inventory:remove-wip-demo-rows --write')->assertFailed();

        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    public function test_an_id_that_is_not_a_candidate_refuses_the_whole_run(): void
    {
        $demo = $this->demoRow($this->label, '6000', 'Issue to Line 1');

        // The Store's own row: real, and not on the WIP list.
        $real = DB::table('stock_movements')->insertGetId([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'type' => StockMovementType::Receipt->value,
            'purpose' => 'receipt',
            'quantity' => '5000',
            'reference' => 'GRN-000001',
            'movement_date' => '2026-07-23 04:44:00',
            'created_at' => '2026-07-23 04:44:00',
            'updated_at' => '2026-07-23 04:44:00',
        ]);

        $this->artisan("inventory:remove-wip-demo-rows --write --ids={$demo},{$real}")
            ->expectsOutputToContain('not a candidate')
            ->assertFailed();

        // NOTHING removed — not even the id that was a genuine candidate.
        $this->assertNotNull(DB::table('stock_movements')->find($demo));
        $this->assertNotNull(DB::table('stock_movements')->find($real));
    }

    public function test_a_row_a_goods_receipt_line_points_at_is_refused(): void
    {
        $demo = $this->demoRow($this->resin, '860', 'QC release for lot A');

        $this->attachToAGoodsReceiptLine($demo);

        $this->artisan("inventory:remove-wip-demo-rows --write --ids={$demo}")
            ->expectsOutputToContain('referenced')
            ->assertFailed();

        $this->assertNotNull(DB::table('stock_movements')->find($demo));
    }

    public function test_one_leg_of_a_transfer_pair_is_refused_unless_its_partner_is_named_too(): void
    {
        $group = (string) Str::uuid();

        $out = DB::table('stock_movements')->insertGetId([
            'item_id' => $this->label->id,
            'warehouse_id' => $this->store->id,
            'type' => StockMovementType::TransferOut->value,
            'purpose' => null,
            'quantity' => '500',
            'reference' => 'Issue to Line 9',
            'transfer_group' => $group,
            'movement_date' => '2026-07-23 04:44:00',
            'created_at' => '2026-07-23 04:44:00',
            'updated_at' => '2026-07-23 04:44:00',
        ]);

        $in = DB::table('stock_movements')->insertGetId([
            'item_id' => $this->label->id,
            'warehouse_id' => $this->wip->id,
            'type' => StockMovementType::TransferIn->value,
            'purpose' => null,
            'quantity' => '500',
            'reference' => 'Issue to Line 9',
            'transfer_group' => $group,
            'movement_date' => '2026-07-23 04:44:00',
            'created_at' => '2026-07-23 04:44:00',
            'updated_at' => '2026-07-23 04:44:00',
        ]);

        // Only the WIP leg is a candidate (the other is in the Store), so
        // naming it alone must refuse rather than conjure 500 labels.
        $this->artisan("inventory:remove-wip-demo-rows --write --ids={$in}")
            ->expectsOutputToContain('transfer partner not named')
            ->assertFailed();

        $this->assertNotNull(DB::table('stock_movements')->find($in));
        $this->assertNotNull(DB::table('stock_movements')->find($out));
    }

    private function balance(Item $item): string
    {
        return bcadd((string) StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $this->wip->id)
            ->value('quantity'), '0', 4);
    }

    /**
     * Point a goods-receipt line at a movement. The whole purchase chain has
     * to exist for the foreign keys to hold, which is the point: this is what
     * a REAL ledger row looks like, and it is exactly what must never be
     * deleted out from under a receipt.
     */
    private function attachToAGoodsReceiptLine(int $movementId): void
    {
        $vendor = DB::table('vendors')->insertGetId([
            'code' => 'V-1', 'name' => 'A Supplier', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $po = DB::table('purchase_orders')->insertGetId([
            'vendor_id' => $vendor,
            'status' => 'sent',
            'order_date' => '2026-07-20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poLine = DB::table('purchase_order_lines')->insertGetId([
            'purchase_order_id' => $po,
            'item_id' => $this->resin->id,
            'quantity' => '860',
            'unit_price' => '1.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grn = DB::table('goods_receipt_notes')->insertGetId([
            'purchase_order_id' => $po,
            'warehouse_id' => $this->wip->id,
            'received_date' => '2026-07-23 04:44:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('goods_receipt_note_lines')->insert([
            'goods_receipt_note_id' => $grn,
            'purchase_order_line_id' => $poLine,
            'item_id' => $this->resin->id,
            'stock_movement_id' => $movementId,
            'quantity' => '860',
            'unit_cost' => '1.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
