<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueBagScan;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BinBayService;
use App\Modules\Production\Services\DayBinLedgerService;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\Production\Services\ProductionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RecordsDayBinHistory;
use Tests\TestCase;

/**
 * EVERY DAY-BIN READER, WITH ITS VERDICT — the durable record of what
 * Phase 7.5 (WS-C) did to each one, pinned as behaviour rather than prose.
 *
 * The Day Bin left the TARGET workflow (DEC-20260817-001: Raw Material Store
 * → Production/WIP → Finished Goods Store, and there is no Day Bin) but
 * NOTHING was deleted: `day_bin_movements`, every historical row in it, and
 * every reader of it stay. Each reader the audit named
 * (docs/engineering/AUDIT-MATERIAL-FLOW-2026-08-17.md §3) was either
 * MIGRATED to the store-issue ledger or recorded as HISTORICAL-ONLY.
 *
 * THE ONE RULE THAT DECIDES MOST OF THE TABLE: a resin request and its issue
 * name NO machine (one common piped loading point — DEC-20260807-006, FC-01)
 * and NO batch (the trace stops at the issue; batch consumption stays
 * calculated). So a reader whose question is keyed by machine or by segment
 * has nothing in the new ledger to key by, and stays historical-only. A
 * reader whose question is factory-wide, or is about a BAG's journey, can be
 * and is migrated.
 *
 *   1. Complete Batch consumption prefill ........ HISTORICAL-ONLY
 *   2. Handover opening basis .................... HISTORICAL-ONLY
 *   3. Start Batch availability panel ............ HISTORICAL-ONLY (named gap)
 *   4. Common-resin estimate + over-load gate .... MIGRATED
 *   5. Traceability report (and its export) ...... MIGRATED (additive)
 *   6. Purchase-order trace drawer ............... MIGRATED (additive)
 *   7. Internal carton trace ..................... MIGRATED (additive)
 *   8. Cancellation blocker ...................... HISTORICAL-ONLY
 *
 * Readers 6 and 7 carry their own fixtures and are pinned in
 * PurchaseOrderTraceTest and CartonInternalTraceTest respectively.
 */
class DayBinReaderVerdictsTest extends TestCase
{
    use RecordsDayBinHistory, RefreshDatabase;

    private User $user;

    protected Item $resin;

    private Item $bottle;

    private WorkCenter $machine;

    private Shift $shift;

    private Warehouse $store;

    private Warehouse $wip;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true]);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml Bottle', 'uom' => 'Nos', 'is_active' => true]);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => true]);
    }

    /**
     * A store issue of 40 kg of resin, with the bag scan that carries the
     * bag, the lot, who issued and who received — written as the database
     * holds it. (The lifecycle itself is WS-B's subject; what is under test
     * here is which READERS see it.)
     */
    protected function issueToProduction(string $kg = '40'): StoreIssue
    {
        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $this->resin->id, 'supplier_lot_no' => 'RIL-2026-0001',
            'received_date' => '2026-08-16', 'bag_count' => 2, 'bag_weight_kg' => '25',
            'total_received_kg' => '50', 'warehouse_id' => $this->store->id,
        ], $this->user->id);
        $bag = $lot->bags->sortBy('id')->first();

        $issue = StoreIssue::create([
            'issue_number' => 'SI-2026-0001',
            'status' => StoreIssueStatus::Issued,
            'issued_by' => $this->user->id,
            'received_by' => $this->user->id,
            'issued_at' => now(),
        ]);
        $line = StoreIssueLine::create([
            'store_issue_id' => $issue->id,
            'item_id' => $this->resin->id,
            'from_warehouse_id' => $this->store->id,
            'to_warehouse_id' => $this->wip->id,
            'quantity_issued' => $kg,
            'quantity_returned' => '0',
            'uom' => 'Kgs',
        ]);
        StoreIssueBagScan::create([
            'store_issue_id' => $issue->id,
            'store_issue_line_id' => $line->id,
            'material_bag_id' => $bag->id,
            'material_lot_id' => $lot->id,
            'quantity_kg' => $kg,
            'issued_by' => $this->user->id,
            'received_by' => $this->user->id,
            'scanned_at' => now(),
        ]);

        return $issue;
    }

    private function segment(): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-08-17',
            'batch_number' => '20260817-MC01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    // ---- 1 · Complete Batch consumption prefill — HISTORICAL-ONLY -------

    public function test_the_complete_batch_prefill_stays_on_the_day_bin_and_does_not_read_store_issues(): void
    {
        $this->issueToProduction();
        $segment = $this->segment();

        $terms = app(DayBinLedgerService::class)->consumptionFor($segment, $this->resin->id);

        // The issue is invisible here, and that is the verdict: the prefill's
        // terms are per (machine, item, segment), and an issue names neither
        // a machine nor a batch (FC-01 / DEC-20260807-006). Nothing in the
        // new ledger can be keyed to this segment without inventing the link
        // FC-01 forbids.
        $this->assertSame('0.0000', $terms['loaded_kg']);
        $this->assertSame('0.0000', $terms['opening_kg']);
        // Null, never a fabricated zero: "we did not count" is not "nothing
        // was consumed", and DEC-20260807-007 records that no count is taken.
        $this->assertNull($terms['closing_kg']);
        $this->assertNull($terms['consumed_kg']);

        // And it still reads its own rows correctly — the history is intact.
        app(DayBinLedgerService::class)->record([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->resin->id,
            'shift_production_entry_id' => $segment->id,
            'type' => 'load',
            'quantity_kg' => '12',
        ]);
        $this->assertSame(
            '12.0000',
            app(DayBinLedgerService::class)->consumptionFor($segment, $this->resin->id)['loaded_kg'],
        );
    }

    // ---- 2 · Handover opening basis — HISTORICAL-ONLY -------------------

    public function test_the_handover_opening_basis_stays_on_the_day_bin(): void
    {
        $this->issueToProduction();
        $parent = $this->segment();

        app(DayBinLedgerService::class)->record([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->resin->id,
            'shift_production_entry_id' => $parent->id,
            'type' => 'count',
            'quantity_kg' => '0',
        ]);

        $child = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-08-17',
            'batch_number' => '20260817-MC01-002',
            'batch_status' => BatchStatus::InProgress,
            'parent_entry_id' => $parent->id,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);

        // The child opens from the PARENT'S COUNT, not from 40 kg standing in
        // Production/WIP: the closing count is the physical handover, and the
        // count writer (recordClosingDayBin) is untouched by this phase.
        $this->assertSame('0.0000', app(DayBinLedgerService::class)->openingFor($child, $this->resin->id));
    }

    // ---- 3 · Start Batch availability panel — HISTORICAL-ONLY + gap -----

    public function test_the_start_batch_availability_panel_is_machine_scoped_and_stays_historical(): void
    {
        $this->issueToProduction();

        $availability = app(BinBayService::class)->availabilityFor($this->machine->id, $this->resin->id);

        // Machine-scoped, so an issue that names no machine cannot fill it.
        // This is the NAMED GAP: the panel has read empty for every machine
        // since DEC-20260807-006 removed the machine-stamped load (the live
        // scan writes work_center_id NULL), and Phase 7.5 changes nothing
        // about that — it records it rather than silently converting a
        // per-machine panel into a factory-wide one.
        $this->assertSame('0.0000', $availability['available_kg']);
        $this->assertSame('0.0000', $availability['loaded_kg']);
        $this->assertSame([], $availability['layers']);
    }

    // ---- 4 · Common-resin estimate — MIGRATED ---------------------------

    public function test_the_common_resin_estimate_counts_material_issued_to_production(): void
    {
        $this->issueToProduction('40');

        $row = app(FactoryDayBinService::class)->commonResinEstimate()
            ->firstWhere(fn (array $material) => $material['item']->id === $this->resin->id);

        // Migrated: without this the estimate would keep subtracting the
        // factory's whole consumption from a numerator that had stopped
        // growing, and report a deficit that is an artefact of the migration.
        $this->assertNotNull($row, 'The estimate must see material that reached production through a store issue.');
        $this->assertSame('40.0000', $row['loaded_kg']);
        $this->assertSame('40.0000', $row['estimated_remaining_kg']);
    }

    public function test_a_cancelled_issue_is_not_material_standing_in_production(): void
    {
        $issue = $this->issueToProduction('40');
        $issue->update(['status' => StoreIssueStatus::Cancelled]);

        // A cancelled issue was reversed in full — counting it would claim
        // material stands in production that does not.
        $this->assertNull(
            app(FactoryDayBinService::class)->commonResinEstimate()
                ->firstWhere(fn (array $material) => $material['item']->id === $this->resin->id),
        );
    }

    public function test_a_returned_part_of_an_issue_stops_standing_in_production(): void
    {
        $issue = $this->issueToProduction('40');
        $issue->lines()->first()->update(['quantity_returned' => '15']);

        $row = app(FactoryDayBinService::class)->commonResinEstimate()
            ->firstWhere(fn (array $material) => $material['item']->id === $this->resin->id);

        $this->assertSame('25.0000', $row['loaded_kg']);
    }

    // ---- 5 · Traceability report — MIGRATED -----------------------------

    public function test_the_traceability_report_shows_the_issue_and_never_a_batch_for_it(): void
    {
        $issue = $this->issueToProduction('40');

        $report = app(ProductionReportService::class)
            ->traceabilityReport(null, $this->resin->id, '2026-08-01', '2026-08-31');

        $bags = collect($report['lots'])->flatMap(fn (array $lot) => $lot['bags']);
        $issued = $bags->flatMap(fn (array $bag) => $bag['issued']);

        $this->assertCount(1, $issued);
        $handover = $issued->first();
        $this->assertSame($issue->issue_number, $handover['issue_number']);
        $this->assertSame('40.0000', $handover['issued_kg']);
        $this->assertSame($this->user->id, $handover['issued_by']['id']);
        $this->assertSame($this->user->id, $handover['received_by']['id']);

        // THE TRACE STOPS AT THE ISSUE. No machine, no batch, no rate and no
        // supplier travel with it (FC-01, FC-06).
        foreach (['machine', 'segment', 'batch_number', 'work_center', 'rate', 'unit_cost', 'vendor'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $handover);
        }

        // The historical half is still there, and still empty for a bag that
        // never moved under the old flow — the two are never merged.
        $this->assertSame([], $bags->flatMap(fn (array $bag) => $bag['fed'])->all());
    }

    // ---- 8 · Cancellation blocker — HISTORICAL-ONLY ---------------------

    public function test_the_cancellation_blocker_reads_day_bin_rows_and_has_no_store_issue_twin(): void
    {
        $this->issueToProduction();
        $segment = $this->segment();

        // No store issue can be attributed to this batch — a request carries
        // a shift and, for a consumable, a work centre; never a batch. So the
        // blocker sees nothing on the new side, by design and not by
        // omission.
        $this->assertFalse($segment->dayBinMovements()->exists());

        app(DayBinLedgerService::class)->record([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->resin->id,
            'shift_production_entry_id' => $segment->id,
            'type' => 'load',
            'quantity_kg' => '5',
        ]);

        // And it still blocks on its own rows, unchanged.
        $this->assertTrue($segment->fresh()->dayBinMovements()->exists());
    }
}
