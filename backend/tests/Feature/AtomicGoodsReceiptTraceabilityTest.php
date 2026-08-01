<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AtomicGoodsReceiptTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);
        Event::fake([GoodsReceiptNoteReceived::class]);

        $user = User::factory()->create(['is_active' => true]);
        // finance.view rides along because this file asserts the lot
        // register's receipt PRICE — a figure the cost-traceability wave
        // limited to Owner/Accounts eyes (MaterialLotResource gates
        // receipt.unit_cost on finance.*). The datetime assertions this
        // file exists for are permission-blind either way.
        foreach (['procurement.manage', 'inventory.view', 'inventory.manage', 'production.manage', 'finance.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    /**
     * @return array{0: PurchaseOrder, 1: PurchaseOrderLine, 2: Item, 3: Warehouse}
     */
    private function purchase(string $quantity = '12000'): array
    {
        $item = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']);
        $vendor = Vendor::create(['code' => 'SUP-1', 'name' => 'Resin Supplier']);
        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'status' => PurchaseOrderStatus::Sent,
            'order_date' => '2026-07-30',
        ]);
        $line = $order->lines()->create([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '100',
            'quantity_received' => '0',
        ]);

        return [$order, $line, $item, $warehouse];
    }

    /** @return array<string, mixed> */
    private function payload(
        PurchaseOrder $order,
        PurchaseOrderLine $line,
        Warehouse $warehouse,
        string $receiptKey = 'receipt-20260730-001',
    ): array {
        return [
            'receipt_key' => $receiptKey,
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'reference' => 'GRN-TEST-001',
            'received_date' => '2026-07-30',
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'quantity' => '12000',
                'lots' => [[
                    'supplier_lot_no' => 'SUP-LOT-88',
                    'bag_count' => 10,
                    'bag_weight_kg' => '1200',
                ]],
            ]],
        ];
    }

    public function test_grn_atomically_creates_one_lot_and_ten_printable_bags(): void
    {
        [$order, $line, $item, $warehouse] = $this->purchase();

        $response = $this->postJson(
            '/api/v1/procurement/goods-receipts',
            $this->payload($order, $line, $warehouse),
        )->assertSuccessful()
            ->assertJsonPath('data.receipt_key', 'receipt-20260730-001')
            ->assertJsonCount(1, 'data.material_lots')
            ->assertJsonCount(1, 'data.lines.0.material_lots')
            ->assertJsonCount(10, 'data.material_lots.0.bags');

        $grnId = $response->json('data.id');
        $lot = MaterialLot::query()->with('bags')->sole();

        $this->assertSame($grnId, $lot->grn_id);
        $this->assertSame($line->id, $lot->goods_receipt_note_line_id);
        $this->assertSame($item->id, $lot->item_id);
        $this->assertSame('12000.0000', (string) $lot->total_received_kg);
        $this->assertCount(10, $lot->bags);
        $this->assertSame(10, $lot->bags->pluck('barcode')->unique()->count());
        $this->assertTrue($lot->bags->every(
            fn (MaterialBag $bag) => (string) $bag->original_kg === '1200.0000'
                && (string) $bag->remaining_kg === '1200.0000'
                && $bag->current_warehouse_id === $warehouse->id,
        ));

        $this->assertSame('12000.0000', (string) StockBalance::query()->sole()->quantity);
        $this->assertSame(1, StockMovement::query()->count(), 'Lots must not create a second stock receipt.');

        $this->getJson("/api/v1/inventory/material-lots?grn_id={$grnId}")
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lot->id)
            ->assertJsonCount(10, 'data.0.bags');
    }

    public function test_same_receipt_key_replays_without_duplicate_stock_lots_bags_or_event(): void
    {
        [$order, $line, , $warehouse] = $this->purchase();
        $payload = $this->payload($order, $line, $warehouse);

        $firstId = $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertSuccessful()->json('data.id');
        $secondId = $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertSuccessful()->json('data.id');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, GoodsReceiptNote::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(1, MaterialLot::query()->count());
        $this->assertSame(10, MaterialBag::query()->count());
        $this->assertSame('12000.0000', (string) $line->fresh()->quantity_received);
        Event::assertDispatchedTimes(GoodsReceiptNoteReceived::class, 1);
    }

    /**
     * The store team enters the real date AND time the lorry was unloaded, so
     * the receipt must keep the time it was given (it used to be stamped with
     * whatever "now" happened to be when the form was submitted). The lot
     * register then shows that receipt's price and exact date+time.
     */
    public function test_a_received_datetime_is_kept_on_the_receipt_and_its_stock_movement(): void
    {
        [$order, $line, , $warehouse] = $this->purchase();
        $payload = $this->payload($order, $line, $warehouse);
        $payload['received_date'] = '2026-07-30 14:35:00';

        $grnId = $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $grn = GoodsReceiptNote::query()->findOrFail($grnId);
        $this->assertSame('2026-07-30 14:35:00', $grn->received_date->format('Y-m-d H:i:s'));

        // The ledger entry carries the same moment, not the submit time.
        $movement = StockMovement::query()->sole();
        $this->assertSame('2026-07-30 14:35:00', $movement->movement_date->format('Y-m-d H:i:s'));

        // material_lots.received_date is a date column (FIFO index): the
        // datetime is narrowed to its day rather than corrupting the column.
        $lot = MaterialLot::query()->sole();
        $this->assertSame('2026-07-30', $lot->received_date->toDateString());

        $this->getJson("/api/v1/inventory/material-lots?grn_id={$grnId}")
            ->assertSuccessful()
            ->assertJsonPath('data.0.receipt.goods_receipt_note_id', $grnId)
            ->assertJsonPath('data.0.receipt.purchase_order_id', $order->id)
            ->assertJsonPath('data.0.receipt.unit_cost', '100.0000')
            ->assertJsonPath('data.0.receipt.received_at', $grn->received_date->toIso8601String());
    }

    public function test_a_grn_without_a_received_date_still_posts_stamped_now(): void
    {
        [$order, $line, , $warehouse] = $this->purchase();
        $payload = $this->payload($order, $line, $warehouse);
        unset($payload['received_date']);

        $grnId = $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $this->assertNotNull(GoodsReceiptNote::query()->findOrFail($grnId)->received_date);
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(10, MaterialBag::query()->count());
    }

    public function test_a_legacy_grn_client_without_a_receipt_key_still_posts_once(): void
    {
        [$order, $line, , $warehouse] = $this->purchase();
        $payload = $this->payload($order, $line, $warehouse);
        unset($payload['receipt_key']);

        $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.receipt_key', null)
            ->assertJsonCount(10, 'data.material_lots.0.bags');

        $this->assertSame(1, GoodsReceiptNote::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_receipt_key_cannot_be_reused_for_different_data(): void
    {
        [$order, $line, , $warehouse] = $this->purchase();
        $payload = $this->payload($order, $line, $warehouse);

        $this->postJson('/api/v1/procurement/goods-receipts', $payload)->assertSuccessful();
        $payload['reference'] = 'DIFFERENT';

        $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('receipt_key');

        $this->assertSame(1, GoodsReceiptNote::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_bad_lot_totals_roll_back_the_grn_stock_and_all_bags(): void
    {
        [$order, $line, , $warehouse] = $this->purchase();
        $payload = $this->payload($order, $line, $warehouse);
        $payload['lines'][0]['lots'][0]['bag_weight_kg'] = '1000';

        $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.lots');

        $this->assertSame(0, GoodsReceiptNote::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBalance::query()->count());
        $this->assertSame(0, MaterialLot::query()->count());
        $this->assertSame(0, MaterialBag::query()->count());
        $this->assertSame('0.0000', (string) $line->fresh()->quantity_received);
        Event::assertNotDispatched(GoodsReceiptNoteReceived::class);
    }

    public function test_direct_lot_registration_refuses_a_total_that_disagrees_with_its_bags(): void
    {
        [, , $item, $warehouse] = $this->purchase();

        $this->postJson('/api/v1/inventory/material-lots', [
            'item_id' => $item->id,
            'received_date' => '2026-07-30',
            'bag_count' => 10,
            'bag_weight_kg' => '100',
            'total_received_kg' => '900',
            'warehouse_id' => $warehouse->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('total_received_kg');

        $this->assertSame(0, MaterialLot::query()->count());
        $this->assertSame(0, MaterialBag::query()->count());
    }

    public function test_disabled_traceability_refuses_nested_lots_without_posting_the_grn(): void
    {
        config(['production.traceability_enabled' => false]);
        [$order, $line, , $warehouse] = $this->purchase();

        $this->postJson(
            '/api/v1/procurement/goods-receipts',
            $this->payload($order, $line, $warehouse),
        )->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.lots');

        $this->assertSame(0, GoodsReceiptNote::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('0.0000', (string) $line->fresh()->quantity_received);
    }

    public function test_a_grn_linked_manual_lot_requires_the_grn_warehouse(): void
    {
        [$order, $line, $item, $warehouse] = $this->purchase();
        $payload = $this->payload($order, $line, $warehouse);
        unset($payload['lines'][0]['lots']);
        $grn = $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertSuccessful()
            ->json('data');

        $this->postJson('/api/v1/inventory/material-lots', [
            'grn_id' => $grn['id'],
            'goods_receipt_note_line_id' => $grn['lines'][0]['id'],
            'item_id' => $item->id,
            'received_date' => '2026-07-30',
            'bag_count' => 10,
            'bag_weight_kg' => '1200',
            'total_received_kg' => '12000',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');

        $this->assertSame(0, MaterialLot::query()->count());
    }

    public function test_a_grn_linked_manual_lot_cannot_name_a_different_item_or_warehouse(): void
    {
        [$order, $line, , $warehouse] = $this->purchase();
        $grnId = $this->postJson(
            '/api/v1/procurement/goods-receipts',
            $this->payload($order, $line, $warehouse),
        )->assertSuccessful()->json('data.id');
        $otherItem = Item::create(['sku' => 'RM-OTHER', 'name' => 'Other', 'uom' => 'Kgs']);
        $otherWarehouse = Warehouse::create(['code' => 'OTHER', 'name' => 'Other']);

        $this->postJson('/api/v1/inventory/material-lots', [
            'grn_id' => $grnId,
            'goods_receipt_note_line_id' => GoodsReceiptNote::query()->findOrFail($grnId)->lines()->value('id'),
            'item_id' => $otherItem->id,
            'received_date' => '2026-07-30',
            'bag_count' => 1,
            'bag_weight_kg' => '1',
            'total_received_kg' => '1',
            'warehouse_id' => $otherWarehouse->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['item_id', 'warehouse_id']);

        $this->assertSame(1, MaterialLot::query()->count());
        $this->assertSame(10, MaterialBag::query()->count());
    }

    public function test_central_bin_load_rejects_a_segment_from_another_machine_before_decrementing_bag(): void
    {
        $resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $bottle = Item::create(['sku' => 'FG-1', 'name' => 'Bottle', 'uom' => 'Nos']);
        $warehouse = Warehouse::create(['code' => 'RM', 'name' => 'RM']);
        $machineA = WorkCenter::create(['code' => 'MC-1', 'name' => 'Machine 1']);
        $machineB = WorkCenter::create(['code' => 'MC-2', 'name' => 'Machine 2']);
        $shift = Shift::create([
            'name' => 'A', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'is_active' => true,
        ]);
        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machineA->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-30',
            'batch_status' => 'in_progress',
        ]);
        $bag = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id,
            'received_date' => '2026-07-30',
            'bag_count' => 1,
            'bag_weight_kg' => '100',
            'total_received_kg' => '100',
            'warehouse_id' => $warehouse->id,
        ], null)->bags->first();

        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machineB->id,
            'barcode' => $bag->barcode,
            'quantity_kg' => '5',
            'shift_production_entry_id' => $entry->id,
        ])->assertStatus(422)->assertJsonValidationErrors('shift_production_entry_id');

        $this->assertSame('100.0000', (string) $bag->fresh()->remaining_kg);
        $this->assertSame(0, DayBinMovement::query()->count());
    }
}
