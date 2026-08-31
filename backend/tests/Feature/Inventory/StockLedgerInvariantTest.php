<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Exceptions\StockLedgerAppendOnlyException;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * THE LEDGER INVARIANT (Phase 5, P5-05): for every (item, warehouse),
 * stock_balances.quantity == Σ signed stock_movements.quantity — receipts and
 * transfers-in add, issues and transfers-out subtract. stock_movements is
 * the fact; stock_balances is a running total derived from it and must
 * never drift.
 *
 * Proven on the REAL paths, not on the service in isolation: a purchase
 * received through the GRN endpoint, resin issued to a batch and finished
 * goods received by the batch's completion, and finished goods dispatched
 * on a delivery — the invariant asserted after each, once by hand and once
 * by the read-only `inventory:check-ledger` command that guards it on live.
 * Each of those four movements also carries the PURPOSE its writer named
 * (receipt / consumption / output / dispatch), which is what lets a report
 * say why stock moved without parsing a reference string.
 *
 * And the other half of "append-only": a recorded movement refuses to be
 * updated or deleted. Corrections are new movements, always.
 */
class StockLedgerInvariantTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private Item $resin;

    private Item $bottle;

    private Warehouse $rm;

    private Warehouse $fg;

    private Shift $shift;

    private WorkCenter $machine;

    private PurchaseOrder $order;

    private PurchaseOrderLine $orderLine;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // The quality stage sits after completion and is pinned elsewhere;
        // here the batch only has to consume and produce.
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->seed(CanonicalMachineSeeder::class);
        $this->machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);

        $this->resin = Item::create(['sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.', 'is_active' => true, 'tally_stock_item_guid' => 'g1']);
        // Fully specified so the readiness gate has nothing to refuse.
        $this->bottle = Item::create([
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g3',
        ]);
        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);

        $vendor = Vendor::create(['code' => 'SUP-1', 'name' => 'Resin Supplier']);
        $this->order = PurchaseOrder::create(['vendor_id' => $vendor->id, 'status' => PurchaseOrderStatus::Sent, 'order_date' => '2026-08-01']);
        $this->orderLine = $this->order->lines()->create(['item_id' => $this->resin->id, 'quantity' => '1000', 'unit_price' => '90', 'quantity_received' => '0']);

        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.manage', 'inventory.view', 'inventory.manage', 'production.view', 'production.manage', 'sales.view', 'sales.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    // ---- the invariant, computed independently of the command ---------------

    /** @return array<string, string> "item@warehouse" => signed sum */
    private function ledgerSums(): array
    {
        $sums = [];
        foreach (StockMovement::query()->orderBy('id')->get() as $movement) {
            $key = "{$movement->item_id}@{$movement->warehouse_id}";
            $signed = match ($movement->type) {
                StockMovementType::Receipt, StockMovementType::TransferIn => (string) $movement->quantity,
                StockMovementType::Issue, StockMovementType::TransferOut => bcmul((string) $movement->quantity, '-1', 4),
            };
            $sums[$key] = bcadd($sums[$key] ?? '0.0000', $signed, 4);
        }

        return $sums;
    }

    private function assertLedgerMatchesBalances(string $step): void
    {
        $sums = $this->ledgerSums();
        $balances = StockBalance::query()->get()->keyBy(fn (StockBalance $b) => "{$b->item_id}@{$b->warehouse_id}");

        foreach ($sums as $key => $sum) {
            $this->assertTrue($balances->has($key), "{$step}: no balance row for {$key} though movements exist");
            $this->assertSame(0, bccomp($sum, (string) $balances[$key]->quantity, 4), "{$step}: {$key} ledger {$sum} vs balance {$balances[$key]->quantity}");
        }
        foreach ($balances as $key => $balance) {
            $this->assertSame(0, bccomp($sums[$key] ?? '0.0000', (string) $balance->quantity, 4), "{$step}: balance {$key} has {$balance->quantity} but the ledger sums to ".($sums[$key] ?? '0.0000'));
        }

        // And the guard that runs on live agrees, and changes nothing.
        $before = StockMovement::query()->count();
        $this->artisan('inventory:check-ledger')->assertExitCode(0)->run();
        $this->assertSame($before, StockMovement::query()->count(), "{$step}: the check must not write");
    }

    // ---- the real paths ------------------------------------------------------

    private function receivePurchase(): void
    {
        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'receipt-20260801-001',
            'purchase_order_id' => $this->order->id,
            'warehouse_id' => $this->rm->id,
            'received_date' => '2026-08-01',
            'lines' => [['purchase_order_line_id' => $this->orderLine->id, 'quantity' => '1000']],
        ])->assertSuccessful();
    }

    private function runBatch(): int
    {
        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-02',
        ])->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '118.998'],
            ],
        ])->assertOk();

        return $entryId;
    }

    private function deliver(string $quantity): void
    {
        $orderId = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $this->customer->id,
            'order_date' => '2026-08-03',
            'expected_date' => '2026-08-10',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => $quantity, 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');
        $this->postJson("/api/v1/sales/sales-orders/{$orderId}/confirm")->assertSuccessful();
        $order = SalesOrder::query()->with('lines')->findOrFail($orderId);

        // Dispatch is gated on Quality's internal sign-off (DEC-20260831-003).
        // This file's subject is the ledger invariant, not the gate, so Quality
        // is assumed to have signed the whole ordered quantity off — the gate
        // itself is DispatchQualityGateTest's subject.
        $this->approveQualityForOrder($order->id);

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-04',
            'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => $quantity]],
        ])->assertSuccessful();
    }

    public function test_the_ledger_and_the_balances_agree_after_a_receipt_a_batch_and_a_delivery_and_each_movement_says_why(): void
    {
        // Empty ledger, empty balances: trivially in balance.
        $this->assertLedgerMatchesBalances('empty');

        // 1. GRN → 1000 kg resin into the RM store.
        $this->receivePurchase();
        $grn = StockMovement::query()->sole();
        $this->assertSame(StockMovementType::Receipt, $grn->type);
        $this->assertSame(StockMovementPurpose::Receipt, $grn->purpose);
        $this->assertSame("GRN for PO #{$this->order->id}", $grn->reference);
        $this->assertSame('1000.0000', (string) $grn->quantity);
        $this->assertLedgerMatchesBalances('after GRN');

        // 2. Batch: 118.998 kg resin OUT of the RM store, 8100 bottles INTO FG.
        $entryId = $this->runBatch();
        $consumption = StockMovement::query()->where('type', StockMovementType::Issue)->sole();
        $this->assertSame($this->resin->id, $consumption->item_id);
        $this->assertSame($this->rm->id, $consumption->warehouse_id);
        $this->assertSame(StockMovementPurpose::Consumption, $consumption->purpose);
        $this->assertSame("SPE #{$entryId}", $consumption->reference);
        $this->assertSame('118.9980', (string) $consumption->quantity);

        $output = StockMovement::query()->where('type', StockMovementType::Receipt)->where('item_id', $this->bottle->id)->sole();
        $this->assertSame($this->fg->id, $output->warehouse_id);
        $this->assertSame(StockMovementPurpose::Output, $output->purpose);
        $this->assertSame("SPE #{$entryId}", $output->reference);
        $this->assertSame('8100.0000', (string) $output->quantity);
        $this->assertLedgerMatchesBalances('after batch');

        $this->assertSame('881.0020', (string) StockBalance::query()->where('item_id', $this->resin->id)->where('warehouse_id', $this->rm->id)->sole()->quantity);
        $this->assertSame('8100.0000', (string) StockBalance::query()->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->sole()->quantity);

        // 3. Delivery: 3000 bottles OUT of FG.
        $this->deliver('3000');
        $dispatch = StockMovement::query()->where('type', StockMovementType::Issue)->where('item_id', $this->bottle->id)->sole();
        $this->assertSame($this->fg->id, $dispatch->warehouse_id);
        $this->assertSame(StockMovementPurpose::Dispatch, $dispatch->purpose);
        $this->assertStringStartsWith('Delivery for SO #', (string) $dispatch->reference);
        $this->assertSame('3000.0000', (string) $dispatch->quantity);
        $this->assertLedgerMatchesBalances('after delivery');

        $this->assertSame('5100.0000', (string) StockBalance::query()->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->sole()->quantity);

        // Four movements, four purposes, nothing unknown and nothing null.
        $this->assertSame(4, StockMovement::query()->count());
        $this->assertSame(0, StockMovement::query()->whereNull('purpose')->count());
        $this->assertSame(0, StockMovement::query()->where('purpose', StockMovementPurpose::Unknown->value)->count());
    }

    public function test_a_recorded_movement_refuses_to_be_updated_or_deleted(): void
    {
        $movement = app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->rm->id, quantity: '100', unitCost: '90', reference: 'seed',
        );

        try {
            $movement->update(['quantity' => '999']);
            $this->fail('An update to a stock movement must be refused.');
        } catch (StockLedgerAppendOnlyException $e) {
            $this->assertStringContainsString("stock movement #{$movement->id}", $e->getMessage());
        }
        $this->assertSame('100.0000', (string) $movement->fresh()->quantity);

        try {
            $movement->fresh()->delete();
            $this->fail('A delete of a stock movement must be refused.');
        } catch (StockLedgerAppendOnlyException) {
        }
        $this->assertNotNull(StockMovement::query()->find($movement->id));

        // The correction path is a NEW movement — that still works.
        app(StockMovementService::class)->recordIssue(itemId: $this->resin->id, warehouseId: $this->rm->id, quantity: '100', reference: 'seed reversed');
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertLedgerMatchesBalances('after reversal');
    }
}
