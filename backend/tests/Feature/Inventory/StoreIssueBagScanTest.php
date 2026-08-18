<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueBagScan;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE BAG SCAN AT THE HANDOVER (Phase 7.5, WS-B).
 *
 * The store hands bags to production against a request, and the scan is what
 * records it: which bag, from which lot, how many kilograms, issued by whom,
 * received by whom, when, and against which request. That whole chain was
 * checked link by link against FC-01 and collides with none of it — FC-01's
 * own point 2 ("a bag scan is a pour record, not consumption") is the same
 * rule this phase enforces one step earlier.
 *
 * TWO GUARDRAILS, BOTH PINNED HERE:
 *  - the trace STOPS AT THE ISSUE. The scan says the bag went to production;
 *    it never says which batch used it. Batch consumption stays calculated.
 *  - a resin scan names no machine and no area (DEC-20260807-006: one common
 *    piped loading point).
 *
 * The bag/lot resolution and the incoming-QC refusals are NOT rewritten
 * here: they are the ones FactoryDayBinService::loadBag has always applied,
 * extracted into MaterialBagIssueResolver so there is exactly one scanner in
 * the codebase.
 */
class StoreIssueBagScanTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    private User $storeKeeper;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => false]);
        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'KGS']);

        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '500.0000',
            'average_cost' => '85.0000',
        ]);

        $this->storeKeeper = $this->userWith(['inventory.manage']);
        $this->supervisor = $this->userWith(['production.manage']);
        Sanctum::actingAs($this->storeKeeper);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function bag(string $barcode = 'LOT1-B1', string $kg = '25.0000', MaterialBagStatus $status = MaterialBagStatus::InStore): MaterialBag
    {
        $lot = MaterialLot::query()->firstOrCreate(
            ['supplier_lot_no' => 'SL-9001'],
            [
                'item_id' => $this->resin->id,
                'received_date' => '2026-08-10',
                'bag_count' => 4,
                'total_received_kg' => '100.0000',
            ],
        );

        return $lot->bags()->create([
            'barcode' => $barcode,
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => $status,
            'current_warehouse_id' => $this->store->id,
        ]);
    }

    private function openIssue(): array
    {
        return $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'material_request_id' => 77,
            'lines' => [],
        ])->assertCreated()->json('data');
    }

    private function balance(Warehouse $warehouse): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0.0000');
    }

    // (a) the whole chain, in one scan --------------------------------------

    public function test_a_scan_records_bag_lot_kilograms_who_issued_who_received_and_the_request(): void
    {
        $bag = $this->bag();
        $issue = $this->openIssue();

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'LOT1-B1',
            'received_by' => $this->supervisor->id,
            'material_request_line_id' => 512,
        ])->assertCreated();

        $scan = StoreIssueBagScan::query()->sole();
        $this->assertSame($bag->id, $scan->material_bag_id);
        $this->assertSame($bag->material_lot_id, $scan->material_lot_id);
        $this->assertSame('25.0000', (string) $scan->quantity_kg);
        $this->assertSame($this->storeKeeper->id, $scan->issued_by);
        $this->assertSame($this->supervisor->id, $scan->received_by);
        $this->assertNotNull($scan->scanned_at);
        $this->assertSame(512, $scan->material_request_line_id);
        $this->assertSame(77, StoreIssue::query()->sole()->material_request_id);

        // The bag is emptied and the kilograms are now with production.
        $this->assertSame('0.0000', (string) $bag->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::Consumed, $bag->fresh()->status);
        $this->assertSame('475.0000', $this->balance($this->store));
        $this->assertSame('25.0000', $this->balance($this->wip));

        $out = StockMovement::query()->where('type', StockMovementType::TransferOut)->sole();
        $this->assertSame(StockMovementPurpose::IssueToProduction, $out->purpose);
        $this->assertStringContainsString('bag LOT1-B1', (string) $out->reference);
    }

    public function test_a_weighed_partial_scan_pours_off_only_what_was_weighed(): void
    {
        $bag = $this->bag(kg: '25.0000');
        $issue = $this->openIssue();

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'LOT1-B1',
            'quantity_kg' => '10',
            'received_by' => $this->supervisor->id,
        ])->assertCreated();

        $this->assertSame('15.0000', (string) $bag->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $bag->fresh()->status);
        $this->assertSame('10.0000', $this->balance($this->wip));
    }

    public function test_two_scans_of_the_same_material_add_up_on_one_issue_line(): void
    {
        $this->bag('LOT1-B1');
        $this->bag('LOT1-B2');
        $issue = $this->openIssue();

        foreach (['LOT1-B1', 'LOT1-B2'] as $barcode) {
            $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
                'barcode' => $barcode,
                'received_by' => $this->supervisor->id,
            ])->assertCreated();
        }

        $issue = StoreIssue::query()->with('lines')->sole();
        $this->assertCount(1, $issue->lines);
        $this->assertSame('50.0000', (string) $issue->lines->sole()->quantity_issued);
        $this->assertSame(2, StoreIssueBagScan::query()->count());
        $this->assertSame('50.0000', $this->balance($this->wip));
    }

    // (b) the refusals, unchanged from the one scanner -----------------------

    public function test_a_bag_waiting_for_incoming_qc_is_refused(): void
    {
        $this->bag(status: MaterialBagStatus::WaitingQc);
        $issue = $this->openIssue();

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'LOT1-B1',
            'received_by' => $this->supervisor->id,
        ])->assertStatus(422)->assertJsonPath(
            'errors.barcode.0',
            'Bag LOT1-B1 is waiting for incoming QC — it cannot be loaded until quality releases it.',
        );

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StoreIssueBagScan::query()->count());
    }

    public function test_a_bag_rejected_by_incoming_qc_is_refused(): void
    {
        $this->bag(status: MaterialBagStatus::RejectedQc);
        $issue = $this->openIssue();

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'LOT1-B1',
            'received_by' => $this->supervisor->id,
        ])->assertStatus(422)->assertJsonPath(
            'errors.barcode.0',
            'Bag LOT1-B1 was rejected by incoming QC — it is out of usable stock and cannot be loaded.',
        );

        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_an_unknown_barcode_and_an_over_weighed_scan_are_both_refused(): void
    {
        $this->bag(kg: '25.0000');
        $issue = $this->openIssue();

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'NOT-A-BAG',
            'received_by' => $this->supervisor->id,
        ])->assertStatus(422)->assertJsonPath(
            'errors.barcode.0',
            'Unknown bag barcode — no registered bag carries this code.',
        );

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'LOT1-B1',
            'quantity_kg' => '40',
            'received_by' => $this->supervisor->id,
        ])->assertStatus(422);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('500.0000', $this->balance($this->store));
    }

    public function test_a_scan_onto_a_closed_issue_is_refused(): void
    {
        $this->bag();
        $issue = $this->openIssue();
        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/complete")->assertOk();

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'LOT1-B1',
            'received_by' => $this->supervisor->id,
        ])->assertStatus(422);

        $this->assertSame(0, StoreIssueBagScan::query()->count());
    }

    // (c) FC-01: no machine on a resin scan, no batch behind a bag -----------

    public function test_a_resin_scan_carries_no_machine_and_no_batch(): void
    {
        $this->bag();
        $issue = $this->openIssue();

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'LOT1-B1',
            'received_by' => $this->supervisor->id,
        ])->assertCreated();

        $columns = array_keys(StoreIssueBagScan::query()->sole()->getAttributes());
        foreach (['work_center_id', 'machine_id', 'shift_production_entry_id', 'batch_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "FC-01: a bag belongs to no machine and no batch — \"{$forbidden}\" must not exist on a scan.");
        }
    }

    public function test_the_scan_never_writes_a_day_bin_row(): void
    {
        $this->bag();
        $issue = $this->openIssue();

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'LOT1-B1',
            'received_by' => $this->supervisor->id,
        ])->assertCreated();

        // The Day Bin leaves the TARGET workflow (DEC-20260817-001). Its
        // table and every historical row stay exactly as they are; the new
        // handover simply does not write to it.
        $this->assertSame(0, DayBinMovement::query()->count());
    }

    // (d) FC-06: a scan shows kilograms, never rupees ------------------------

    public function test_a_scan_shows_kilograms_and_never_the_rate_behind_the_bag(): void
    {
        $lot = MaterialLot::query()->create([
            'item_id' => $this->resin->id,
            'supplier_lot_no' => 'SL-RATE',
            'received_date' => '2026-08-10',
            'bag_count' => 1,
            'total_received_kg' => '25.0000',
            'receipt_rate_per_kg' => '92.5000',
        ]);
        $lot->bags()->create([
            'barcode' => 'RATE-B1',
            'original_kg' => '25.0000',
            'remaining_kg' => '25.0000',
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ]);

        $issue = $this->openIssue();
        $created = $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/bag-scans", [
            'barcode' => 'RATE-B1',
            'received_by' => $this->supervisor->id,
        ])->assertCreated()->json();

        $shown = json_encode($created).json_encode($this->getJson("/api/v1/inventory/store-issues/{$issue['id']}")->json());

        foreach (['92.5000', 'receipt_rate_per_kg', 'unit_cost', 'average_cost'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $shown, "FC-06: \"{$forbidden}\" must never reach a store reader.");
        }
    }

    // (e) the old scanner is untouched --------------------------------------

    public function test_the_common_input_scan_still_writes_no_stock_movement(): void
    {
        $this->bag();
        app(FactoryDayBinService::class)->setWarehouseId($this->wip->id);

        app(FactoryDayBinService::class)
            ->loadBag('LOT1-B1', null, $this->supervisor->id);

        // CommonInputLoadIsNotStockTest's central pin, restated here so the
        // extraction of the shared resolver cannot quietly change it.
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(1, DayBinMovement::query()->count());
    }
}
