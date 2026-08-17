<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\TallySync\Exceptions\PurchaseOrderNotPostable;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\AgentTokenService;
use App\Modules\TallySync\Services\TallyLedgerMappingService;
use App\Modules\TallySync\Services\TallySyncService;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PHASE 8 ACCEPTANCE — CHAIN B, ACCOUNTING TRACEABILITY.
 *
 *   Purchase Order → Goods Receipt → material lot → stock movement (with a
 *   purpose) → balance → production consumption
 *   [PO → Tally staged only; the live write stays OFF — Q35(d)]
 *
 * This is the CHAIN walked once, end to end, through the real endpoints and
 * the real services, with the Phase 5 ledger invariant
 * (stock_balances == Σ signed stock_movements per (item, warehouse)) and the
 * read-only `inventory:check-ledger` guard asserted green after EVERY link —
 * not once at the end. Assertions are on the TRANSACTION MODEL: what rows
 * exist, what they point at, what they are worth. No screen is involved.
 *
 * SCOPE. Chain B only. Chain B2 (store → production material flow,
 * Phase 7.5) is NOT in this branch and is deliberately not stubbed here.
 *
 * NAMED FIXTURES (all synthetic — FC-06; no real vendor, rate, ledger, GSTIN
 * or Tally name appears in this file):
 *   vendor      ACC-VND-01  "Acceptance Vendor One"
 *               Tally ledger "Acceptance Vendor One (Sundry Creditor)"
 *   resin       ACC-RM-01   "ACC_RESIN"    Kgs
 *   product     ACC-FG-01   "ACC_PRODUCT"  Nos.
 *   store       ACC-STORE   "Acceptance Store"  (the one Tally godown)
 *   purchase ledger  "Acceptance Purchase Ledger"
 *   order       1000 kg of ACC_RESIN at 1.25 (a made-up rate)
 *   arrivals    ACC-GRN-1 400 kg in 16 × 25 kg bags · ACC-GRN-2 600 kg in 24
 *   batch       ACC_PRODUCT on MC-01, Morning shift, 2026-08-03
 *
 * NOT re-proven here (already pinned, by name — this file walks the chain,
 * it does not re-derive the unit contracts):
 *  - the over-receipt refusal, the receipt against a Draft/Cancelled/Closed
 *    order, and the untouched database behind each refusal —
 *    Procurement\PurchaseChainContractTest;
 *  - one lot + printable bags per GRN line, receipt_key replay, bad lot
 *    totals rolling back — AtomicGoodsReceiptTraceabilityTest;
 *  - a hand-inserted drift row → `inventory:check-ledger` exits 1 naming the
 *    (item, warehouse) — Inventory\CheckStockLedgerCommandTest;
 *  - the full PO → Tally staging contract (payload shape, every unmapped
 *    refusal, idempotence, withdrawal) —
 *    TallySync\PurchaseOrderTallyStagingTest;
 *  - the five post-receipt rate keys and their finance gate —
 *    StockRateVisibilityTest; the two Procurement list payloads —
 *    ProcurementRateVisibilityTest.
 *
 * THE OWNER GATE, STATED HONESTLY. `tally-sync.purchase_orders_enabled` is
 * OFF by default and phpunit.xml pins it off. With it off there is no staged
 * entry at all: `send()` records tally_staging {state:'disabled', reason
 * purchase_orders_disabled} and enqueues nothing, and the agent's own poll
 * offers no Purchase Order to carry. "Staged, never posted" is therefore
 * asserted in TWO legs below — the default leg (nothing enqueued, the order
 * still Sent, the link null) and a flag-ON leg flipped by config() INSIDE
 * the test body only, on the dev database, which writes exactly one queue
 * row and touches no stock, lot, journal or GRN table. Neither leg posts
 * anything to Tally: the cloud has no outbound Tally path — vouchers leave
 * only when the local agent collects them from GET /tally-sync/pending.
 */
class AccountingChainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every key name that carries a purchase rate or an amount anywhere in a
     * Procurement or Inventory payload — the procurement set
     * (ProcurementRateVisibilityTest), the lot register's own rate keys, the
     * post-receipt inventory set (StockRateVisibilityTest), and the generic
     * names a new nested block would most plausibly reintroduce.
     */
    private const RATE_KEYS = [
        'unit_price', 'unit_cost', 'receipt_rate_per_kg', 'current_rate_per_kg',
        'rate', 'rate_per_kg', 'amount', 'total_amount', 'average_cost',
        'material_cost',
    ];

    private const UNIT_PRICE = '1.25';

    private const VENDOR_LEDGER = 'Acceptance Vendor One (Sundry Creditor)';

    private const PURCHASE_LEDGER = 'Acceptance Purchase Ledger';

    /**
     * Every table the PO → Tally staging must leave untouched — the
     * DEC-20260812-002 (i) list, counted before and after.
     */
    private const UNTOUCHED_TABLES = [
        'stock_movements', 'stock_balances', 'material_lots', 'material_bags',
        'journal_entries', 'journal_entry_lines',
        'goods_receipt_notes', 'goods_receipt_note_lines',
    ];

    private Vendor $vendor;

    private Item $resin;

    private Warehouse $store;

    private ?string $agentToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);
        // The post-completion quality stage is pinned by its own test; here
        // the batch only has to consume what the chain received.
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->vendor = Vendor::create(['code' => 'ACC-VND-01', 'name' => 'Acceptance Vendor One']);
        Vendor::query()->whereKey($this->vendor->id)->update(['tally_ledger_name' => self::VENDOR_LEDGER]);
        $this->vendor->refresh();

        $this->resin = Item::create([
            'sku' => 'ACC-RM-01', 'name' => 'ACC_RESIN', 'uom' => 'Kgs',
            'is_active' => true, 'tally_stock_item_guid' => 'acc-guid-resin',
        ]);
        $this->store = Warehouse::create([
            'code' => 'ACC-STORE', 'name' => 'Acceptance Store',
            'is_active' => true, 'tally_guid' => 'acc-guid-store',
        ]);
    }

    // ---- actors ---------------------------------------------------------------

    /** @param list<string> $permissions */
    private function actingAsWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsTheWholeFactory(): User
    {
        return $this->actingAsWith([
            'procurement.view', 'procurement.manage',
            'inventory.view', 'inventory.manage',
            'quality.view', 'quality.manage',
            'production.view', 'production.manage',
        ]);
    }

    /** The local Tally agent, by its REAL token — the only collector there is. */
    private function asAgent(): static
    {
        if ($this->agentToken === null) {
            $this->agentToken = app(AgentTokenService::class)->issueToken('acceptance-factory-pc')['plainTextToken'];
        }

        // Forget the seated staff user, or the bearer token is never resolved.
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->agentToken);
    }

    // ---- the chain's steps ------------------------------------------------------

    /** @return array{0: int, 1: int} [order id, line id] */
    private function draftOrder(string $quantity = '1000'): array
    {
        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-01',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => $quantity, 'unit_price' => self::UNIT_PRICE]],
        ])->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Draft->value)
            ->json('data');

        return [$order['id'], $order['lines'][0]['id']];
    }

    /** @return array{0: int, 1: int} [order id, line id] */
    private function sentOrder(string $quantity = '1000'): array
    {
        [$orderId, $lineId] = $this->draftOrder($quantity);
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Sent->value);

        return [$orderId, $lineId];
    }

    /**
     * An arrival of `$quantity` kg in 25 kg bags (one lot), keyed for replay.
     *
     * @return array<string, mixed>
     */
    private function receiptPayload(int $orderId, int $lineId, string $quantity, string $key): array
    {
        return [
            'receipt_key' => $key,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-08-02 09:00:00',
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'quantity' => $quantity,
                'lots' => [[
                    'supplier_lot_no' => $key,
                    'bag_count' => (int) ((float) $quantity / 25),
                    'bag_weight_kg' => '25',
                ]],
            ]],
        ];
    }

    // ---- the invariant, computed independently of the command -------------------

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

    /**
     * THE LEDGER INVARIANT AT THIS LINK: stock_balances == Σ signed
     * stock_movements per (item, warehouse), computed here, AND
     * `inventory:check-ledger` — the read-only guard that runs on live —
     * agreeing with exit 0 while writing nothing.
     */
    private function assertLedgerGreen(string $link): void
    {
        $sums = $this->ledgerSums();
        $balances = StockBalance::query()->get()->keyBy(fn (StockBalance $b) => "{$b->item_id}@{$b->warehouse_id}");

        foreach ($sums as $key => $sum) {
            $this->assertTrue($balances->has($key), "{$link}: no balance row for {$key} though movements exist");
            $this->assertSame(0, bccomp($sum, (string) $balances[$key]->quantity, 4), "{$link}: {$key} ledger {$sum} vs balance {$balances[$key]->quantity}");
        }
        foreach ($balances as $key => $balance) {
            $this->assertSame(0, bccomp($sums[$key] ?? '0.0000', (string) $balance->quantity, 4), "{$link}: balance {$key} has {$balance->quantity} but the ledger sums to ".($sums[$key] ?? '0.0000'));
        }

        $movementsBefore = StockMovement::query()->count();
        $balancesBefore = StockBalance::query()->count();
        $this->artisan('inventory:check-ledger')->assertExitCode(0)->run();
        $this->assertSame($movementsBefore, StockMovement::query()->count(), "{$link}: the ledger check must not write a movement");
        $this->assertSame($balancesBefore, StockBalance::query()->count(), "{$link}: the ledger check must not write a balance");
    }

    /** Every movement says WHY it exists — no null purpose anywhere. */
    private function assertEveryMovementHasAPurpose(string $link): void
    {
        $this->assertSame(0, StockMovement::query()->whereNull('purpose')->count(), "{$link}: a movement without a purpose exists");
    }

    private function balanceOf(Item $item): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $this->store->id)
            ->value('quantity') ?? '0.0000');
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (self::UNTOUCHED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // ---- FC-06 walk -------------------------------------------------------------

    /**
     * Every key path in the payload, at any depth, whose name is a rate key —
     * a walk, not a list of guessed paths, so a rate that reappears in a new
     * nested block fails here instead of slipping past.
     *
     * @return array<int, string>
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

    private function assertCarriesNoRate(?array $json, string $what): void
    {
        $this->assertSame([], $this->rateKeyPaths($json ?? []), "{$what} leaked a rate key");
        $this->assertStringNotContainsString(
            bcadd(self::UNIT_PRICE, '0', 4),
            json_encode($json, JSON_THROW_ON_ERROR),
            "{$what} carries the purchase rate as a value",
        );
    }

    // =========================================================================
    // LINK B1–B7 · the chain, walked once, the ledger green after every link
    // =========================================================================

    public function test_the_chain_from_purchase_order_to_production_consumption_keeps_the_ledger_green_at_every_link(): void
    {
        $this->actingAsTheWholeFactory();

        // Production fixture, so the chain has somewhere to end.
        $this->seed(CanonicalMachineSeeder::class);
        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $product = Item::create([
            'sku' => 'ACC-FG-01', 'name' => 'ACC_PRODUCT', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '10.0000', 'standard_cycle_time' => '10.00', 'standard_cavities' => 2,
            'nos_per_tray' => 100, 'trays_per_box' => 2, 'nos_per_box' => 200,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'acc-guid-product',
        ]);
        $bom = Bom::create(['item_id' => $product->id, 'name' => 'ACC recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0100']);
        // The common resin input IS the store under one accounting godown.
        app(FactoryDayBinService::class)->setWarehouseId($this->store->id);

        // ---- B1 · the purchase order --------------------------------------
        // An order is a promise: it books nothing and moves nothing.
        [$orderId, $lineId] = $this->draftOrder('1000');
        $this->assertSame(PurchaseOrderStatus::Draft, PurchaseOrder::query()->findOrFail($orderId)->status);
        $this->assertSame(0, StockMovement::query()->count(), 'B1: a Draft order moved stock');
        $this->assertSame(0, StockBalance::query()->count(), 'B1: a Draft order created a balance');
        $this->assertLedgerGreen('B1 after the order was drafted');

        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Sent->value);
        $this->assertSame(0, StockMovement::query()->count(), 'B1: sending an order moved stock');
        $this->assertLedgerGreen('B1 after the order was sent');

        // ---- B2 · the goods receipt ---------------------------------------
        // ACC-GRN-1: 400 of the 1000 ordered → PartiallyReceived.
        $grn1 = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'ACC-GRN-1'))
            ->assertSuccessful()
            ->assertJsonPath('data.purchase_order_id', $orderId)
            ->assertJsonPath('data.lines.0.quantity', '400.0000')
            ->json('data');

        $order = PurchaseOrder::query()->with('lines')->findOrFail($orderId);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->status, 'B2: a partial arrival did not move the order');
        $this->assertSame('400.0000', (string) $order->lines->first()->quantity_received);
        $this->assertLedgerGreen('B2 after ACC-GRN-1');

        // ---- B3 · the material lot ----------------------------------------
        // One lot per GRN line, carrying exactly that line's quantity, in bags
        // whose weights add up to it — the physical identity of the arrival.
        $grnLine1 = GoodsReceiptNoteLine::query()->findOrFail($grn1['lines'][0]['id']);
        $lot1 = MaterialLot::query()->with('bags')->where('goods_receipt_note_line_id', $grnLine1->id)->sole();
        $this->assertSame($grn1['id'], $lot1->grn_id, 'B3: the lot points at another GRN');
        $this->assertSame($this->resin->id, $lot1->item_id);
        $this->assertSame(0, bccomp('400', (string) $lot1->total_received_kg, 4), 'B3: the lot total is not the GRN line quantity');
        $this->assertCount(16, $lot1->bags, 'B3: 400 kg in 25 kg bags is 16 bags');
        $bagTotal = $lot1->bags->reduce(fn (string $carry, MaterialBag $bag) => bcadd($carry, (string) $bag->original_kg, 4), '0.0000');
        $this->assertSame(0, bccomp('400', $bagTotal, 4), 'B3: the bags do not sum to the lot');
        // Bags are born waiting for QC — an arrival is not yet usable stock.
        $this->assertSame([MaterialBagStatus::WaitingQc], MaterialBag::query()->pluck('status')->unique()->values()->all());
        $this->assertLedgerGreen('B3 after the lot was raised');

        // ---- B4 · the stock movement, with its purpose ---------------------
        // ONE Receipt movement per GRN line, NAMED on the line (not matched by
        // a shared reference string), and it says why it exists.
        $receipt1 = StockMovement::query()->sole();
        $this->assertSame($receipt1->id, $grnLine1->fresh()->stock_movement_id, 'B4: the GRN line does not name its ledger row');
        $this->assertSame(StockMovementType::Receipt, $receipt1->type);
        $this->assertSame(StockMovementPurpose::Receipt, $receipt1->purpose, 'B4: the arrival movement does not say Receipt');
        $this->assertSame($this->resin->id, $receipt1->item_id);
        $this->assertSame($this->store->id, $receipt1->warehouse_id);
        $this->assertSame(0, bccomp('400', (string) $receipt1->quantity, 4));
        $this->assertEveryMovementHasAPurpose('B4 after ACC-GRN-1');
        $this->assertLedgerGreen('B4 after the receipt movement');

        // ---- B5 · the balance ----------------------------------------------
        $this->assertSame(0, bccomp('400', $this->balanceOf($this->resin), 4), 'B5: the balance is not what arrived');

        // The remainder arrives: ACC-GRN-2, 600 kg → the order Closes, the
        // ledger is still the sum of its movements.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '600', 'ACC-GRN-2'))
            ->assertSuccessful()
            ->assertJsonPath('data.lines.0.quantity', '600.0000');

        $order = PurchaseOrder::query()->with('lines')->findOrFail($orderId);
        $this->assertSame(PurchaseOrderStatus::Closed, $order->status);
        $this->assertSame('1000.0000', (string) $order->lines->first()->quantity_received);
        $this->assertSame(2, GoodsReceiptNote::query()->count());
        $this->assertSame(2, MaterialLot::query()->count(), 'B3: lots != GRN lines');
        $this->assertSame(40, MaterialBag::query()->count(), '16 + 24 bags');
        $this->assertSame(['400.0000', '600.0000'], StockMovement::query()->orderBy('id')->pluck('quantity')->map(fn ($q) => (string) $q)->all());
        $this->assertSame(0, bccomp('1000', $this->balanceOf($this->resin), 4), 'B5: the balance is not the whole arrival');
        $this->assertEveryMovementHasAPurpose('B5 after ACC-GRN-2');
        $this->assertLedgerGreen('B5 after the order closed on its balance');

        // ---- QC release: the arrival becomes usable, and moves no stock ----
        foreach (GoodsReceiptNoteLine::query()->orderBy('id')->get() as $line) {
            $this->postJson('/api/v1/quality/incoming-inspections', [
                'goods_receipt_note_line_id' => $line->id,
                'inspected_quantity' => (string) $line->quantity,
                'accepted_quantity' => (string) $line->quantity,
                'rejected_quantity' => '0',
                'inspection_date' => '2026-08-02',
            ])->assertSuccessful();
        }
        $this->assertSame([MaterialBagStatus::InStore], MaterialBag::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(2, StockMovement::query()->count(), 'a full acceptance moves no stock');
        $this->assertLedgerGreen('after the QC release');

        // A bag is poured into the COMMON input: no machine, no batch (FC-01),
        // and a pour is not a stock movement — company stock is untouched.
        $bag = MaterialBag::query()->orderBy('id')->firstOrFail();
        $this->postJson('/api/v1/production/day-bin/load-bag', ['barcode' => $bag->barcode])->assertSuccessful();
        $pour = DayBinMovement::query()->sole();
        $this->assertNull($pour->work_center_id, 'FC-01: a bag belongs to no machine');
        $this->assertNull($pour->shift_production_entry_id, 'FC-01: a bag belongs to no batch');
        $this->assertSame(2, StockMovement::query()->count(), 'a scan is not a stock movement');
        $this->assertSame(0, bccomp('1000', $this->balanceOf($this->resin), 4));
        $this->assertLedgerGreen('after the pour into the common input');

        // ---- B6 · production consumption ------------------------------------
        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $product->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-08-03',
        ])->assertOk()->json('data.id');
        $this->assertSame(2, StockMovement::query()->count(), 'B6: starting a batch moved stock');
        $this->assertLedgerGreen('B6 after the batch started');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '400',
            'running_hours' => '1',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->store->id, 'quantity_issued_kg' => '4.5'],
            ],
        ])->assertOk();

        // ONE Issue movement for the received item, saying Consumption, naming
        // the entry that consumed it — the received material's ledger end.
        $consumption = StockMovement::query()->where('type', StockMovementType::Issue)->sole();
        $this->assertSame($this->resin->id, $consumption->item_id, 'B6: the batch consumed another item');
        $this->assertSame($this->store->id, $consumption->warehouse_id);
        $this->assertSame(StockMovementPurpose::Consumption, $consumption->purpose, 'B6: the consumption does not say Consumption');
        $this->assertSame("SPE #{$entryId}", $consumption->reference, 'B6: the consumption does not name its entry');
        $this->assertSame(0, bccomp('4.5', (string) $consumption->quantity, 4));

        // and the entry itself records what it consumed, of what, from where.
        $recorded = ShiftProductionEntry::query()->with('materialConsumptions')->findOrFail($entryId)->materialConsumptions->sole();
        $this->assertSame($this->resin->id, $recorded->item_id);
        $this->assertSame($this->store->id, $recorded->warehouse_id);
        $this->assertSame(0, bccomp('4.5', (string) $recorded->quantity_issued_kg, 4));

        // ---- B7 · the balance after consumption, ledger still green --------
        $this->assertSame(0, bccomp('995.5', $this->balanceOf($this->resin), 4), 'B7: 1000 received − 4.5 consumed');
        $output = StockMovement::query()->where('item_id', $product->id)->sole();
        $this->assertSame(StockMovementPurpose::Output, $output->purpose);
        $this->assertSame(4, StockMovement::query()->count(), 'two receipts + one consumption + one output, nothing else');
        $this->assertEveryMovementHasAPurpose('B7 after the batch completed');
        $this->assertLedgerGreen('B7 after the batch completed');

        // The whole chain, traced backwards from the ledger row: every receipt
        // movement is named by a GRN line, whose GRN is on the order.
        foreach (GoodsReceiptNoteLine::query()->orderBy('id')->get() as $line) {
            $this->assertNotNull($line->stock_movement_id, 'B7: a GRN line has no ledger row');
            $movement = StockMovement::query()->findOrFail($line->stock_movement_id);
            $this->assertSame(StockMovementPurpose::Receipt, $movement->purpose);
            $this->assertSame(0, bccomp((string) $line->quantity, (string) $movement->quantity, 4));
            $this->assertSame($orderId, GoodsReceiptNote::query()->findOrFail($line->goods_receipt_note_id)->purchase_order_id);
            $this->assertSame(1, MaterialLot::query()->where('goods_receipt_note_line_id', $line->id)->count());
        }
    }

    // =========================================================================
    // LINK B8 · PO → Tally: staged only, the live write OFF, no egress
    // =========================================================================

    /**
     * The owner gate, both legs.
     *
     * DEFAULT (the flag off, as phpunit.xml and config/tally-sync.php pin it):
     * the order is sent, NOTHING is enqueued, the order records
     * tally_staging {state: 'disabled', reason purchase_orders_disabled}, its
     * Tally link is null and the resource says so — and the local agent, the
     * only thing that can carry a voucher to Tally, is offered nothing.
     *
     * FLAG ON (config() inside this method only, on the DEV database): exactly
     * ONE tally_sync_entries row of voucher type 'Purchase Order', still
     * pending and uncollected, and every stock / lot / journal / GRN table
     * unchanged. That is what "staged" means — a queue row, not a post.
     *
     * NO LIVE WRITE PATH IS REACHABLE from either leg: the cloud never talks
     * to Tally. A voucher leaves only when the agent collects it from
     * GET /tally-sync/pending, so the poll is the egress, and it is asserted
     * here directly.
     */
    public function test_the_purchase_order_reaches_tally_only_as_a_staged_voucher_and_no_live_write_is_reachable(): void
    {
        $this->actingAsTheWholeFactory();
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Purchase->value => self::PURCHASE_LEDGER]);

        // ---- leg 1: the flag is off, which is the shipped state -------------
        $this->assertFalse(config('tally-sync.purchase_orders_enabled'), 'B8: the PO → Tally flag must ship OFF (Q35(d))');

        [$orderId, $lineId] = $this->sentOrder('1000');
        $order = PurchaseOrder::query()->findOrFail($orderId);

        $this->assertSame(PurchaseOrderStatus::Sent, $order->status, 'B8: Tally staging must never block the order itself');
        $this->assertSame(0, TallySyncEntry::query()->count(), 'B8: the flag is off — nothing may be enqueued');
        $this->assertSame(0, TallySyncEvent::query()->count());
        $this->assertSame('disabled', $order->tally_staging['state'] ?? null, 'B8: the order does not record the gate honestly');
        $this->assertSame(
            ['purchase_orders_disabled'],
            array_column($order->tally_staging['reasons'] ?? [], 'code'),
            'B8: the recorded reason is not the named owner gate',
        );

        $shown = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}")->assertSuccessful()->json('data');
        $this->assertNull($shown['tally'], 'B8: the order claims a Tally link it does not have');
        $this->assertSame('disabled', $shown['tally_staging']['state'], 'B8: the read does not state the gate');

        // A direct enqueue — the path a command or a forgetful caller would
        // take — refuses by the named code and writes nothing.
        try {
            app(TallySyncService::class)->enqueuePurchaseOrder($order);
            $this->fail('B8: the enqueue was not refused while the flag is off');
        } catch (PurchaseOrderNotPostable $refusal) {
            $this->assertContains('purchase_orders_disabled', $refusal->codes());
        }
        $this->assertSame(0, TallySyncEntry::query()->count());

        // THE EGRESS: the agent's own poll, by a real agent token — the ONE
        // way a voucher can leave for Tally (the cloud has no outbound Tally
        // path of its own).
        //
        // A CONTROL first, or "the agent was offered nothing" would prove
        // nothing: the same arrival the chain already makes puts a genuine,
        // collectable Receipt Note in the queue. So the poll IS answering,
        // and IS handing out this order's own paperwork — and still carries
        // no Purchase Order. That distinguishes "the gate held" from "the
        // queue happened to be empty".
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'ACC-B8-CONTROL'))
            ->assertSuccessful();
        $receiptNote = TallySyncEntry::query()->sole();
        $this->assertSame('Receipt Note', $receiptNote->tally_voucher_type, 'B8 control: the arrival did not enqueue its Receipt Note');

        $pending = $this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data');
        $this->assertSame([$receiptNote->id], array_column($pending, 'id'), 'B8 control: the poll did not hand out the collectable voucher');
        $this->assertSame(
            [],
            array_values(array_filter($pending, fn (array $row) => ($row['tally_voucher_type'] ?? null) === 'Purchase Order')),
            'B8: a Purchase Order was offered to the agent while the owner gate is shut',
        );
        $this->assertSame(
            [],
            TallySyncEntry::query()->where('syncable_type', (new PurchaseOrder)->getMorphClass())->pluck('id')->all(),
            'B8: a Purchase Order reached the queue while the flag is off',
        );

        // ---- leg 2: the flag flipped HERE ONLY, on the dev database ---------
        $this->app['auth']->forgetGuards();
        $this->actingAsTheWholeFactory();
        config(['tally-sync.purchase_orders_enabled' => true]);

        $before = $this->tableCounts();
        [$stagedOrderId] = $this->sentOrder('500');

        $entry = TallySyncEntry::query()->where('syncable_type', (new PurchaseOrder)->getMorphClass())->sole();
        $this->assertSame('Purchase Order', $entry->tally_voucher_type, 'B8: the staged voucher is not an ORDER voucher');
        $this->assertSame((new PurchaseOrder)->getMorphClass(), $entry->syncable_type);
        $this->assertSame($stagedOrderId, (int) $entry->syncable_id);
        $this->assertSame(TallySyncStatus::Pending, $entry->status, 'B8: a staged voucher is pending, never posted');
        $this->assertNull($entry->synced_at, 'B8: nothing was posted to Tally');
        $this->assertSame(
            'enqueued',
            PurchaseOrder::query()->findOrFail($stagedOrderId)->tally_staging['state'] ?? null,
        );

        // ONE queue row and NOTHING else — no stock, no lot, no journal, no GRN.
        $this->assertSame($before, $this->tableCounts(), 'B8: staging a PO touched a stock, lot, journal or GRN table');
        $this->assertSame(1, TallySyncEntry::query()->where('syncable_type', (new PurchaseOrder)->getMorphClass())->count(), 'B8: one order, one staged voucher');

        // Staged is not sent: the row sits in the queue until the agent, on
        // the factory PC, collects it. Even then the ERP has said nothing to
        // Tally — collection is a read.
        $offered = $this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data');
        $this->assertContains($entry->id, array_column($offered, 'id'), 'B8: the staged order was not handed to the agent');
        $this->assertSame(TallySyncStatus::Pending, $entry->fresh()->status, 'B8: a poll is a read; it does not post');
        $this->assertNull($entry->fresh()->synced_at);
    }

    // =========================================================================
    // LINK B9 · FC-06 across every link of the chain
    // =========================================================================

    /**
     * A PROCUREMENT-ONLY login walks the chain it is allowed to walk and never
     * sees a rate — the shape is ABSENCE (assertArrayNotHasKey), not a null.
     * Past the procurement boundary the same reader is REFUSED outright (403):
     * the lot register, the ledger, the balances and the lot's cost history
     * are not its data at all, which is the stronger form of "never sees a
     * rate". Both halves are asserted, because asserting "no rate leaked" on
     * an endpoint that 403s would pass trivially and prove nothing.
     */
    public function test_a_procurement_only_reader_sees_no_rate_on_any_link_of_the_chain(): void
    {
        $this->actingAsWith(['procurement.view', 'procurement.manage']);

        $created = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-01',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '1000', 'unit_price' => self::UNIT_PRICE]],
        ])->assertSuccessful()->json();
        $this->assertCarriesNoRate($created, 'B9 purchase-orders store');
        $this->assertSame('1000.0000', $created['data']['lines'][0]['quantity'], 'the quantity is still served');
        $this->assertArrayNotHasKey('unit_price', $created['data']['lines'][0], 'ABSENT, not null');
        $orderId = $created['data']['id'];
        $lineId = $created['data']['lines'][0]['id'];

        $this->assertCarriesNoRate(
            $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertSuccessful()->json(),
            'B9 purchase-orders send',
        );

        $grn = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'ACC-FC06-1'))
            ->assertSuccessful()->json();
        $this->assertCarriesNoRate($grn, 'B9 goods-receipts store');
        $this->assertArrayNotHasKey('unit_cost', $grn['data']['lines'][0], 'ABSENT, not null');
        $grnId = $grn['data']['id'];

        // Every procurement read on the chain, walked — a read that stops
        // answering fails here instead of being stepped over.
        $served = [
            '/api/v1/procurement/purchase-orders',
            "/api/v1/procurement/purchase-orders/{$orderId}",
            "/api/v1/procurement/purchase-orders/{$orderId}/trace",
            '/api/v1/procurement/goods-receipts',
            "/api/v1/procurement/goods-receipts/{$grnId}",
        ];
        foreach ($served as $url) {
            $payload = $this->getJson($url)->assertSuccessful()->json();
            // A payload that answered with nothing would satisfy the rate walk
            // trivially — so each read must still be SAYING something.
            $this->assertNotEmpty($payload['data'] ?? null, "B9 {$url} answered with nothing");
            $this->assertCarriesNoRate($payload, "B9 {$url}");
        }

        // The trace in particular: it must still show this order's arrival
        // (quantities served, rates gone), not an empty envelope.
        $trace = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}/trace")->assertSuccessful()->json('data');
        $this->assertStringContainsString('400', json_encode($trace, JSON_THROW_ON_ERROR), 'B9: the trace no longer shows the arrival it is meant to trace');

        // Past the procurement boundary: refused, not redacted.
        $lotId = MaterialLot::query()->sole()->id;
        $refused = [
            '/api/v1/inventory/material-lots',
            "/api/v1/inventory/material-lots/{$lotId}",
            '/api/v1/inventory/material-bags',
            '/api/v1/inventory/stock-movements',
            '/api/v1/inventory/stock-balances',
            "/api/v1/inventory/material-lots/{$lotId}/cost-versions",
        ];
        foreach ($refused as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    /**
     * The other half of FC-06 on this chain: the reader who IS allowed the
     * inventory links — the store — still sees no purchase rate there, because
     * a rate is Owner and Accounts territory (FC-06). unit_cost /
     * average_cost / the lot's rate keys are OMITTED for a reader without
     * finance, and the quantities are served whole.
     */
    public function test_the_inventory_links_of_the_chain_carry_no_rate_for_a_reader_without_finance(): void
    {
        // Build the chain's arrival with a login that may.
        $this->actingAsTheWholeFactory();
        [$orderId, $lineId] = $this->sentOrder('1000');
        $grn = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'ACC-FC06-2'))
            ->assertSuccessful()->json('data');
        $lotId = MaterialLot::query()->sole()->id;

        // Now the store reads its own data: inventory, no finance.
        $this->app['auth']->forgetGuards();
        $this->actingAsWith(['inventory.view']);

        $links = [
            '/api/v1/inventory/material-lots',
            "/api/v1/inventory/material-lots/{$lotId}",
            '/api/v1/inventory/material-bags',
            '/api/v1/inventory/stock-movements',
            '/api/v1/inventory/stock-balances',
        ];
        foreach ($links as $url) {
            $this->assertCarriesNoRate($this->getJson($url)->assertSuccessful()->json(), "B9 {$url}");
        }

        // The quantity side is untouched by the gate — the store still sees
        // what it holds.
        $balances = $this->getJson('/api/v1/inventory/stock-balances')->assertSuccessful()->json('data');
        $resin = collect($balances)->first(fn (array $row) => ($row['item']['id'] ?? null) === $this->resin->id);
        $this->assertNotNull($resin, 'B9: the balance vanished with the rate');
        $this->assertSame(0, bccomp('400', (string) $resin['quantity'], 4));
        $this->assertArrayNotHasKey('average_cost', $resin, 'B9: ABSENT, not null');

        // The lot's COST history is a finance route: refused outright here.
        $this->getJson("/api/v1/inventory/material-lots/{$lotId}/cost-versions")->assertForbidden();

        // and the arrival itself still carries its quantities.
        $this->assertSame(0, bccomp('400', (string) $grn['lines'][0]['quantity'], 4));
    }
}
