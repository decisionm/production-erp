<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\DayBinLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RecordsDayBinHistory;
use Tests\TestCase;

/**
 * ADVERSARIAL FINDING (QA attack #1 — CONSUMPTION MATH):
 *
 * DayBinLedgerService::openingFor() only carries a balance forward through
 * the explicit Phase 6 "handover" parent_entry_id chain. Ordinary shop-floor
 * reality — a fresh (non-handover) Start Batch on a machine whose bin still
 * physically holds material from the PREVIOUS batch (very common: nobody
 * empties a hopper between runs of the same/similar resin) — is not that
 * chain, so the new segment's opening is hard-coded to 0.0000 regardless of
 * what the ledger's own balanceFor() says is really sitting in the bin.
 *
 * This is not merely a false rejection (the loud symptom). It is a SILENT
 * understatement of consumption whenever the operator happens to load at
 * least as much fresh material as they later report consuming — the
 * segment's own closing-count guard (segmentHeadroom) is happy to accept a
 * closing count that is honestly BELOW the true physical balance, because
 * it only knows about ITS OWN segment's loads, not the carryover. The
 * resulting `consumed_kg` silently omits exactly the carried-over quantity.
 *
 * Two symptoms of the same root cause are asserted below:
 *   1. balanceFor() (ledger-wide) and openingFor() (segment-scoped)
 *      contradict each other for the same work_center+item at the moment
 *      the new segment starts, before segment B has done anything at all.
 *   2. The segment's reported consumed_kg understates true physical
 *      consumption by exactly the carryover, with NO error raised — the
 *      dangerous case, because a wrong number beats a loud 422 every time.
 *   3. The hard-block variant: when segment B loads nothing at all, ANY
 *      true positive closing count (even one reflecting an untouched,
 *      unconsumed carryover) is refused as "impossible", because
 *      segmentHeadroom() computes ceiling 0 + 0 − 0 = 0 for a segment that
 *      never loaded anything, ignoring what the machine's bin physically
 *      holds.
 *
 * Responsible code: DayBinLedgerService::openingFor() (parent_entry_id
 * chain only) and DayBinLedgerService::segmentHeadroom() (both scoped to
 * `shift_production_entry_id`, never to the work-center-wide balance).
 */
class DayBinCrossBatchCarryoverTest extends TestCase
{
    use RecordsDayBinHistory, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the carryover defect from the (separately covered)
        // readiness gate — minimal fixtures here deliberately have no
        // weight/cycle-time/Tally identity.
        config()->set('production.readiness.enforced', false);
        config()->set('production.traceability_enabled', true);
    }

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'inventory.manage', 'production.override-fifo'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.manage', 'inventory.manage', 'production.override-fifo']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{0: WorkCenter, 1: Item, 2: Item, 3: Shift, 4: Warehouse} */
    private function fixtures(): array
    {
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $bottle = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'Nos.']);
        $resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs.']);
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Store']);

        return [$machine, $bottle, $resin, $shift, $warehouse];
    }

    public function test_a_fresh_batch_inherits_the_bin_balance_left_by_the_previous_batch(): void
    {
        $user = $this->actingAsProduction();
        [$machine, $bottle, $resin, $shift, $warehouse] = $this->fixtures();
        $ledger = app(DayBinLedgerService::class);

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
        ], $user->id);
        [$bagA, $bagB] = $lot->bags->sortBy('id')->values();

        // ---- Batch A: loads 10kg, closes with 4kg left in the bin. -------
        $entryA = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $bottle->id, 'warehouse_id' => $warehouse->id,
        ])->assertOk()->json('data.id');

        $this->loadDayBin([
            'barcode' => $bagA->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $entryA, 'quantity_kg' => '10',
        ]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entryA}/complete", [
            'quantity_produced' => '100',
            'closing_day_bin' => [['item_id' => $resin->id, 'quantity_kg' => '4']],
        ])->assertOk();

        // Sanity: batch A's own consumption is right (0 + 10 − 4 − 0 = 6).
        $entryAModel = ShiftProductionEntry::find($entryA);
        $this->assertSame('6.0000', $ledger->consumptionFor($entryAModel, $resin->id)['consumed_kg']);

        // The bin PHYSICALLY holds 4kg right now — this is not in dispute;
        // it is the ledger's own authoritative running balance.
        $this->assertSame('4.0000', $ledger->balanceFor($machine->id, $resin->id));

        // ---- Batch B: an ordinary fresh Start Batch, NOT a handover. -----
        // No parent_entry_id — this is the everyday "next batch" path, not
        // the Phase 6 shift-continuity feature.
        $entryB = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $bottle->id, 'warehouse_id' => $warehouse->id,
        ])->assertOk()->json('data.id');
        $entryBModel = ShiftProductionEntry::find($entryB);

        // The two methods must AGREE about how much resin is at this machine.
        // They used to contradict each other: balanceFor() (ledger-wide) said
        // 4 kg while openingFor() (what segment B's own math uses) said 0,
        // because opening was only ever inherited through a handover parent.
        $this->assertSame('4.0000', $ledger->balanceFor($machine->id, $resin->id));
        $this->assertSame('4.0000', $ledger->openingFor($entryBModel, $resin->id));

        // Batch B loads 10kg of its own. Physical bin now holds 4 + 10 = 14kg.
        $this->loadDayBin([
            'barcode' => $bagB->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $entryB, 'quantity_kg' => '10',
            'override_fifo' => true,
        ]);
        $this->assertSame('14.0000', $ledger->balanceFor($machine->id, $resin->id));

        // The operator does an honest scale reading at close: 6kg remains.
        // True consumption for THIS run = 14 (physical) − 6 (closing) = 8kg.
        // The segment guard accepts it without complaint, because
        // segmentHeadroom(B) only sees B's own 10kg load as the ceiling.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryB}/complete", [
            'quantity_produced' => '100',
            'closing_day_bin' => [['item_id' => $resin->id, 'quantity_kg' => '6']],
        ])->assertOk();

        $reported = $ledger->consumptionFor($entryBModel->fresh(), $resin->id);

        // *** THE DEFECT ***
        // The app reports 4kg consumed (0 opening + 10 loaded − 6 closing).
        // Physically 8kg was consumed. The 4kg carryover vanished — not
        // flagged, not erroring, just silently missing from a number that
        // feeds straight into productionMetrics()'s issued_kg /
        // reconciliation_unaccounted_kg / blocks_approval.
        // REGRESSION GUARD. openingFor() used to return 0 for any batch with
        // no handover parent, so this reported 4.0000 and silently lost the
        // 4 kg carry-over — consumed = opening + loaded − closing − returned,
        // so a missing opening is subtracted straight out of the answer, with
        // no error and nothing flagged. It fed unaccounted_kg and approval
        // blocking.
        $this->assertSame(
            '8.0000',
            $reported['consumed_kg'],
            'Consumption must include the 4 kg the previous batch left in the bin: 4 opening + 10 loaded − 6 closing.'
        );
    }

    public function test_a_fresh_batch_that_loads_nothing_can_still_record_an_honest_closing_count(): void
    {
        $user = $this->actingAsProduction();
        [$machine, $bottle, $resin, $shift, $warehouse] = $this->fixtures();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);

        // Batch A loads 10kg, closes at 4kg — 4kg genuinely left in the bin.
        $entryA = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $bottle->id, 'warehouse_id' => $warehouse->id,
        ])->assertOk()->json('data.id');

        $this->loadDayBin([
            'barcode' => $lot->bags->first()->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $entryA, 'quantity_kg' => '10',
        ]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entryA}/complete", [
            'quantity_produced' => '100',
            'closing_day_bin' => [['item_id' => $resin->id, 'quantity_kg' => '4']],
        ])->assertOk();

        // Batch B starts fresh on the same machine. Nobody loads anything
        // new — the 4kg sitting in the bin from batch A is exactly what's
        // there. The supervisor takes an honest, UNCHANGED scale reading of
        // 4kg at close (nothing was run yet).
        $entryB = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $bottle->id, 'warehouse_id' => $warehouse->id,
        ])->assertOk()->json('data.id');

        // REGRESSION GUARD (hard-block variant of the same defect).
        // segmentHeadroom(B) used to be opening(0, no parent) + loaded(0)
        // − returned(0) = 0, so a truthful 4 kg reading was refused as
        // "impossible" and the supervisor was told to recheck a scale that
        // was right and a load that never happened. With opening inherited
        // from the ledger the headroom is 4 kg and the honest count stands.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryB}/complete", [
            'quantity_produced' => '100',
            'closing_day_bin' => [['item_id' => $resin->id, 'quantity_kg' => '4']],
        ])->assertOk();

        $this->assertSame(
            'completed',
            ShiftProductionEntry::find($entryB)->batch_status->value
        );

        // Nothing was run and nothing loaded, so nothing was consumed —
        // 4 opening − 4 closing = 0, not a phantom figure.
        $consumption = app(DayBinLedgerService::class)
            ->consumptionFor(ShiftProductionEntry::find($entryB), $resin->id);
        $this->assertSame('4.0000', $consumption['opening_kg']);
        $this->assertSame('0.0000', $consumption['consumed_kg']);
    }
}
