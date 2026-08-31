<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * EVERY WEIGHED ARRIVAL IS COUNTED AT THE GATE — owner decision, 31-Aug-2026,
 * answering Q77, plus the QA clause answered with it.
 *
 * WHAT WAS WRONG. Lot and bag traceability was switched on and had never
 * produced a single row, because the lots block was optional on a goods
 * receipt. Nothing was mandatory, so nothing was entered; incoming QC acts on
 * BAGS, so it had nothing to hold; and the whole chain sat inert while every
 * screen reported it as enabled. `returned = 0` across the entire ledger was
 * not a floor that never returns anything — it was a system nobody could
 * write to.
 *
 * THE RULE IS NARROWER THAN THE DECISION, AND THAT IS DELIBERATE. The owner's
 * answer was "every purchased material". The lot machinery cannot do that:
 * GoodsReceiptService refuses a lots block outright for anything not measured
 * in kg, and the reconciliation under it is arithmetic in KILOGRAMS — a
 * nominal or per-bag weight is mandatory and bag_weight x bag_count must
 * equal the received line quantity.
 *
 * Applied literally, a counted material would be REQUIRED to carry a block
 * the service REFUSES, so cartons, trays and film could not be received at
 * all — and the only way to satisfy both rules would be to invent a kilogram
 * weight for a carton, putting a fiction into the stock ledger and into
 * Tally. So the rule reaches the weighed materials it was built for, counted
 * packaging is untouched, and extending it is an owner question with a model
 * change behind it. The second and third tests below are what stop somebody
 * "completing" the decision by deleting the unit check.
 */
class EveryWeighedArrivalIsCountedTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->store = Warehouse::create(['code' => 'EW-STORE', 'name' => 'EW Store', 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.manage', 'inventory.manage', 'quality.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    /** @return array{0: int, 1: int} order id and line id */
    private function order(string $uom, string $quantity = '100'): array
    {
        $item = Item::create([
            'sku' => 'EW-'.$uom.'-1', 'name' => 'EW '.$uom, 'uom' => $uom,
            'is_active' => true, 'is_production_input' => true,
        ]);
        $vendor = Vendor::create(['code' => 'EW-SUP-'.$uom, 'name' => 'EW Supplier']);
        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'status' => PurchaseOrderStatus::Sent,
            'order_date' => '2026-08-15',
        ]);
        $line = $order->lines()->create([
            'item_id' => $item->id, 'quantity' => $quantity,
            'unit_price' => '10', 'quantity_received' => '0',
        ]);

        return [$order->id, $line->id];
    }

    /** @param  array<string, mixed>|null  $lots */
    private function receive(int $orderId, int $lineId, ?array $lots, string $quantity = '100'): TestResponse
    {
        $line = ['purchase_order_line_id' => $lineId, 'quantity' => $quantity];

        if ($lots !== null) {
            $line['lots'] = $lots;
        }

        return $this->postJson('/api/v1/procurement/goods-receipts', [
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-08-15',
            'lines' => [$line],
        ]);
    }

    /* ------------------------------------------------------------------ *
     * The rule
     * ------------------------------------------------------------------ */

    public function test_a_weighed_arrival_cannot_be_booked_without_saying_what_arrived(): void
    {
        [$orderId, $lineId] = $this->order('Kgs');

        // Indexed rather than dot-pathed: the KEY contains dots, and
        // Laravel's json() resolves dots as nesting.
        $errors = $this->receive($orderId, $lineId, null)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.lots')
            ->json('errors');

        $message = (string) $errors['lines.0.lots'][0];

        // The storekeeper is standing at the gate with a delivery. Laravel's
        // default here is "The lines.0.lots field is required", which names a
        // JSON path and cannot be acted on.
        $this->assertStringContainsString('how many bags', $message);
        $this->assertStringNotContainsString('lines.0', $message);

        $this->assertSame(0, GoodsReceiptNote::query()->count(), 'the refusal wrote nothing');
    }

    public function test_a_weighed_arrival_with_its_bags_is_booked(): void
    {
        [$orderId, $lineId] = $this->order('Kgs');

        $this->receive($orderId, $lineId, [['bag_count' => 4, 'bag_weight_kg' => '25']])->assertCreated();

        $this->assertSame(4, MaterialBag::query()->count(), 'four bags, one row each');
    }

    /**
     * COUNTED PACKAGING IS UNTOUCHED, and this is the test that stops the
     * rule being "completed" later by deleting the unit check. Bag lots are
     * kg-only in the service; requiring them here would make cartons
     * unreceivable by either door.
     */
    public function test_a_counted_arrival_is_booked_without_bags(): void
    {
        [$orderId, $lineId] = $this->order('Nos');

        $this->receive($orderId, $lineId, null)->assertCreated();

        $this->assertSame(0, MaterialBag::query()->count(), 'a carton is not a bag and must not be forced to be one');
    }

    /** And the service still refuses one if a caller sends it anyway. */
    public function test_a_counted_arrival_that_sends_bags_is_refused_by_the_service(): void
    {
        [$orderId, $lineId] = $this->order('Nos');

        $this->receive($orderId, $lineId, [['bag_count' => 4, 'bag_weight_kg' => '25']])->assertStatus(422);
    }

    /**
     * THE RULE FOLLOWS THE FEATURE FLAG. Bags and lots live behind
     * production.traceability_enabled and the whole surface 404s without it,
     * so demanding a block that cannot exist would refuse every goods receipt
     * on such an instance.
     */
    public function test_with_traceability_off_a_weighed_arrival_needs_no_bags(): void
    {
        config(['production.traceability_enabled' => false]);
        [$orderId, $lineId] = $this->order('Kgs');

        $this->receive($orderId, $lineId, null)->assertCreated();
    }

    /* ------------------------------------------------------------------ *
     * The QA clause answered alongside it
     * ------------------------------------------------------------------ */

    /**
     * EVERY ARRIVAL WAITS FOR QA (owner decision, 31-Aug-2026). This was
     * already built — bags are born waiting_qc and held stock may not leave a
     * store by any door (DEC-20260825-001) — and it had simply never fired,
     * because no arrival ever created a bag. Making the lots block mandatory
     * is what switches it on, so the two decisions are one behaviour.
     */
    public function test_what_just_arrived_is_held_for_qa_and_released_by_inspection(): void
    {
        [$orderId, $lineId] = $this->order('Kgs');
        $this->receive($orderId, $lineId, [['bag_count' => 4, 'bag_weight_kg' => '25']])->assertCreated();

        $this->assertSame(
            [MaterialBagStatus::WaitingQc->value],
            MaterialBag::query()->pluck('status')->map->value->unique()->values()->all(),
            'a bag is born waiting for incoming QC',
        );

        $grnLineId = GoodsReceiptNote::query()->sole()->lines()->value('id');

        $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $grnLineId,
            'inspected_quantity' => '100',
            'accepted_quantity' => '100',
            'rejected_quantity' => '0',
            'inspection_date' => '2026-08-16',
        ])->assertSuccessful();

        $this->assertSame(
            [MaterialBagStatus::InStore->value],
            MaterialBag::query()->pluck('status')->map->value->unique()->values()->all(),
            'and QA acceptance is what makes it usable',
        );
    }
}
