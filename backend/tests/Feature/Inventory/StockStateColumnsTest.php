<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE FOUR FIGURES a storekeeper needs on a stock row: what is there, what
 * quality is holding, what a customer is owed, and what may actually go out.
 *
 * The row used to carry one number. A storekeeper reading "500" could not
 * tell whether any of it was standing in incoming-QC hold or already promised
 * to an order, and the ERP would happily let them hand over stock that was
 * both.
 *
 * FREE_TO_ISSUE IS STRICTER THAN THE ENGINE, deliberately. The write path
 * (StockMovementService::decrementBalance) subtracts the QC hold and NOT
 * reservations, so the system permits issuing reserved stock. The owner ruled
 * on 31-Aug-2026 that the screen must subtract both: breaking a reservation
 * should be a decision, not an accident. These tests pin that gap open on
 * purpose — if someone later "fixes" the screen to match the engine, they
 * fail.
 */
class StockStateColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private User $storeKeeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'SS-STORE', 'name' => 'SS Store', 'is_active' => true]);
        $this->resin = Item::create([
            'sku' => 'SS-RESIN', 'name' => 'SS Resin', 'uom' => 'KGS',
            'is_active' => true, 'is_production_input' => true,
        ]);

        $this->storeKeeper = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $this->storeKeeper->givePermissionTo('inventory.view');
        Sanctum::actingAs($this->storeKeeper);
    }

    private function balance(string $quantity, ?Item $item = null): StockBalance
    {
        return StockBalance::create([
            'item_id' => ($item ?? $this->resin)->id,
            'warehouse_id' => $this->store->id,
            'quantity' => $quantity,
            'average_cost' => '10.0000',
        ]);
    }

    /** Bags exactly as a goods receipt makes them. */
    private function waitingBags(string $kgEach, int $count, ?int $warehouseId, ?Item $item = null): void
    {
        $lot = MaterialLot::create([
            'item_id' => ($item ?? $this->resin)->id,
            'received_date' => '2026-08-20',
            'bag_count' => $count,
            'bag_weight_kg' => $kgEach,
            'total_received_kg' => bcmul($kgEach, (string) $count, 4),
        ]);

        for ($seq = 1; $seq <= $count; $seq++) {
            $lot->bags()->create([
                'barcode' => 'SS-'.$lot->id.'-'.$seq.'-'.($warehouseId ?? 'null'),
                'original_kg' => $kgEach,
                'remaining_kg' => $kgEach,
                'status' => MaterialBagStatus::WaitingQc,
                'current_warehouse_id' => $warehouseId,
            ]);
        }
    }

    private function reserve(string $quantity): void
    {
        $customer = Customer::create(['code' => 'SS-CUST-1', 'name' => 'SS Customer', 'is_active' => true]);
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'order_number' => 'SS-SO-1',
            'order_date' => '2026-08-20',
            'status' => 'confirmed',
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'item_id' => $this->resin->id,
            'quantity' => $quantity,
            'unit_price' => '1.0000',
        ]);

        StockReservation::create([
            'sales_order_line_id' => $line->id,
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => $quantity,
            'consumed_quantity' => '0.0000',
            'released_quantity' => '0.0000',
            'status' => 'active',
        ]);
    }

    private function state(): array
    {
        return $this->getJson('/api/v1/inventory/stock-balances')->assertOk()->json('data.0.state');
    }

    public function test_a_plain_row_reports_everything_free(): void
    {
        $this->balance('500.0000');

        $this->assertSame([
            'on_hand' => '500.0000',
            'qa_hold' => '0.0000',
            'reserved' => '0.0000',
            'free_to_issue' => '500.0000',
        ], $this->state());
    }

    public function test_material_waiting_for_incoming_qc_is_not_free_to_issue(): void
    {
        $this->balance('500.0000');
        $this->waitingBags('25.0000', 4, $this->store->id);   // 100 kg held

        $state = $this->state();

        $this->assertSame('100.0000', $state['qa_hold']);
        $this->assertSame('400.0000', $state['free_to_issue']);
    }

    /**
     * THE ONE THE SCREEN EXISTS FOR. The engine would let this go out; the
     * screen must not say it may.
     */
    public function test_stock_promised_to_a_customer_is_not_free_to_issue(): void
    {
        $this->balance('500.0000');
        $this->reserve('120.0000');

        $state = $this->state();

        $this->assertSame('120.0000', $state['reserved']);
        $this->assertSame('380.0000', $state['free_to_issue'], 'the screen must subtract customer holds even though the write path does not');
    }

    public function test_both_claims_come_off_the_same_row(): void
    {
        $this->balance('500.0000');
        $this->waitingBags('25.0000', 4, $this->store->id);
        $this->reserve('120.0000');

        $this->assertSame([
            'on_hand' => '500.0000',
            'qa_hold' => '100.0000',
            'reserved' => '120.0000',
            'free_to_issue' => '280.0000',
        ], $this->state());
    }

    /**
     * THE TRAP. A bag with no store recorded counts against EVERY store,
     * because nothing says which one it is in. A batched GROUP BY would drop
     * those into a null bucket and report a SMALLER hold than the write path —
     * failing OPEN, in exactly the direction the hold exists to prevent.
     */
    public function test_a_bag_with_no_store_recorded_is_still_held_against_this_store(): void
    {
        $this->balance('500.0000');
        $this->waitingBags('30.0000', 2, null);   // 60 kg, nowhere recorded

        $state = $this->state();

        $this->assertSame('60.0000', $state['qa_hold'], 'a store-less bag must not fall out of the hold');
        $this->assertSame('440.0000', $state['free_to_issue']);
    }

    public function test_free_to_issue_never_goes_below_zero(): void
    {
        $this->balance('50.0000');
        $this->waitingBags('40.0000', 3, $this->store->id);   // 120 kg held against 50 on hand

        $state = $this->state();

        $this->assertSame('120.0000', $state['qa_hold'], 'the hold is reported honestly even when it exceeds the balance');
        $this->assertSame('0.0000', $state['free_to_issue'], 'never negative — the honest answer is that none may go');
    }

    public function test_no_rate_rides_along_for_a_reader_without_finance(): void
    {
        $this->balance('500.0000');

        $row = $this->getJson('/api/v1/inventory/stock-balances')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('average_cost', $row);
        foreach ($row['state'] as $key => $value) {
            $this->assertStringNotContainsString('cost', $key, 'no cost may reach the state block');
        }
    }

    /**
     * A page of rows must cost a fixed number of queries, not one pair at a
     * time — the stock list is hundreds of rows on the live instance.
     */
    public function test_a_page_of_rows_costs_a_fixed_number_of_queries(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $item = Item::create(['sku' => 'SS-ITEM-'.$i, 'name' => 'SS Item '.$i, 'uom' => 'KGS']);
            $this->balance('100.0000', $item);
            $this->waitingBags('10.0000', 1, $this->store->id, $item);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $rows = $this->getJson('/api/v1/inventory/stock-balances')->assertOk()->json('data');

        $this->assertCount(12, $rows);
        $this->assertSame('10.0000', $rows[0]['state']['qa_hold']);
        $this->assertLessThan(12, $queries, "a page of 12 rows ran {$queries} queries — the state block looks unbatched");
    }
}
