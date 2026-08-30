<?php

namespace Tests\Feature\Procurement;

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
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE PURCHASE CHAIN AS ONE TESTED CONTRACT (Phase 6, P6-01).
 *
 *   PO create (Draft) → send (Sent) → GRN partial (PartiallyReceived) →
 *   GRN over the remainder (422, the line named, nothing written) → GRN of
 *   the exact remainder (Closed) → a further GRN refused (Closed) → the
 *   received material consumed by a batch (Consumption movement).
 *
 * Walked through the REAL services behind the REAL endpoints —
 * PurchaseOrderService, GoodsReceiptService, StockMovementService,
 * TraceabilityService, IncomingInspectionService, FactoryDayBinService,
 * ShiftProductionEntryService::completeBatch — with the real TallySync
 * listener live (no Event::fake), so every Receipt Note the chain enqueues
 * is counted here. After EVERY step the Phase 5 ledger invariant holds
 * (stock_balances == Σ signed stock_movements per (item, warehouse), and
 * `inventory:check-ledger` agrees with exit 0), every material lot carries
 * its GRN line's quantity, and every purchase movement says Receipt.
 *
 * NOT re-proven here (already pinned, by name):
 *  - one lot + printable bags per GRN line, replay of the same receipt_key,
 *    key reuse with different data, bad lot totals rolling back —
 *    AtomicGoodsReceiptTraceabilityTest;
 *  - schedule allocation (oldest due first, edited allocations, per-window
 *    over-allocation refused) — PoScheduleArrivalTest;
 *  - the delivery leg (Dispatch) and append-only stock_movements —
 *    Inventory\StockLedgerInvariantTest (its batch leg is re-walked below
 *    only because here the resin the batch consumes is the resin the chain
 *    received);
 *  - a hand-inserted drift row → `inventory:check-ledger` exits 1 naming
 *    the (item, warehouse) — Inventory\CheckStockLedgerCommandTest
 *    (test_every_kind_of_drift_is_listed_with_its_figures_and_exits_one_without_writing);
 *  - the two Procurement list payloads for a procurement-only reader —
 *    ProcurementRateVisibilityTest (extended below to the whole chain).
 *
 * FC-06: every value below is synthetic ("Vendor Alpha", "ITEM_A", 1.25).
 * FC-01: the consumption test walks the CONFIRMED floor flow — bags wait
 * for QC, are released, are scanned into the COMMON input (no machine, no
 * batch), and the batch's consumption is CALCULATED at completion.
 */
class PurchaseChainContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every key name that carries a purchase rate or an amount anywhere in
     * a Procurement/Inventory payload — the two ProcurementRateVisibilityTest
     * walks for, plus the lot register's rate keys and the generic ones a
     * new nested block would most plausibly reintroduce.
     */
    private const RATE_KEYS = [
        'unit_price', 'unit_cost', 'receipt_rate_per_kg', 'current_rate_per_kg',
        'rate', 'rate_per_kg', 'amount', 'total_amount', 'average_cost',
    ];

    private const UNIT_PRICE = '1.25';

    private Vendor $vendor;

    private Item $resin;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);
        // The quality stage after completion is pinned elsewhere; here the
        // batch only has to consume what the chain received.
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha', 'tally_ledger_name' => 'Vendor Alpha']);
        $this->resin = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'guid-item-a']);
        $this->store = Warehouse::create(['code' => 'WH-A', 'name' => 'Warehouse A', 'is_active' => true, 'tally_guid' => 'guid-wh-a']);
    }

    // ---- actors -----------------------------------------------------------------

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

    private function actingAsProcurementOnly(): User
    {
        return $this->actingAsWith(['procurement.view', 'procurement.manage']);
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

    // ---- the chain's steps, each through its endpoint --------------------------

    /**
     * A Draft order for `$quantity` kg of ITEM_A through the store endpoint.
     *
     * @return array{0: int, 1: int} [order id, line id]
     */
    private function draftOrder(string $quantity = '1000'): array
    {
        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-01',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => $quantity, 'unit_price' => self::UNIT_PRICE]],
        ])->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Draft->value)
            ->assertJsonPath('data.source', 'erp')
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
     * A receipt of `$quantity` kg in 25 kg bags (one lot), keyed for replay.
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
                    'supplier_lot_no' => 'LOT-'.$key,
                    'bag_count' => (int) ((float) $quantity / 25),
                    'bag_weight_kg' => '25',
                ]],
            ]],
        ];
    }

    // ---- the invariant, computed independently of the command ------------------

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
     * stock_balances == Σ signed stock_movements per (item, warehouse), and
     * the read-only guard that runs on live agrees (exit 0) without writing.
     */
    private function assertLedgerInBalance(string $step): void
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

        $before = StockMovement::query()->count();
        $this->artisan('inventory:check-ledger')->assertExitCode(0)->run();
        $this->assertSame($before, StockMovement::query()->count(), "{$step}: the check must not write");
    }

    /** Every purchase movement so far says why it exists: Receipt. */
    private function assertEveryMovementIsAReceipt(string $step): void
    {
        $this->assertSame(0, StockMovement::query()->where('purpose', '!=', StockMovementPurpose::Receipt->value)->count(), "{$step}: a non-Receipt movement exists");
        $this->assertSame(0, StockMovement::query()->whereNull('purpose')->count(), "{$step}: a movement without a purpose exists");
    }

    /**
     * One material lot per GRN line, carrying exactly that line's quantity,
     * in bags whose weights add up to it.
     */
    private function assertLotsMirrorTheReceiptLines(string $step): void
    {
        $lines = GoodsReceiptNote::query()->with('lines')->get()->flatMap->lines;
        $this->assertSame($lines->count(), MaterialLot::query()->count(), "{$step}: lots != GRN lines");

        foreach ($lines as $line) {
            $lot = MaterialLot::query()->with('bags')->where('goods_receipt_note_line_id', $line->id)->sole();
            $this->assertSame($line->goods_receipt_note_id, $lot->grn_id, "{$step}: lot {$lot->id} points at another GRN");
            $this->assertSame($line->item_id, $lot->item_id, "{$step}: lot {$lot->id} carries another item");
            $this->assertSame(0, bccomp((string) $line->quantity, (string) $lot->total_received_kg, 4), "{$step}: lot {$lot->id} total {$lot->total_received_kg} vs GRN line {$line->quantity}");
            $bagTotal = $lot->bags->reduce(fn (string $carry, MaterialBag $bag) => bcadd($carry, (string) $bag->original_kg, 4), '0.0000');
            $this->assertSame(0, bccomp($bagTotal, (string) $lot->total_received_kg, 4), "{$step}: lot {$lot->id} bags sum {$bagTotal} vs total {$lot->total_received_kg}");
        }
    }

    /** Exactly one pending Receipt Note per GRN, through the real listener. */
    private function assertOneReceiptNotePerGrn(string $step): void
    {
        $grnIds = GoodsReceiptNote::query()->orderBy('id')->pluck('id')->all();
        $entries = TallySyncEntry::query()->orderBy('syncable_id')->get();

        $this->assertSame(count($grnIds), $entries->count(), "{$step}: tally_sync_entries != GRNs");
        $this->assertSame([], $entries->reject(fn (TallySyncEntry $e) => $e->tally_voucher_type === 'Receipt Note')->pluck('tally_voucher_type')->all(), "{$step}: a non-Receipt-Note entry exists");
        $this->assertSame($grnIds, $entries->pluck('syncable_id')->map(fn ($id) => (int) $id)->all(), "{$step}: an entry points at another GRN, or a GRN has two");
        $this->assertSame([(new GoodsReceiptNote)->getMorphClass()], $entries->pluck('syncable_type')->unique()->values()->all(), "{$step}: an entry is not on a GRN");
    }

    private function assertNothingChangedSince(array $counts, string $step): void
    {
        $this->assertSame($counts, $this->counts(), "{$step}: a refused receipt changed the database");
    }

    /** @return array<string, int|string> */
    private function counts(): array
    {
        return [
            'grns' => GoodsReceiptNote::query()->count(),
            'movements' => StockMovement::query()->count(),
            'lots' => MaterialLot::query()->count(),
            'bags' => MaterialBag::query()->count(),
            'tally_entries' => TallySyncEntry::query()->count(),
            'balance' => (string) (StockBalance::query()->where('item_id', $this->resin->id)->where('warehouse_id', $this->store->id)->value('quantity') ?? '0.0000'),
            'statuses' => PurchaseOrder::query()->orderBy('id')->pluck('status')->map->value->implode(','),
            'received' => PurchaseOrderLine::query()->orderBy('id')->pluck('quantity_received')->map(fn ($q) => (string) $q)->implode(','),
        ];
    }

    // ---- FC-06 walk (extends ProcurementRateVisibilityTest to the chain) --------

    /**
     * Every key path in the payload, at any depth, whose name is a rate key.
     * A walk, not a list of guessed paths, so a rate that reappears in a new
     * nested block fails this test instead of slipping past it.
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

    // ---- 1. Draft → Sent → Partial → over-receipt refused → Closed → refused -----

    public function test_the_chain_from_draft_to_closed_keeps_the_ledger_balanced_the_lots_per_line_and_one_receipt_note_per_grn(): void
    {
        $this->actingAsTheWholeFactory();

        // Draft: an order moves nothing.
        [$orderId, $lineId] = $this->draftOrder('1000');
        $this->assertSame(PurchaseOrderStatus::Draft, PurchaseOrder::query()->findOrFail($orderId)->status);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, TallySyncEntry::query()->count(), 'A Draft order enqueues nothing');
        $this->assertLedgerInBalance('after create');

        // Sent: still nothing moves, nothing is enqueued (PO → Tally is WS-C's
        // owner-gated flag, OFF by default — asserted by its own test).
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Sent->value);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertLedgerInBalance('after send');

        // GRN 1: 400 of 1000 → PartiallyReceived.
        $grn1 = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'chain-grn-1'))
            ->assertSuccessful()
            ->assertJsonPath('data.purchase_order_id', $orderId)
            ->assertJsonPath('data.lines.0.quantity', '400.0000')
            ->assertJsonCount(1, 'data.material_lots')
            ->assertJsonCount(16, 'data.material_lots.0.bags')
            ->json('data');

        $order = PurchaseOrder::query()->with('lines')->findOrFail($orderId);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->status);
        $this->assertSame('400.0000', (string) $order->lines->first()->quantity_received);
        $this->assertSame('400.0000', (string) StockBalance::query()->where('item_id', $this->resin->id)->where('warehouse_id', $this->store->id)->sole()->quantity);
        $movement = StockMovement::query()->sole();
        $this->assertSame(StockMovementType::Receipt, $movement->type);
        $this->assertSame(StockMovementPurpose::Receipt, $movement->purpose);
        $this->assertSame('400.0000', (string) $movement->quantity);
        $this->assertLedgerInBalance('after GRN 1');
        $this->assertEveryMovementIsAReceipt('after GRN 1');
        $this->assertLotsMirrorTheReceiptLines('after GRN 1');
        $this->assertOneReceiptNotePerGrn('after GRN 1');
        $this->assertSame($grn1['receipt_note_reference'], TallySyncEntry::query()->sole()->payload['voucher_number'], 'The Receipt Note carries the arrival reference the GRN minted');

        // GRN 2: 700 against a remaining 600 → refused whole, the line named,
        // and the database exactly as it was.
        $before = $this->counts();
        $refused = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '700', 'chain-grn-2'))
            ->assertStatus(422)
            ->json();
        $this->assertStringContainsString("purchase order line #{$lineId}", $refused['message']);
        $this->assertStringContainsString('remaining 600.0000', $refused['message']);
        $this->assertStringContainsString('requested 700', $refused['message']);
        $this->assertNothingChangedSince($before, 'after the refused over-receipt');
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, PurchaseOrder::query()->findOrFail($orderId)->status);
        $this->assertLedgerInBalance('after the refused over-receipt');
        $this->assertOneReceiptNotePerGrn('after the refused over-receipt');

        // GRN 3: exactly the remaining 600 → Closed.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '600', 'chain-grn-3'))
            ->assertSuccessful()
            ->assertJsonPath('data.lines.0.quantity', '600.0000')
            ->assertJsonCount(24, 'data.material_lots.0.bags');

        $order = PurchaseOrder::query()->with('lines')->findOrFail($orderId);
        $this->assertSame(PurchaseOrderStatus::Closed, $order->status);
        $this->assertSame('1000.0000', (string) $order->lines->first()->quantity_received);
        $this->assertSame('1000.0000', (string) StockBalance::query()->where('item_id', $this->resin->id)->where('warehouse_id', $this->store->id)->sole()->quantity);
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame(2, GoodsReceiptNote::query()->count());
        $this->assertSame(['400.0000', '600.0000'], StockMovement::query()->orderBy('id')->pluck('quantity')->map(fn ($q) => (string) $q)->all());
        $this->assertLedgerInBalance('after GRN 3');
        $this->assertEveryMovementIsAReceipt('after GRN 3');
        $this->assertLotsMirrorTheReceiptLines('after GRN 3');
        $this->assertOneReceiptNotePerGrn('after GRN 3');

        // A further receipt against the Closed order is refused by STATUS —
        // before any quantity is even compared — and writes nothing.
        $before = $this->counts();
        $closed = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '25', 'chain-grn-4'))
            ->assertStatus(422)
            ->json('message');
        $this->assertStringContainsString('"closed"', $closed);
        $this->assertStringNotContainsString('remaining', $closed, 'A Closed order is refused as Closed, not as an over-receipt');
        $this->assertNothingChangedSince($before, 'after the receipt against a Closed order');
        $this->assertLedgerInBalance('after the receipt against a Closed order');
    }

    // ---- 2. GRN against an order that is not Sent/PartiallyReceived --------------

    public function test_a_receipt_against_a_draft_order_is_refused_before_anything_is_written(): void
    {
        $this->actingAsTheWholeFactory();
        [$orderId, $lineId] = $this->draftOrder('1000');
        $before = $this->counts();

        $message = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'draft-grn-1'))
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('"draft"', $message);
        $this->assertNothingChangedSince($before, 'after the receipt against a Draft order');
        $this->assertSame(0, GoodsReceiptNote::query()->count());
        $this->assertSame(0, StockBalance::query()->count());
        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertSame(PurchaseOrderStatus::Draft, PurchaseOrder::query()->findOrFail($orderId)->status);
        $this->assertLedgerInBalance('after the receipt against a Draft order');
    }

    /**
     * Cancelled is reached by fixture here: at the time of writing no service
     * method transitions an order to Cancelled (WS-A's cancel lands in the
     * same phase — the endpoint version is the next test). What this pins is
     * GoodsReceiptService's refusal, which is on the STATUS alone.
     */
    public function test_a_receipt_against_a_cancelled_order_is_refused_before_anything_is_written(): void
    {
        $this->actingAsTheWholeFactory();
        [$orderId, $lineId] = $this->draftOrder('1000');
        PurchaseOrder::query()->whereKey($orderId)->update(['status' => PurchaseOrderStatus::Cancelled->value]);
        $before = $this->counts();

        $message = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'cancelled-grn-1'))
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('"cancelled"', $message);
        $this->assertNothingChangedSince($before, 'after the receipt against a Cancelled order');
        $this->assertSame(0, GoodsReceiptNote::query()->count());
        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertSame(PurchaseOrderStatus::Cancelled, PurchaseOrder::query()->findOrFail($orderId)->status);
    }

    /**
     * The same refusal reached through the lifecycle:
     * `POST purchase-orders/{po}/cancel` (Draft|Sent with zero receipts →
     * Cancelled). The endpoint has landed, so this test no longer asks
     * whether the route exists — renaming it FAILS the contract.
     */
    public function test_a_receipt_against_an_order_cancelled_through_the_lifecycle_endpoint_is_refused(): void
    {
        $this->actingAsTheWholeFactory();
        [$orderId, $lineId] = $this->sentOrder('1000');

        // Both spellings of the reason field are sent: the FormRequest is
        // WS-A's and validation reads only the key it declares.
        $reason = 'contract test: cancelled before any arrival';
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/cancel", ['reason' => $reason, 'cancelled_reason' => $reason])
            ->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Cancelled->value);
        $before = $this->counts();

        $message = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'cancelled-grn-2'))
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('"cancelled"', $message);
        $this->assertNothingChangedSince($before, 'after the receipt against an order cancelled through the endpoint');
        $this->assertSame(0, GoodsReceiptNote::query()->count());
    }

    // ---- 3. Received stock consumed by a batch ----------------------------------

    /**
     * The confirmed floor flow after the arrival (FC-01, DEC-20260807-006):
     * bags are born waiting_qc → Incoming QC releases them → a bag is
     * scanned into the COMMON resin input (no machine, no batch; a pour
     * record, not a stock movement) → a batch starts and completes with its
     * consumption CALCULATED in material_consumptions → ONE Issue movement
     * with purpose Consumption, the balance reduced, the invariant intact.
     *
     * TraceabilityService::consumptionFor(entry, item) is the per-MACHINE
     * day-bin formula (opening + loaded − closing − returned). Under the
     * common input no load is stamped with a machine or a batch, so for this
     * entry it must report loaded 0 and a NOT-COMPUTABLE consumed_kg (null,
     * not zero) — it may not claim a bag→batch figure the factory said is
     * physically impossible. The entry↔item consumption is the calculated
     * shift_material_consumptions row and the movement that books it.
     */
    public function test_received_stock_is_consumed_by_a_batch_as_one_calculated_consumption_movement_and_the_ledger_stays_balanced(): void
    {
        $this->actingAsTheWholeFactory();

        // Production fixture, the shape StockLedgerInvariantTest proves starts.
        $this->seed(CanonicalMachineSeeder::class);
        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $product = Item::create([
            'sku' => 'ITEM_B', 'name' => 'ITEM_B', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '10.0000', 'standard_cycle_time' => '10.00', 'standard_cavities' => 2,
            'nos_per_tray' => 100, 'trays_per_box' => 2, 'nos_per_box' => 200,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'guid-item-b',
        ]);
        $bom = Bom::create(['item_id' => $product->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0100']);
        // The common input IS the store under one accounting godown.
        app(FactoryDayBinService::class)->setWarehouseId($this->store->id);

        // Purchase → arrival: 200 kg in 8 bags, waiting for QC.
        [$orderId, $lineId] = $this->sentOrder('200');
        $grn = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '200', 'consume-grn-1'))
            ->assertSuccessful()
            ->json('data');
        $this->assertSame(PurchaseOrderStatus::Closed, PurchaseOrder::query()->findOrFail($orderId)->status);
        $this->assertSame([MaterialBagStatus::WaitingQc], MaterialBag::query()->pluck('status')->unique()->values()->all());
        $this->assertLedgerInBalance('after the arrival');

        // Incoming QC releases the whole arrival: every bag in store, no
        // rejection, so no stock leaves.
        $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $grn['lines'][0]['id'],
            'inspected_quantity' => '200',
            'accepted_quantity' => '200',
            'rejected_quantity' => '0',
            'inspection_date' => '2026-08-02',
        ])->assertSuccessful();
        $this->assertSame([MaterialBagStatus::InStore], MaterialBag::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(1, StockMovement::query()->count(), 'A full acceptance moves no stock');
        $this->assertLedgerInBalance('after QC release');

        // The scan into the common input: a pour record — the bag empties,
        // the ledger row names no machine and no batch, company stock is
        // untouched.
        $bag = MaterialBag::query()->orderBy('id')->firstOrFail();
        $this->postJson('/api/v1/production/day-bin/load-bag', ['barcode' => $bag->barcode])->assertSuccessful();
        $this->assertSame(MaterialBagStatus::Consumed, $bag->fresh()->status);
        $pour = DayBinMovement::query()->sole();
        $this->assertNull($pour->work_center_id);
        $this->assertNull($pour->shift_production_entry_id);
        $this->assertSame(1, StockMovement::query()->count(), 'A scan is not a stock movement');
        $this->assertSame('200.0000', (string) StockBalance::query()->where('item_id', $this->resin->id)->where('warehouse_id', $this->store->id)->sole()->quantity);
        $this->assertLedgerInBalance('after the scan');

        // Start Batch, then Complete with the calculated consumption.
        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $product->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-08-03',
        ])->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '400',
            'running_hours' => '1',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->store->id, 'quantity_issued_kg' => '4.5'],
            ],
        ])->assertOk();

        // ONE Consumption movement for the received item, and the balance is
        // what arrived minus what the batch consumed.
        $consumption = StockMovement::query()->where('type', StockMovementType::Issue)->sole();
        $this->assertSame($this->resin->id, $consumption->item_id);
        $this->assertSame($this->store->id, $consumption->warehouse_id);
        $this->assertSame(StockMovementPurpose::Consumption, $consumption->purpose);
        $this->assertSame("SPE #{$entryId}", $consumption->reference);
        $this->assertSame('4.5000', (string) $consumption->quantity);
        $this->assertSame('195.5000', (string) StockBalance::query()->where('item_id', $this->resin->id)->where('warehouse_id', $this->store->id)->sole()->quantity);

        $output = StockMovement::query()->where('type', StockMovementType::Receipt)->where('item_id', $product->id)->sole();
        $this->assertSame(StockMovementPurpose::Output, $output->purpose);
        $this->assertSame(3, StockMovement::query()->count(), 'receipt + consumption + output, nothing else');
        $this->assertLedgerInBalance('after the batch');

        // The entry↔item consumption is recorded on the entry itself.
        $entry = ShiftProductionEntry::query()->with('materialConsumptions')->findOrFail($entryId);
        $recorded = $entry->materialConsumptions->sole();
        $this->assertSame($this->resin->id, $recorded->item_id);
        $this->assertSame($this->store->id, $recorded->warehouse_id);
        // bccomp, not string identity: the column is unCAST and comes back
        // driver-formatted ('4.5' on SQLite, '4.5000' on MySQL).
        $this->assertSame(0, bccomp('4.5', (string) $recorded->quantity_issued_kg, 4));

        // consumptionFor is honest about what it cannot know (FC-01): no
        // machine-stamped load, no closing count → not computable, not zero.
        $formula = app(TraceabilityService::class)->consumptionFor($entryId, $this->resin->id);
        $this->assertSame(['opening_kg', 'loaded_kg', 'returned_kg', 'closing_kg', 'consumed_kg'], array_keys($formula));
        $this->assertSame('0.0000', $formula['opening_kg']);
        $this->assertSame('0.0000', $formula['loaded_kg'], 'A common-input scan is not a load onto this batch');
        $this->assertSame('0.0000', $formula['returned_kg']);
        $this->assertNull($formula['closing_kg']);
        $this->assertNull($formula['consumed_kg'], 'Not computable is null, never a fabricated figure');
    }

    // ---- 4. FC-06 across the whole chain ---------------------------------------

    /**
     * A PROCUREMENT-ONLY login walks the same chain — every write and every
     * read, including the refusal bodies — and never sees a purchase rate.
     * ProcurementRateVisibilityTest pins the two list payloads and the GRN
     * store; this extends the walk to the order's store and send responses,
     * the over-receipt 422, and (once WS-A registers them) the show and
     * trace endpoints.
     */
    public function test_a_procurement_only_login_walking_the_whole_chain_never_sees_a_rate(): void
    {
        $this->actingAsProcurementOnly();

        $created = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-01',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '1000', 'unit_price' => self::UNIT_PRICE]],
        ])->assertSuccessful()->json();
        $this->assertCarriesNoRate($created, 'purchase-orders store');
        $this->assertSame('1000.0000', $created['data']['lines'][0]['quantity'], 'The quantity is still served');
        $this->assertArrayNotHasKey('unit_price', $created['data']['lines'][0], 'ABSENT, not null');
        $orderId = $created['data']['id'];
        $lineId = $created['data']['lines'][0]['id'];

        $sent = $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertSuccessful()->json();
        $this->assertCarriesNoRate($sent, 'purchase-orders send');

        $partial = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '400', 'fc06-grn-1'))
            ->assertSuccessful()->json();
        $this->assertCarriesNoRate($partial, 'goods-receipts store (partial)');
        $this->assertArrayNotHasKey('unit_cost', $partial['data']['lines'][0]);

        $refused = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '700', 'fc06-grn-2'))
            ->assertStatus(422)->json();
        $this->assertCarriesNoRate($refused, 'the over-receipt refusal');

        $closed = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($orderId, $lineId, '600', 'fc06-grn-3'))
            ->assertSuccessful()->json();
        $this->assertCarriesNoRate($closed, 'goods-receipts store (remainder)');
        $grnId = $closed['data']['id'];

        $orders = $this->getJson('/api/v1/procurement/purchase-orders')->assertSuccessful()->json();
        $this->assertCarriesNoRate($orders, 'purchase-orders index');
        $this->assertSame(PurchaseOrderStatus::Closed->value, $orders['data'][0]['status']);

        $receipts = $this->getJson('/api/v1/procurement/goods-receipts')->assertSuccessful()->json();
        $this->assertCarriesNoRate($receipts, 'goods-receipts index');
        $this->assertCount(2, $receipts['data']);

        // The Phase 6 reads, all landed: every one is walked, so a new
        // payload can never open the gate the lists keep shut — and a read
        // that stops answering fails here instead of being stepped over.
        $shows = [
            "/api/v1/procurement/purchase-orders/{$orderId}",
            "/api/v1/procurement/purchase-orders/{$orderId}/trace",
            "/api/v1/procurement/goods-receipts/{$grnId}",
        ];
        foreach ($shows as $url) {
            $this->assertCarriesNoRate($this->getJson($url)->assertSuccessful()->json(), $url);
        }
    }
}
