<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StoreIssueService;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE SECOND DOOR INTO PRODUCTION, AND THE HOLD IT USED TO WALK PAST.
 *
 * A bag arriving on a goods receipt is born `waiting_qc` — the owner-confirmed
 * arrival hold that MaterialBagIssueResolver has always enforced: "a bag
 * waiting for incoming QC is not production's yet". That refusal guarded the
 * BAG SCAN door only.
 *
 * The store issue has a second door: typed lines. It names a material and a
 * quantity, reads the aggregate stock balance and transfers it to
 * Production/WIP — and the balance still counts every held bag's kilograms,
 * because the hold lives on the bag, not on the balance. So 100 kg received in
 * four un-inspected bags could be typed straight onto the floor, with the four
 * bags left sitting at `waiting_qc` behind it. The repository's own
 * ResinReceivingChainTest walked exactly that route, green.
 *
 * This test pins the door shut. Nothing here decides anything new about the
 * factory: the rule being applied is the arrival hold that already exists, now
 * read through the same eyes at both doors.
 *
 * WHAT IT IS NOT: a quarantine warehouse, a QC state on the GRN line, or any
 * hold on material that has no bags. An item with no bag records has nothing
 * held and issues exactly as it did before.
 */
class StoreIssueTypedLineQcGuardTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $tray;

    private Warehouse $store;

    private Warehouse $wip;

    private User $storeKeeper;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->store = Warehouse::create(['code' => 'QG-RM', 'name' => 'QG Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'QG-WIP', 'name' => 'QG Work In Progress', 'is_active' => false]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);

        $this->resin = Item::create([
            'sku' => 'QG-RESIN', 'name' => 'QG Resin', 'uom' => 'KGS',
            'is_active' => true, 'is_production_input' => true, 'category' => ItemCategory::RawMaterial,
        ]);
        $this->tray = Item::create([
            'sku' => 'QG-TRAY', 'name' => 'QG Tray', 'uom' => 'Nos',
            'is_active' => true, 'is_production_input' => true,
        ]);

        $this->storeKeeper = User::factory()->create(['is_active' => true]);
        foreach (['inventory.manage', 'procurement.manage', 'quality.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->storeKeeper->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->storeKeeper);
    }

    // ---- fixtures ----------------------------------------------------------

    private function stock(Item $item, string $quantity): void
    {
        StockBalance::query()->updateOrCreate(
            ['item_id' => $item->id, 'warehouse_id' => $this->store->id],
            ['quantity' => $quantity, 'average_cost' => '10.0000'],
        );
    }

    /**
     * Bags exactly as a goods receipt makes them: one lot, N bags, each in the
     * store it was unloaded into, at the given status.
     *
     * @return list<MaterialBag>
     */
    private function bags(string $lotNo, int $count, string $kgEach, MaterialBagStatus $status, ?Item $item = null): array
    {
        $item ??= $this->resin;

        $lot = MaterialLot::create([
            'item_id' => $item->id,
            'supplier_lot_no' => $lotNo,
            'received_date' => '2026-08-18',
            'bag_count' => $count,
            'bag_weight_kg' => $kgEach,
            'total_received_kg' => bcmul($kgEach, (string) $count, 4),
        ]);

        $made = [];
        for ($seq = 1; $seq <= $count; $seq++) {
            $made[] = $lot->bags()->create([
                'barcode' => "{$lotNo}-B{$seq}",
                'original_kg' => $kgEach,
                'remaining_kg' => $kgEach,
                'status' => $status,
                'current_warehouse_id' => $this->store->id,
            ]);
        }

        return $made;
    }

    private function typedIssue(Item $item, string $quantity): TestResponse
    {
        return $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => $quantity,
                'from_warehouse_id' => $this->store->id,
            ]],
        ]);
    }

    private function balance(Item $item, Warehouse $warehouse): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0');
    }

    // ---- the bypass itself -------------------------------------------------

    public function test_a_typed_line_cannot_issue_material_whose_bags_are_all_waiting_qc(): void
    {
        $this->stock($this->resin, '100');
        $this->bags('QG-LOT-A', 4, '25', MaterialBagStatus::WaitingQc);

        $response = $this->typedIssue($this->resin, '75');

        $response->assertStatus(422);
        $this->assertStringContainsString('incoming QC', (string) $response->json('message').json_encode($response->json('errors')));

        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '100', 4),
            'the refused issue moved nothing out of the store');
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->wip), '0', 4),
            'and nothing reached Production/WIP');
        $this->assertSame(0, StoreIssue::query()->count(),
            'the whole handover rolled back — no half-written issue survives the refusal');
    }

    public function test_the_same_quantity_issues_once_quality_releases_the_bags(): void
    {
        $this->stock($this->resin, '100');
        $bags = $this->bags('QG-LOT-B', 4, '25', MaterialBagStatus::WaitingQc);

        $this->typedIssue($this->resin, '75')->assertStatus(422);

        foreach ($bags as $bag) {
            $bag->update(['status' => MaterialBagStatus::InStore]);
        }

        $this->typedIssue($this->resin, '75')->assertCreated();

        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '25', 4));
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->wip), '75', 4));
    }

    // ---- the arithmetic: held is subtracted, not the whole balance ---------

    public function test_only_the_held_kilograms_are_withheld_when_some_bags_are_released(): void
    {
        // 100 kg on the balance; two bags released, two still waiting.
        $this->stock($this->resin, '100');
        $released = $this->bags('QG-LOT-C', 2, '25', MaterialBagStatus::InStore);
        $this->bags('QG-LOT-D', 2, '25', MaterialBagStatus::WaitingQc);
        $this->assertCount(2, $released);

        // 50 kg is genuinely available and must still go through.
        $this->typedIssue($this->resin, '50')->assertCreated();
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '50', 4));

        // The remaining 50 kg is the held pair. It must not.
        $this->typedIssue($this->resin, '50')->assertStatus(422);
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '50', 4),
            'the held kilograms stayed in the store');
    }

    public function test_one_kilogram_over_the_available_figure_is_refused_to_the_fourth_decimal(): void
    {
        $this->stock($this->resin, '100.5000');
        $this->bags('QG-LOT-E', 1, '25.2500', MaterialBagStatus::WaitingQc);

        // available = 100.5000 - 25.2500 = 75.2500 exactly.
        $this->typedIssue($this->resin, '75.2501')->assertStatus(422);
        $this->typedIssue($this->resin, '75.2500')->assertCreated();

        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '25.2500', 4));
    }

    public function test_a_part_poured_bag_withholds_only_what_is_still_in_it(): void
    {
        // A bag can be part-poured and still be held only for its remainder;
        // remaining_kg is the figure, never original_kg.
        $this->stock($this->resin, '40');
        $bags = $this->bags('QG-LOT-F', 1, '25', MaterialBagStatus::WaitingQc);
        $bags[0]->update(['remaining_kg' => '10.0000']);

        $this->typedIssue($this->resin, '30')->assertCreated();
        $this->typedIssue($this->resin, '10')->assertStatus(422);
    }

    // ---- what the guard must NOT touch -------------------------------------

    public function test_a_counted_item_with_no_bags_issues_exactly_as_before(): void
    {
        $this->stock($this->tray, '5000');

        $this->typedIssue($this->tray, '1200')->assertCreated();

        $this->assertSame(0, bccomp($this->balance($this->tray, $this->store), '3800', 4));
        $this->assertSame(0, bccomp($this->balance($this->tray, $this->wip), '1200', 4));
    }

    public function test_rejected_bags_are_not_counted_as_held(): void
    {
        // QC rejection already takes the rejected kilograms OFF the balance
        // through its Rejections Out issue. Counting them again here would
        // withhold the same kilograms twice and refuse a legitimate handover.
        $this->stock($this->resin, '50');          // 100 received, 50 rejected out
        $this->bags('QG-LOT-G', 2, '25', MaterialBagStatus::InStore);
        $this->bags('QG-LOT-H', 2, '25', MaterialBagStatus::RejectedQc);

        $this->typedIssue($this->resin, '50')->assertCreated();
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '0', 4));
    }

    public function test_a_hold_in_another_store_does_not_withhold_this_store_s_material(): void
    {
        $other = Warehouse::create(['code' => 'QG-RM2', 'name' => 'QG Second Store', 'is_active' => true]);

        $this->stock($this->resin, '100');
        $held = $this->bags('QG-LOT-I', 4, '25', MaterialBagStatus::WaitingQc);
        foreach ($held as $bag) {
            $bag->update(['current_warehouse_id' => $other->id]);
        }
        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $other->id,
            'quantity' => '100.0000',
            'average_cost' => '10.0000',
        ]);

        $this->typedIssue($this->resin, '100')->assertCreated();

        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '100',
                'from_warehouse_id' => $other->id,
            ]],
        ])->assertStatus(422);
    }

    // ---- every door, not only the HTTP one ---------------------------------

    public function test_the_service_itself_refuses_it_not_merely_the_http_layer(): void
    {
        $this->stock($this->resin, '100');
        $this->bags('QG-LOT-J', 4, '25', MaterialBagStatus::WaitingQc);

        $this->expectException(ValidationException::class);

        try {
            app(StoreIssueService::class)->issue([
                'lines' => [[
                    'item_id' => $this->resin->id,
                    'quantity' => '75',
                    'from_warehouse_id' => $this->store->id,
                ]],
            ], (int) $this->storeKeeper->id);
        } finally {
            $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '100', 4));
            $this->assertSame(0, StoreIssue::query()->count());
        }
    }

    public function test_a_second_line_on_one_issue_cannot_eat_into_the_held_kilograms(): void
    {
        // Two lines of the same material on one handover: the first is legal,
        // the second reaches into the hold. The WHOLE issue must roll back —
        // a per-line check that read a stale balance would let this through.
        $this->stock($this->resin, '100');
        $this->bags('QG-LOT-K', 2, '25', MaterialBagStatus::WaitingQc);

        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [
                ['item_id' => $this->resin->id, 'quantity' => '40', 'from_warehouse_id' => $this->store->id],
                ['item_id' => $this->resin->id, 'quantity' => '40', 'from_warehouse_id' => $this->store->id],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '100', 4),
            'the first line was rolled back with the second');
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->wip), '0', 4));
        $this->assertSame(0, StoreIssue::query()->count());
    }

    // ---- the real arrival, through the real doors ---------------------------

    /**
     * NOT A HAND-BUILT FIXTURE — the whole road, over HTTP: a purchase order,
     * a goods receipt with a four-bag manifest, then the REAL incoming
     * inspection rejecting half. What the guard has to get right afterwards is
     * that the rejected kilograms are not withheld a second time: the
     * inspection already took them off the balance through its Rejections Out
     * issue, so the released half must still hand over.
     */
    public function test_a_partly_rejected_arrival_releases_exactly_the_accepted_kilograms(): void
    {
        $vendor = Vendor::create(['code' => 'QG-V1', 'name' => 'QG Test Supplier', 'is_active' => true]);

        $orderId = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100', 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');

        $poLineId = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}")
            ->assertOk()->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertOk();

        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'qg-key-1',
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'reference' => 'QG-DC-1',
            'received_date' => '2026-08-18',
            'lines' => [[
                'purchase_order_line_id' => $poLineId,
                'quantity' => '100',
                'lots' => [[
                    'supplier_lot_no' => 'QG-LOT-N',
                    'bag_count' => 4,
                    'bag_weight_kg' => '25',
                    'barcodes' => ['QG-N1', 'QG-N2', 'QG-N3', 'QG-N4'],
                ]],
            ]],
        ])->assertCreated();

        // 100 kg on the balance, 100 kg of it held: nothing may be typed out.
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '100', 4));
        $this->typedIssue($this->resin, '1')->assertStatus(422);

        // Quality accepts 50 and rejects 50 — two whole bags each way.
        $grnLineId = GoodsReceiptNoteLine::query()->latest('id')->value('id');
        $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $grnLineId,
            'inspected_quantity' => '100',
            'accepted_quantity' => '50',
            'rejected_quantity' => '50',
            'inspection_date' => '2026-08-18',
        ])->assertCreated();

        // The rejection took its 50 kg off the balance already.
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '50', 4));

        // ...and the accepted 50 kg hands over — the rejected bags are not
        // withheld on top of kilograms that have already gone.
        $this->typedIssue($this->resin, '50')->assertCreated();
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->store), '0', 4));
        $this->assertSame(0, bccomp($this->balance($this->resin, $this->wip), '50', 4));
    }

    // ---- history stays readable --------------------------------------------

    public function test_an_issue_made_before_the_guard_existed_is_still_readable(): void
    {
        $this->stock($this->resin, '100');
        $this->bags('QG-LOT-L', 2, '25', MaterialBagStatus::InStore);

        $id = $this->typedIssue($this->resin, '50')->assertCreated()->json('data.id');

        // ...and now the rest of the arrival goes on hold. The historical
        // issue is unaffected: the guard is a write-time refusal, never a
        // rewriting of what was already handed over.
        $this->bags('QG-LOT-M', 2, '25', MaterialBagStatus::WaitingQc);

        $this->getJson("/api/v1/inventory/store-issues/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->getJson('/api/v1/inventory/store-issues')->assertOk();
    }
}
