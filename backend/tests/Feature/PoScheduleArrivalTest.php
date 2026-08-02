<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderSchedule;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE OWNER-CONFIRMED TALLY-PO-TO-ARRIVAL FLOW. Tally is the read-only PO
 * and schedule master; one PO arrives across many GRNs; each arrival books
 * against exact item/due-date schedules (oldest due first by default,
 * editable), records its Receipt Note reference, creates the permanent bag
 * identities immediately — held waiting_qc and unavailable to production —
 * and Incoming QC releases accepted whole bags and routes rejected whole
 * bags through a recorded Rejections Out reference. No Tally voucher is
 * created for a rejection: the reference waits for the proven XML.
 */
class PoScheduleArrivalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);
        Event::fake([GoodsReceiptNoteReceived::class]);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.manage', 'inventory.view', 'inventory.manage', 'quality.manage', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    /** @return array{0: PurchaseOrder, 1: Item, 2: Warehouse} */
    private function mirroredOrder(): array
    {
        $item = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']);
        $vendor = Vendor::create(['code' => 'SUP-1', 'name' => 'Resin Supplier']);

        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $vendor->id,
            'order_date' => '2026-07-30',
            'source' => 'tally',
            'tally_order_no' => 'PO/2026/041',
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => '300',
                'unit_price' => '95',
                'schedules' => [
                    ['due_date' => '2026-08-10', 'quantity' => '100', 'tally_reference' => 'PO/2026/041-1'],
                    ['due_date' => '2026-08-01', 'quantity' => '100', 'tally_reference' => 'PO/2026/041-2'],
                    ['due_date' => '2026-08-20', 'quantity' => '100', 'tally_reference' => 'PO/2026/041-3'],
                ],
            ]],
        ])->assertSuccessful()->json('data');

        return [PurchaseOrder::findOrFail($order['id']), $item, $warehouse];
    }

    /** @return array<string, mixed> */
    private function arrival(PurchaseOrder $order, Warehouse $warehouse, string $quantity, string $key, array $extra = []): array
    {
        $line = $order->lines()->first();

        return [
            'receipt_key' => $key,
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'received_date' => '2026-08-02',
            'lines' => [array_merge([
                'purchase_order_line_id' => $line->id,
                'quantity' => $quantity,
                'lots' => [[
                    'supplier_lot_no' => 'SB-'.$key,
                    'bag_count' => (int) ((float) $quantity / 25),
                    'bag_weight_kg' => '25',
                ]],
            ], $extra)],
        ];
    }

    public function test_a_tally_mirror_is_born_sent_with_its_schedules_and_identities(): void
    {
        [$order] = $this->mirroredOrder();

        $this->assertTrue($order->isTallyMirror());
        $this->assertSame(PurchaseOrderStatus::Sent, $order->status);
        $this->assertSame('PO/2026/041', $order->tally_order_no);

        // Served oldest-due first, whatever order they were entered in.
        $due = $order->lines->first()->schedules->pluck('due_date')->map->toDateString()->all();
        $this->assertSame(['2026-08-01', '2026-08-10', '2026-08-20'], $due);
    }

    public function test_schedules_promising_more_than_the_line_are_refused(): void
    {
        $item = Item::create(['sku' => 'RM-X', 'name' => 'X', 'uom' => 'Kgs']);
        $vendor = Vendor::create(['code' => 'SUP-2', 'name' => 'S2']);

        $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $vendor->id,
            'order_date' => '2026-07-30',
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => '100',
                'unit_price' => '10',
                'schedules' => [
                    ['due_date' => '2026-08-01', 'quantity' => '80'],
                    ['due_date' => '2026-08-10', 'quantity' => '40'],
                ],
            ]],
        ])->assertStatus(422);
    }

    public function test_an_arrival_defaults_to_oldest_due_and_walks_forward(): void
    {
        [$order, , $warehouse] = $this->mirroredOrder();

        // 150 kg arrives: fills the 01-Aug window (100) then 50 into 10-Aug.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->arrival($order, $warehouse, '150', 'arr-1'))
            ->assertSuccessful();

        $schedules = PurchaseOrderSchedule::query()->orderBy('due_date')->get();
        $this->assertSame(['100.0000', '50.0000', '0.0000'], $schedules->pluck('quantity_received')->map(fn ($q) => (string) $q)->all());

        // References defaulted deterministically at arrival.
        $grn = GoodsReceiptNote::sole();
        $this->assertNotNull($grn->receipt_note_reference);
        $this->assertNotNull($grn->tracking_number);
    }

    public function test_an_edited_allocation_is_validated_and_honoured(): void
    {
        [$order, , $warehouse] = $this->mirroredOrder();
        $schedules = $order->lines->first()->schedules;
        $aug10 = $schedules->firstWhere(fn ($s) => $s->due_date->toDateString() === '2026-08-10');
        $aug20 = $schedules->firstWhere(fn ($s) => $s->due_date->toDateString() === '2026-08-20');

        // The receiver moves the whole 150 onto the later windows on purpose.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->arrival($order, $warehouse, '150', 'arr-2', [
            'schedule_allocations' => [
                ['purchase_order_schedule_id' => $aug10->id, 'quantity' => '100'],
                ['purchase_order_schedule_id' => $aug20->id, 'quantity' => '50'],
            ],
        ]))->assertSuccessful();

        $this->assertSame('100.0000', (string) $aug10->fresh()->quantity_received);
        $this->assertSame('50.0000', (string) $aug20->fresh()->quantity_received);

        // A second arrival over-allocating a window is refused whole.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->arrival($order, $warehouse, '50', 'arr-3', [
            'schedule_allocations' => [
                ['purchase_order_schedule_id' => $aug10->id, 'quantity' => '50'],
            ],
        ]))->assertStatus(422);
        $this->assertSame('100.0000', (string) $aug10->fresh()->quantity_received);
    }

    public function test_arrival_bags_wait_for_qc_and_cannot_be_loaded(): void
    {
        [$order, , $warehouse] = $this->mirroredOrder();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->arrival($order, $warehouse, '100', 'arr-4'))
            ->assertSuccessful();

        $bags = MaterialBag::all();
        $this->assertCount(4, $bags);
        $bags->each(fn ($bag) => $this->assertSame(MaterialBagStatus::WaitingQc, $bag->status));

        // Production cannot touch a waiting bag: the load door refuses it by
        // name. (Day-bin warehouse named so the refusal is the QC hold, not
        // missing setup.)
        $bin = Warehouse::create(['code' => 'WIP', 'name' => 'WIP']);
        app(FactoryDayBinService::class)->setWarehouseId($bin->id);

        $this->postJson('/api/v1/production/day-bin/load-bag', ['barcode' => $bags->first()->barcode])
            ->assertStatus(422)
            ->assertJsonPath('errors.barcode.0', fn ($message) => str_contains($message, 'waiting for incoming QC'));
    }

    public function test_qc_releases_accepted_whole_bags_and_routes_rejects_out_with_a_reference(): void
    {
        [$order, $item, $warehouse] = $this->mirroredOrder();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->arrival($order, $warehouse, '100', 'arr-5'))
            ->assertSuccessful();

        $line = GoodsReceiptNote::sole()->lines()->first();

        // 100 arrived in 4 × 25 kg bags; QC rejects exactly 50 — two whole
        // bags off the tail of the unload.
        $inspection = $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $line->id,
            'inspected_quantity' => '100',
            'accepted_quantity' => '50',
            'rejected_quantity' => '50',
            'inspection_date' => '2026-08-02',
        ])->assertSuccessful()->json('data');

        $statuses = MaterialBag::query()->orderBy('id')->pluck('status')->map->value->all();
        $this->assertSame(['in_store', 'in_store', 'rejected_qc', 'rejected_qc'], $statuses);

        // The rejected kilograms left usable stock through an ordinary issue.
        $balance = StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->sole();
        $this->assertSame('50.0000', (string) $balance->quantity);

        // The Rejections Out reference is recorded — and it is a reference,
        // not a voucher: no Tally sync entry exists for it.
        $this->assertNotNull($inspection['rejections_out_reference'] ?? null);
        $this->assertSame(0, TallySyncEntry::query()
            ->where('payload', 'like', '%Rejections Out%')->count());

        // A second inspection of the same line is refused: disposition ran.
        $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $line->id,
            'inspected_quantity' => '100',
            'accepted_quantity' => '100',
            'rejected_quantity' => '0',
            'inspection_date' => '2026-08-02',
        ])->assertStatus(422);
    }

    public function test_a_rejection_ending_inside_a_bag_holds_that_bag_for_the_owner_ruling(): void
    {
        [$order, , $warehouse] = $this->mirroredOrder();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->arrival($order, $warehouse, '100', 'arr-6'))
            ->assertSuccessful();

        $line = GoodsReceiptNote::sole()->lines()->first();

        // 30 kg rejected: one whole 25 kg bag goes, the next 5 kg would
        // split a bag — an open owner decision, so that bag stays held.
        $inspection = $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $line->id,
            'inspected_quantity' => '100',
            'accepted_quantity' => '70',
            'rejected_quantity' => '30',
            'inspection_date' => '2026-08-02',
        ])->assertSuccessful()->json('data');

        $statuses = MaterialBag::query()->orderBy('id')->pluck('status')->map->value->all();
        $this->assertSame(['in_store', 'in_store', 'waiting_qc', 'rejected_qc'], $statuses);
        $this->assertStringContainsString('open owner decision', $inspection['bag_disposition_note'] ?? '');
    }
}
