<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE TWO HALVES OF PHASE 7.5, WIRED TOGETHER.
 *
 * WS-A built the material request and its fulfilment writer
 * (MaterialRequestService::applyIssuedQuantities — the one writer of
 * `issued_quantity` and the only place submitted → partially_issued →
 * issued happens). WS-B built the store issue. Nothing called the first
 * from the second, so issuing against a request left the request untouched:
 * the remaining quantity never fell, the status never advanced, and the
 * store's queue showed this morning's completed work as still owed, for
 * ever.
 *
 * This file is that seam, from the outside — through the API, on both
 * handover paths (typed lines and scanned bags), and read back through the
 * store's own queue rather than off a model.
 *
 * THE TWO REMAINING FIGURES MUST AGREE. `remaining_quantity` on the request
 * line is derived from the stored `issued_quantity`;
 * `quantity_remaining_on_request` on the issue line is recomputed from the
 * issue rows. They answer the same question from opposite sides and a
 * screen may show either, so every case below asserts both.
 *
 * NONE OF THIS IS A CONSUMPTION. A fulfilled request means the material has
 * moved from the Raw Material Store into Production/WIP (DEC-20260817-001)
 * and is standing there, unconsumed.
 */
class StoreIssueFulfilsTheRequestTest extends TestCase
{
    use RefreshDatabase;

    private Item $carton;

    private Warehouse $store;

    private Warehouse $wip;

    private User $storeKeeper;

    protected function setUp(): void
    {
        parent::setUp();

        // The bag-scan route sits behind the traceability gate: a barcode
        // only resolves to a MaterialBag with it on.
        config(['production.traceability_enabled' => true]);

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => false]);

        // Nos, not kg: a carton is not a common input, so a request for it
        // may name a work centre and nothing here trips FC-01's refusal.
        $this->carton = Item::create(['sku' => 'PKG-CTN', 'name' => 'Carton 500ml', 'uom' => 'Nos', 'is_production_input' => true]);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->carton->id,
            warehouseId: $this->store->id,
            quantity: '1000',
            unitCost: '12',
            reference: 'Opening',
            purpose: StockMovementPurpose::Opening,
        );

        $this->storeKeeper = User::factory()->create(['is_active' => true]);
        foreach (['inventory.manage', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->storeKeeper->givePermissionTo(['inventory.manage', 'production.manage']);

        Sanctum::actingAs($this->storeKeeper);
    }

    /** A submitted request for 500 cartons, and its one line id. */
    private function submittedRequest(string $quantity = '500'): array
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->carton->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        return [$request['id'], $request['lines'][0]['id']];
    }

    /** The request as the store's queue shows it — never off the model. */
    private function fromQueue(int $requestId): array
    {
        return $this->getJson("/api/v1/inventory/material-requests/{$requestId}")->assertOk()->json('data');
    }

    public function test_issuing_part_of_a_request_advances_it_and_issuing_the_rest_closes_it(): void
    {
        [$requestId, $lineId] = $this->submittedRequest('500');

        $first = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->carton->id,
                'quantity' => '200',
                'material_request_line_id' => $lineId,
                'quantity_requested' => '500',
            ]],
        ])->assertCreated()->json('data');

        $queued = $this->fromQueue($requestId);
        $this->assertSame('partially_issued', $queued['status']);
        $this->assertSame('200.0000', $queued['lines'][0]['issued_quantity']);
        $this->assertSame('300.0000', $queued['lines'][0]['remaining_quantity']);
        // The store may still fulfil the rest, and the floor may still
        // withdraw the REMAINDER.
        $this->assertTrue($queued['can']['issue']);
        $this->assertTrue($queued['can']['cancel']);

        // The other side of the same number.
        $this->assertSame('300.0000', $first['lines'][0]['quantity_remaining_on_request']);

        $second = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->carton->id,
                'quantity' => '300',
                'material_request_line_id' => $lineId,
                'quantity_requested' => '500',
            ]],
        ])->assertCreated()->json('data');

        $queued = $this->fromQueue($requestId);
        $this->assertSame('issued', $queued['status']);
        $this->assertSame('500.0000', $queued['lines'][0]['issued_quantity']);
        $this->assertSame('0.0000', $queued['lines'][0]['remaining_quantity']);
        $this->assertFalse($queued['can']['issue']);
        $this->assertFalse($queued['can']['cancel']);

        $this->assertSame('0.0000', $second['lines'][0]['quantity_remaining_on_request']);

        // And it is STILL not a consumption: the 500 are standing in
        // Production/WIP, not used up.
        $this->assertSame('500.0000', $this->wipBalance());
    }

    public function test_a_bag_scanned_onto_an_issue_fulfils_the_request_line_too(): void
    {
        // The resin path: the store opens an EMPTY issue and scans bags onto
        // it. Wiring only the typed-lines path would leave the queue stale
        // for the one material that actually moves this way.
        $resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'KGS', 'is_production_input' => true]);
        [$requestId, $lineId] = $this->resinRequest($resin, '50');
        $bag = $this->bagOfResin($resin, '25');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => $bag,
            'material_request_line_id' => $lineId,
        ])->assertCreated();

        $queued = $this->fromQueue($requestId);
        $this->assertSame('partially_issued', $queued['status']);
        $this->assertSame('25.0000', $queued['lines'][0]['issued_quantity']);
        $this->assertSame('25.0000', $queued['lines'][0]['remaining_quantity']);
    }

    public function test_two_bags_scanned_against_two_request_lines_credit_one_line_each(): void
    {
        // ONE ISSUE LINE, TWO REQUEST LINES. The issue line is found by
        // (material, source store), so both bags of the same resin land on
        // it — and its own material_request_line_id is whatever the FIRST
        // scan set. Crediting that id would put the second bag on the first
        // line, leaving one request line over-fulfilled and the other
        // showing work as still owed that was done this morning.
        $resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'KGS', 'is_production_input' => true]);
        [$requestId, $lineIds] = $this->twoLineResinRequest($resin, '25', '25');
        $bags = $this->twoBagsOfResin($resin, '25');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [],
        ])->assertCreated()->json('data');

        foreach ([0, 1] as $i) {
            $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
                'barcode' => $bags[$i],
                'material_request_line_id' => $lineIds[$i],
            ])->assertCreated();
        }

        $queued = $this->fromQueue($requestId);
        $this->assertSame('issued', $queued['status']);
        $this->assertSame(['25.0000', '25.0000'], array_column($queued['lines'], 'issued_quantity'));
        $this->assertSame(['0.0000', '0.0000'], array_column($queued['lines'], 'remaining_quantity'));
    }

    public function test_the_store_may_hand_over_more_than_was_asked_for_and_the_request_still_closes(): void
    {
        // A bag is not divisible. 500 asked, 520 in the carton stack that
        // came off the pallet — the overage is visible and remaining floors
        // at zero, exactly as applyIssuedQuantities documents.
        [$requestId, $lineId] = $this->submittedRequest('500');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->carton->id,
                'quantity' => '520',
                'material_request_line_id' => $lineId,
                'quantity_requested' => '500',
            ]],
        ])->assertCreated()->json('data');

        $queued = $this->fromQueue($requestId);
        $this->assertSame('issued', $queued['status']);
        $this->assertSame('520.0000', $queued['lines'][0]['issued_quantity']);
        $this->assertSame('0.0000', $queued['lines'][0]['remaining_quantity']);
        $this->assertSame('0.0000', $issue['lines'][0]['quantity_remaining_on_request']);
    }

    public function test_cancelling_the_handover_gives_the_request_its_remainder_back(): void
    {
        [$requestId, $lineId] = $this->submittedRequest('500');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->carton->id,
                'quantity' => '500',
                'material_request_line_id' => $lineId,
                'quantity_requested' => '500',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('issued', $this->fromQueue($requestId)['status']);

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/cancel", [
            'reason' => 'Handed to the wrong shift',
        ])->assertOk();

        // The cartons went straight back on the shelf, so the request is
        // owed in full again — and the store's queue has to say so, or it
        // shows work as done that nobody did.
        $queued = $this->fromQueue($requestId);
        $this->assertSame('submitted', $queued['status']);
        $this->assertSame('0.0000', $queued['lines'][0]['issued_quantity']);
        $this->assertSame('500.0000', $queued['lines'][0]['remaining_quantity']);
        $this->assertTrue($queued['can']['issue']);

        // Both sides agree again: the issue-side figure already excluded
        // cancelled issues, which is exactly why the request side had to
        // move too.
        $reread = $this->getJson("/api/v1/inventory/store-issues/{$issue['id']}")->assertOk()->json('data');
        $this->assertSame('500.0000', $reread['lines'][0]['quantity_remaining_on_request']);
        $this->assertSame('1000.0000', $this->storeBalance());
    }

    public function test_a_return_does_not_un_issue_the_request(): void
    {
        // A return is a LATER, separate fact: the store did hand the
        // material over and some of it came back. The issue side records it
        // the same way (quantity_remaining_on_request reads quantity_issued,
        // not the net), so the request side must not move either.
        [$requestId, $lineId] = $this->submittedRequest('500');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->carton->id,
                'quantity' => '500',
                'material_request_line_id' => $lineId,
                'quantity_requested' => '500',
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '100']],
        ])->assertOk();

        $queued = $this->fromQueue($requestId);
        $this->assertSame('issued', $queued['status']);
        $this->assertSame('500.0000', $queued['lines'][0]['issued_quantity']);
        $this->assertSame('0.0000', $queued['lines'][0]['remaining_quantity']);
    }

    public function test_an_issue_naming_a_line_that_is_not_on_the_request_is_refused_and_moves_nothing(): void
    {
        [$requestId] = $this->submittedRequest('500');

        $movementsBefore = StockMovement::query()->count();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->carton->id,
                'quantity' => '200',
                'material_request_line_id' => 9999,
                'quantity_requested' => '500',
            ]],
        ])->assertStatus(422);

        // The request writer refuses INSIDE the issue's transaction, so the
        // whole handover rolls back — no issue row, no stock movement, and
        // the store's balance untouched.
        $this->assertSame(0, StoreIssue::query()->count());
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame('1000.0000', $this->storeBalance());
        $this->assertSame('submitted', $this->fromQueue($requestId)['status']);
    }

    public function test_a_handover_against_a_cancelled_request_is_refused(): void
    {
        [$requestId, $lineId] = $this->submittedRequest('500');

        $this->postJson("/api/v1/inventory/material-requests/{$requestId}/cancel", [
            'reason' => 'Run pulled',
        ])->assertOk();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->carton->id,
                'quantity' => '200',
                'material_request_line_id' => $lineId,
                'quantity_requested' => '500',
            ]],
        ])->assertStatus(422);

        $this->assertSame(0, StoreIssue::query()->count());
        $this->assertSame('1000.0000', $this->storeBalance());
        $this->assertSame('cancelled', $this->fromQueue($requestId)['status']);
    }

    public function test_a_handover_against_no_request_at_all_is_still_recorded(): void
    {
        // The store may record a handover made against a verbal ask. Wiring
        // the request in must not make a request compulsory.
        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $this->carton->id, 'quantity' => '200']],
        ])->assertCreated()->json('data');

        $this->assertNull($issue['material_request_id']);
        $this->assertSame(0, MaterialRequest::query()->count());
        $this->assertSame('200.0000', $this->wipBalance());
    }

    // ---- helpers -----------------------------------------------------------

    private function storeBalance(?Item $item = null): string
    {
        return $this->balanceAt($this->store, $item ?? $this->carton);
    }

    private function wipBalance(?Item $item = null): string
    {
        return $this->balanceAt($this->wip, $item ?? $this->carton);
    }

    private function balanceAt(Warehouse $warehouse, Item $item): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0.0000');
    }

    /**
     * A submitted request for resin — raised WITHOUT a work centre, because
     * a kg item is a common input and naming a machine on it is refused
     * (FC-01 / DEC-20260807-006).
     *
     * @return array{0: int, 1: int}
     */
    private function resinRequest(Item $resin, string $quantity): array
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $resin->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        return [$request['id'], $request['lines'][0]['id']];
    }

    /** One 25 kg bag of resin, sitting in the store, ready to be scanned. */
    private function bagOfResin(Item $resin, string $kg): string
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $resin->id,
            warehouseId: $this->store->id,
            quantity: $kg,
            unitCost: '85',
            reference: 'Opening',
            purpose: StockMovementPurpose::Opening,
        );

        $lot = MaterialLot::create([
            'item_id' => $resin->id,
            'supplier_lot_no' => 'SL-9001',
            'received_date' => '2026-08-10',
            'bag_count' => 1,
            'total_received_kg' => $kg,
        ]);

        return $lot->bags()->create([
            'barcode' => 'LOT1-B1',
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ])->barcode;
    }

    /**
     * A submitted resin request with TWO lines for the same material — the
     * shape a two-bag handover has to keep apart.
     *
     * @return array{0: int, 1: array<int, int>}
     */
    private function twoLineResinRequest(Item $resin, string $first, string $second): array
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [
                ['item_id' => $resin->id, 'quantity' => $first],
                ['item_id' => $resin->id, 'quantity' => $second],
            ],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        return [$request['id'], array_column($request['lines'], 'id')];
    }

    /**
     * Two bags of one lot, both in the store.
     *
     * @return array<int, string> barcodes
     */
    private function twoBagsOfResin(Item $resin, string $kg): array
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $resin->id,
            warehouseId: $this->store->id,
            quantity: bcmul($kg, '2', 4),
            unitCost: '85',
            reference: 'Opening',
            purpose: StockMovementPurpose::Opening,
        );

        $lot = MaterialLot::create([
            'item_id' => $resin->id,
            'supplier_lot_no' => 'SL-9002',
            'received_date' => '2026-08-10',
            'bag_count' => 2,
            'total_received_kg' => bcmul($kg, '2', 4),
        ]);

        return collect(['LOT2-B1', 'LOT2-B2'])->map(fn (string $barcode) => $lot->bags()->create([
            'barcode' => $barcode,
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ])->barcode)->all();
    }
}
