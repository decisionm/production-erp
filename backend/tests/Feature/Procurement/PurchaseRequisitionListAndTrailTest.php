<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The purchase requisition queue's way in, and its paper trail — 28-Aug
 * audit finding 8. Four contracts:
 *
 *   1. the list narrows SERVER-side: status (one value or a list), `q` in
 *      the shared grammar ("PR-12" / "pr 12" / "12", a requester's name, an
 *      item's name or SKU), a needed-by date range — and a value that could
 *      only be a mistake is a 422, never a silently full or empty list;
 *   2. approving stamps WHO and WHEN, at the moment of decision and never
 *      after (rejecting likewise); a requisition decided before the stamps
 *      existed keeps NULLs rather than inventing an approver;
 *   3. the resource carries the orders raised FROM the requisition
 *      (purchase_orders.purchase_requisition_id) — id, status, document
 *      number, and whether that order holds quantity against the
 *      requisition; never a line and never a rate. What each order has
 *      ORDERED, and what that leaves to order, is RequisitionCoverageTest;
 *   4. a line's unit of measure travels with its item, so the queue can
 *      print "500 Kgs" instead of a bare number.
 */
class PurchaseRequisitionListAndTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->desk = User::factory()->create(['name' => 'Procurement Desk', 'is_active' => true]);
        // DEC-20260902-025: every requisition below is raised by the desk, so
        // deciding one needs a second user — the requester cannot.
        $this->approver = User::factory()->create(['name' => 'Approvals Desk', 'is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->desk->givePermissionTo($permission);
            $this->approver->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->desk);
    }

    public function test_the_status_filter_narrows_server_side_and_a_nonsense_status_is_refused(): void
    {
        $this->requisition(PurchaseRequisitionStatus::Draft);
        $approved = $this->requisition(PurchaseRequisitionStatus::Approved);

        $ids = collect($this->getJson('/api/v1/procurement/purchase-requisitions?status=approved')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertSame([$approved->id], $ids->all());

        $this->getJson('/api/v1/procurement/purchase-requisitions?status=signed_off')->assertStatus(422);
    }

    public function test_q_finds_a_requisition_by_number_spelling_requester_and_item(): void
    {
        $resin = Item::create(['sku' => 'RES-9', 'name' => 'Relpet G5801M', 'uom' => 'Kgs']);
        $wanted = $this->requisition(PurchaseRequisitionStatus::Draft, $resin);
        $this->requisition(PurchaseRequisitionStatus::Draft);

        foreach (["PR-{$wanted->id}", "pr {$wanted->id}", (string) $wanted->id, 'Relpet', 'RES-9'] as $spelling) {
            $ids = collect($this->getJson('/api/v1/procurement/purchase-requisitions?q='.urlencode($spelling))->assertOk()->json('data'))
                ->pluck('id');
            $this->assertContains($wanted->id, $ids, "q={$spelling}");
        }

        // The requester's name reaches both rows here (one requester), so it
        // is asserted as "finds", not "narrows to one".
        $byName = collect($this->getJson('/api/v1/procurement/purchase-requisitions?q=Procurement+Desk')->assertOk()->json('data'));
        $this->assertNotEmpty($byName);
    }

    public function test_approving_stamps_who_and_when_and_the_resource_carries_the_trail(): void
    {
        $requisition = $this->requisition(PurchaseRequisitionStatus::Draft);

        Sanctum::actingAs($this->approver);
        $data = $this->postJson("/api/v1/procurement/purchase-requisitions/{$requisition->id}/approve")
            ->assertOk()
            ->json('data');

        $this->assertSame('approved', $data['status']);
        $this->assertSame('Approvals Desk', $data['approved_by']);
        $this->assertNotNull($data['approved_at']);
        $this->assertNull($data['rejected_by']);

        // The stamp survives to the list read.
        $row = collect($this->getJson('/api/v1/procurement/purchase-requisitions')->assertOk()->json('data'))
            ->firstWhere('id', $requisition->id);
        $this->assertSame('Approvals Desk', $row['approved_by']);
    }

    public function test_rejecting_stamps_the_other_pair(): void
    {
        $requisition = $this->requisition(PurchaseRequisitionStatus::Draft);

        Sanctum::actingAs($this->approver);
        $data = $this->postJson("/api/v1/procurement/purchase-requisitions/{$requisition->id}/reject")
            ->assertOk()
            ->json('data');

        $this->assertSame('rejected', $data['status']);
        $this->assertSame('Approvals Desk', $data['rejected_by']);
        $this->assertNotNull($data['rejected_at']);
        $this->assertNull($data['approved_by']);
    }

    public function test_a_requisition_decided_before_the_stamps_existed_reads_null_rather_than_an_invented_approver(): void
    {
        $requisition = $this->requisition(PurchaseRequisitionStatus::Approved);

        $row = collect($this->getJson('/api/v1/procurement/purchase-requisitions')->assertOk()->json('data'))
            ->firstWhere('id', $requisition->id);

        $this->assertSame('approved', $row['status']);
        $this->assertNull($row['approved_by']);
        $this->assertNull($row['approved_at']);
    }

    public function test_the_orders_raised_from_a_requisition_ride_its_row(): void
    {
        $requisition = $this->requisition(PurchaseRequisitionStatus::Approved);
        $vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha']);
        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'purchase_requisition_id' => $requisition->id,
            'status' => PurchaseOrderStatus::Draft,
            'order_date' => '2026-08-28',
        ]);

        $row = collect($this->getJson('/api/v1/procurement/purchase-requisitions')->assertOk()->json('data'))
            ->firstWhere('id', $requisition->id);

        // Plus, since the coverage build, the order's document number and
        // whether it is one of the orders HOLDING quantity against the
        // requisition (RequisitionCoverageService::reserves()). FALSE for a
        // draft: on the owner's rule nothing is held until the order goes to
        // the vendor. Still id + status + these two: no rate, no lines.
        $this->assertSame(
            [['id' => $order->id, 'status' => 'draft', 'document_number' => "PO-{$order->id}", 'reserves_quantity' => false]],
            $row['purchase_orders'],
        );
    }

    public function test_a_line_travels_with_its_item_and_the_items_uom(): void
    {
        $resin = Item::create(['sku' => 'RES-9', 'name' => 'Relpet G5801M', 'uom' => 'Kgs']);
        $requisition = $this->requisition(PurchaseRequisitionStatus::Draft, $resin);

        $row = collect($this->getJson('/api/v1/procurement/purchase-requisitions')->assertOk()->json('data'))
            ->firstWhere('id', $requisition->id);

        $this->assertSame('Kgs', $row['lines'][0]['item']['uom']);
        $this->assertSame("PR-{$requisition->id}", $row['document_number']);
    }

    private function requisition(PurchaseRequisitionStatus $status, ?Item $item = null): PurchaseRequisition
    {
        $item ??= Item::create(['sku' => 'ITEM-'.fake()->unique()->numerify('###'), 'name' => 'Item '.fake()->unique()->numerify('###'), 'uom' => 'Nos']);

        $requisition = PurchaseRequisition::create([
            'status' => $status,
            'requested_by' => $this->desk->id,
            'needed_by_date' => '2026-09-01',
        ]);
        $requisition->lines()->create(['item_id' => $item->id, 'quantity' => '500.0000']);

        return $requisition;
    }
}
