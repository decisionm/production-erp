<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StoreIssueService;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use App\Modules\TallySync\Services\TallyStockReconcileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MATERIAL STANDING IN PRODUCTION/WIP IS NOT STORE DRIFT (Phase 7.5 fix).
 *
 * Since DEC-20260817-001 a store issue moves kilograms out of the store and
 * into Production/WIP, and NO Tally voucher is raised for that move — the
 * accountant's books only ever see the CONSUMPTION, when the batch is
 * approved. (Verified: TallySyncEventServiceProvider enqueues vouchers from
 * invoices, journal entries, GRNs, deliveries, purchase orders and approved
 * production entries. Nothing enqueues from a stock movement, and nothing at
 * all listens for a store issue.)
 *
 * So the moment the store hands material over, the ERP's STORE balance falls
 * while Tally's godown balance does not. Read naively, the reconcile would
 * call that a difference and RECEIPT it back into the store — creating a
 * second copy of material that is standing on the floor, and sending the
 * accountant after a discrepancy that is simply an open issue.
 *
 * The rule these tests pin: the ERP side of a godown line includes the
 * Production/WIP balance that posts under that same godown. Where WIP posts
 * under no godown Tally knows, the kilograms are REPORTED as their own named
 * line and the item is left alone — never matched, never reported as drift.
 */
class ReconcileProductionWipTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_GODOWN = 'SWAASHPET POLYMERS PVT LTD';

    private Item $resin;

    private Warehouse $godown;

    private Warehouse $wip;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resin = Item::create([
            'sku' => 'RELPET', 'name' => 'Relpet G5801M', 'uom' => 'Kgs.', 'is_active' => true,
        ]);

        $this->godown = Warehouse::create([
            'code' => 'RM', 'name' => self::COMPANY_GODOWN,
            'is_active' => true, 'tally_guid' => 'gd-company',
        ]);

        // The WIP row the factory already has — no Tally identity of its own,
        // no parent, inactive, exactly as AUDIT-WAREHOUSES-2026-08-17 found it.
        $this->wip = Warehouse::create([
            'code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => false,
        ]);

        $this->storekeeper = User::factory()->create(['is_active' => true]);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->godown->id,
            quantity: '1000.0000',
            unitCost: '95.0000',
        );
    }

    private function issueToProduction(string $kg): void
    {
        app(StoreIssueService::class)->issue(
            ['lines' => [['item_id' => $this->resin->id, 'quantity' => $kg]]],
            $this->storekeeper->id,
        );
    }

    private function snapshot(string $closing): TallyStockSnapshot
    {
        return TallyStockSnapshot::create([
            'company' => self::COMPANY_GODOWN,
            'as_of' => '2026-08-17',
            'lines' => [[
                'tally_item_name' => 'Relpet G5801M',
                'godown' => self::COMPANY_GODOWN,
                'closing_quantity' => $closing,
                'closing_rate' => '95.0000',
                'erp_item_id' => $this->resin->id,
                'erp_item_name' => $this->resin->name,
                'erp_warehouse_id' => $this->godown->id,
                'erp_warehouse_name' => $this->godown->name,
                'importable' => true,
                'problems' => [],
            ]],
            'totals' => ['lines' => 1, 'importable' => 1],
            'status' => TallyStockSnapshot::STATUS_PENDING,
        ]);
    }

    private function balanceIn(Warehouse $warehouse): string
    {
        return bcadd((string) (StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0'), '0', 4);
    }

    /** @return array<string, mixed> */
    private function reconcile(TallyStockSnapshot $snapshot, bool $write = true): array
    {
        return app(TallyStockReconcileService::class)->apply($snapshot, null, $write);
    }

    // ---- (a) an open issue is not drift ------------------------------------

    public function test_an_open_issue_produces_no_drift(): void
    {
        $this->issueToProduction('100.0000');

        $this->assertSame('900.0000', $this->balanceIn($this->godown));
        $this->assertSame('100.0000', $this->balanceIn($this->wip));

        // Tally still holds all 1,000: no voucher is raised for a handover.
        $result = $this->reconcile($this->snapshot('1000.0000'));

        $this->assertSame(0, $result['matched'], 'an open issue must not read as a difference');
        $this->assertSame(1, $result['already_equal']);
        $this->assertSame([], $result['changes']);
        $this->assertSame([], $result['skipped']);
        $this->assertFalse(
            StockMovement::query()->where('reference', 'like', 'TALLY-RECONCILE-%')->exists(),
            'nothing may be moved to correct material that is simply on the floor',
        );

        // Neither balance is touched.
        $this->assertSame('900.0000', $this->balanceIn($this->godown));
        $this->assertSame('100.0000', $this->balanceIn($this->wip));

        // And the material is REPORTED, not merely absorbed silently.
        $this->assertSame('Work In Progress', $result['production_wip']['warehouse']);
        $this->assertSame(self::COMPANY_GODOWN, $result['production_wip']['godown']);
        $this->assertSame([[
            'item_id' => $this->resin->id,
            'item' => 'Relpet G5801M',
            'quantity' => '100.0000',
            'counted_with_godown' => true,
        ]], $result['production_wip']['lines']);
    }

    public function test_real_drift_is_still_found_while_an_issue_is_open(): void
    {
        $this->issueToProduction('100.0000');

        // 20 kg genuinely missing from the store on top of the open issue.
        app(StockMovementService::class)->recordIssue(
            itemId: $this->resin->id,
            warehouseId: $this->godown->id,
            quantity: '20.0000',
        );

        $result = $this->reconcile($this->snapshot('1000.0000'));

        $this->assertSame(1, $result['received']);
        $this->assertSame(0, $result['issued']);
        $this->assertSame('880.0000', $result['changes'][0]['erp']);
        $this->assertSame('100.0000', $result['changes'][0]['production_wip']);
        $this->assertSame('980.0000', $result['changes'][0]['erp_including_wip']);
        $this->assertSame('20.0000', $result['changes'][0]['difference']);

        // Only the 20 moves, and it moves into the STORE — never into WIP.
        $this->assertSame('900.0000', $this->balanceIn($this->godown));
        $this->assertSame('100.0000', $this->balanceIn($this->wip));
    }

    public function test_the_wip_side_is_counted_once_when_tally_holds_less(): void
    {
        $this->issueToProduction('100.0000');

        // Tally holds 950; the ERP holds 900 in the store and 100 standing in
        // production — 50 too many, taken off the store.
        $result = $this->reconcile($this->snapshot('950.0000'));

        $this->assertSame(1, $result['issued']);
        $this->assertSame('-50.0000', $result['changes'][0]['difference']);
        $this->assertSame('850.0000', $this->balanceIn($this->godown));
        $this->assertSame('100.0000', $this->balanceIn($this->wip));
    }

    // ---- (b) WIP that posts under no godown is named, never matched --------

    public function test_wip_that_aliases_to_no_godown_is_reported_and_the_item_is_left_alone(): void
    {
        $this->issueToProduction('100.0000');

        // A second Tally-linked row (the live shape the warehouse audit
        // describes) leaves Production/WIP posting under no godown at all.
        Warehouse::create([
            'code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg',
        ]);

        $result = $this->reconcile($this->snapshot('1000.0000'));

        $this->assertSame(0, $result['matched']);
        $this->assertSame([], $result['changes']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('Production/WIP', $result['skipped'][0]);
        $this->assertStringContainsString('100.0000', $result['skipped'][0]);

        $this->assertNull($result['production_wip']['godown']);
        $this->assertFalse($result['production_wip']['lines'][0]['counted_with_godown']);

        // Nothing moved: the difference is material on the floor.
        $this->assertSame('900.0000', $this->balanceIn($this->godown));
        $this->assertSame('100.0000', $this->balanceIn($this->wip));
        $this->assertFalse(
            StockMovement::query()->where('reference', 'like', 'TALLY-RECONCILE-%')->exists(),
        );
    }

    /**
     * A SKIPPED LINE IS NOT A SPENT SNAPSHOT.
     *
     * The re-run guard is the LEDGER's, not the status column's: it refuses
     * a snapshot whose movements already exist. A run that skipped every
     * line wrote no movements, so once the WIP row is parented to the
     * company godown — master data a person changes — the same snapshot
     * still reconciles. Skipping must not cost the accountant the snapshot.
     */
    public function test_a_snapshot_skipped_for_standing_material_can_still_be_reconciled_after_the_fix(): void
    {
        $this->issueToProduction('100.0000');

        $second = Warehouse::create([
            'code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg',
        ]);

        $snapshot = $this->snapshot('1000.0000');
        $this->assertCount(1, $this->reconcile($snapshot)['skipped']);

        // The ordinary fix: Production/WIP is parented to the company godown.
        $this->wip->update(['parent_id' => $this->godown->id]);
        $this->assertNotNull($second->tally_guid);

        $again = $this->reconcile($snapshot->fresh());

        $this->assertSame([], $again['skipped']);
        $this->assertSame(1, $again['already_equal']);
        $this->assertTrue($again['production_wip']['lines'][0]['counted_with_godown']);
    }

    // ---- (c) nothing standing in production changes nothing ----------------

    public function test_a_factory_with_nothing_standing_in_production_reconciles_exactly_as_before(): void
    {
        $result = $this->reconcile($this->snapshot('1200.0000'));

        $this->assertSame(1, $result['received']);
        $this->assertSame('1200.0000', $this->balanceIn($this->godown));
        $this->assertSame([], $result['production_wip']['lines']);
    }
}
