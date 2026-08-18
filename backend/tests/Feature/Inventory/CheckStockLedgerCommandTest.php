<?php

namespace Tests\Feature\Inventory;

use App\Console\Commands\CheckStockLedger;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * inventory:check-ledger — the READ-ONLY guard of the ledger invariant
 * (StockLedgerInvariantTest): Σ signed stock_movements per (item, warehouse)
 * against stock_balances.quantity. What these pin: a database in balance
 * says so and exits 0; every kind of drift — a balance edited away from its
 * movements, a balance row no movement explains, movements no balance row
 * carries — is listed in a table with the figures and exits 1; and the
 * database is byte-identical before and after, whichever way it went.
 * Nothing here repairs anything: what to do about drift on live is a
 * decision, not a side effect of a check.
 */
class CheckStockLedgerCommandTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $bottle;

    private Warehouse $rm;

    private Warehouse $fg;

    private StockMovementService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
        $this->stock = app(StockMovementService::class);
    }

    /** @return array<string, list<array<string, mixed>>> every row of every table */
    private function snapshot(): array
    {
        $tables = collect(Schema::getTableListing())
            ->reject(fn (string $table) => in_array($table, ['migrations'], true))
            ->sort()
            ->values();

        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn (array $a, array $b) => strcmp(json_encode($a), json_encode($b)));
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    private function seedABalancedLedger(): void
    {
        $this->stock->recordReceipt(itemId: $this->resin->id, warehouseId: $this->rm->id, quantity: '1000', unitCost: '90', reference: 'GRN for PO #1');
        $this->stock->recordIssue(itemId: $this->resin->id, warehouseId: $this->rm->id, quantity: '118.998', reference: 'SPE #1');
        $this->stock->recordTransfer(itemId: $this->resin->id, fromWarehouseId: $this->rm->id, toWarehouseId: $this->fg->id, quantity: '0.5', reference: 'move');
        $this->stock->recordReceipt(itemId: $this->bottle->id, warehouseId: $this->fg->id, quantity: '8100', unitCost: '0', reference: 'SPE #1');
        $this->stock->recordIssue(itemId: $this->bottle->id, warehouseId: $this->fg->id, quantity: '3000', reference: 'Delivery for SO #1');
    }

    public function test_a_ledger_in_balance_is_reported_clean_with_exit_code_zero_and_nothing_written(): void
    {
        $this->seedABalancedLedger();
        $before = $this->snapshot();

        $this->artisan('inventory:check-ledger')
            ->expectsOutputToContain('movements: 6')
            ->expectsOutputToContain('balance rows: 3')
            ->expectsOutputToContain(CheckStockLedger::VERDICT_CLEAN)
            ->assertExitCode(0)
            ->run();

        $this->assertSame($before, $this->snapshot(), 'The check must not write anything.');
    }

    public function test_an_empty_ledger_is_clean(): void
    {
        $this->artisan('inventory:check-ledger')
            ->expectsOutputToContain(CheckStockLedger::VERDICT_CLEAN)
            ->assertExitCode(0);
    }

    public function test_every_kind_of_drift_is_listed_with_its_figures_and_exits_one_without_writing(): void
    {
        $this->seedABalancedLedger();

        // (a) a balance edited away from its movements — 881.0020 − 0.5 = 880.5020 expected.
        DB::table('stock_balances')->where('item_id', $this->resin->id)->where('warehouse_id', $this->rm->id)->update(['quantity' => '900.0000']);
        // (b) a balance row no movement explains.
        DB::table('stock_balances')->insert(['item_id' => $this->bottle->id, 'warehouse_id' => $this->rm->id, 'quantity' => '12.0000', 'average_cost' => '0.0000', 'created_at' => now(), 'updated_at' => now()]);
        // (c) a movement no balance row carries.
        DB::table('stock_movements')->insert([
            'item_id' => $this->resin->id, 'warehouse_id' => $this->fg->id, 'type' => 'receipt', 'quantity' => '7.2500', 'unit_cost' => '0.0000',
            'reference' => 'stray', 'movement_date' => now(), 'purpose' => 'unknown', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->where('item_id', $this->resin->id)->where('warehouse_id', $this->fg->id)->delete();

        $before = $this->snapshot();

        $this->artisan('inventory:check-ledger')
            // (a) — item, warehouse, ledger sum, balance, drift = balance − ledger.
            ->expectsTable(
                ['item', 'warehouse', 'ledger sum', 'balance', 'drift'],
                [
                    ["#{$this->resin->id} RES-1 PET Resin", "#{$this->rm->id} RM RM Store", '880.5020', '900.0000', '+19.4980'],
                    ["#{$this->resin->id} RES-1 PET Resin", "#{$this->fg->id} FG FG Store", '7.7500', '(no row)', '-7.7500'],
                    ["#{$this->bottle->id} BTL-500 500ml PET Bottle", "#{$this->rm->id} RM RM Store", '0.0000', '12.0000', '+12.0000'],
                ],
            )
            ->expectsOutputToContain('VERDICT: DRIFT — 3 (item, warehouse) pair(s) disagree')
            ->assertExitCode(1)
            ->run();

        $this->assertSame($before, $this->snapshot(), 'Drift is reported, never repaired.');
    }
}
