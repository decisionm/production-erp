<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The reports wave: read-only production / reconciliation / traceability
 * report endpoints. Central rule under test (SHIFT-REDESIGN-FORMULAS.md
 * row 24, WB2 totals row): period efficiency is Σ actual boxes / Σ
 * expected boxes × 100 — a ratio of sums, NEVER the average of row
 * percentages — and rows with null expected boxes (standards not
 * captured) contribute to NEITHER sum.
 *
 * Fixture: 2 machines × 2 completed entries each, one of them missing
 * standards. Eligible rows: machine A 7/16, machine B 7/8 and 6/7 (13/15)
 * → total 20/31 = 64.5 (the average of the row percentages would be 72.3).
 */
class ReportEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsViewer(): User
    {
        // production.view ONLY — the reports are GETs under
        // module:production, so view must be enough.
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{shift: Shift, machineA: WorkCenter, machineB: WorkCenter, resin: Item, rmStore: Warehouse}
     */
    private function fixture(): array
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machineA = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $machineB = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2']);
        $fgStore = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
        $rmStore = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);
        $resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $bottleA = Item::create([
            'sku' => 'BTL-840', 'name' => 'Bottle 840-pack', 'uom' => 'NOS',
            'nominal_weight_grams' => '12', 'nos_per_box' => 840,
        ]);
        $bottleB = Item::create([
            'sku' => 'BTL-1500', 'name' => 'Bottle 1500-pack', 'uom' => 'NOS',
            'nominal_weight_grams' => '10', 'nos_per_box' => 1500,
        ]);

        $entry = function (array $attributes) use ($shift, $fgStore): ShiftProductionEntry {
            return ShiftProductionEntry::create($attributes + [
                'shift_id' => $shift->id,
                'warehouse_id' => $fgStore->id,
                'production_date' => '2026-07-27',
                'batch_status' => BatchStatus::Completed,
                'quantity_scrap' => '0',
            ]);
        };

        // Machine A row 1 — WB2 row 6 standards: CT 10.6 × 5 cav × 8 h /
        // pack 840 → 16 expected boxes. Row efficiency is piece-grain:
        // 5880/13584.9057 = 43.283… → 43.3 (the 7/16 boxes feed only the
        // totals-row ratio, which stays the WB2 box formula).
        $a1 = $entry([
            'work_center_id' => $machineA->id, 'item_id' => $bottleA->id,
            'batch_number' => '20260727-M01-001',
            'standard_cycle_time' => '10.6', 'active_cavities' => 5, 'running_hours' => '8',
            'nos_per_box' => 840, 'no_of_box' => 7,
            'quantity_produced' => '5880', 'quantity_produced_kg' => '70.5600',
            'quantity_rejection_kg' => '7.7529', 'qc_rejection_kg' => '7.75',
        ]);
        $a1->materialConsumptions()->create(['item_id' => $resin->id, 'warehouse_id' => $rmStore->id, 'quantity_issued_kg' => '80']);
        $a1->scraps()->create(['type' => 'lumps', 'quantity_kg' => '0.55']);

        // Machine A row 2 — the missing-standards entry: no cycle time →
        // expected null, efficiency null; contributes to NEITHER side of
        // the ratio-of-sums even though it produced 5 actual boxes.
        $a2 = $entry([
            'work_center_id' => $machineA->id, 'item_id' => $bottleA->id,
            'batch_number' => '20260727-M01-002',
            'running_hours' => '8',
            'nos_per_box' => 840, 'no_of_box' => 5,
            'quantity_produced' => '4200', 'quantity_produced_kg' => '50.4000',
        ]);
        $a2->materialConsumptions()->create(['item_id' => $resin->id, 'warehouse_id' => $rmStore->id, 'quantity_issued_kg' => '50']);

        // Machine B row 1 — CT 12 × 5 × 8 h / pack 1500 → 8 expected
        // boxes; piece-grain 10500/12000 = 87.5.
        $b1 = $entry([
            'work_center_id' => $machineB->id, 'item_id' => $bottleB->id,
            'batch_number' => '20260727-M02-001',
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8',
            'nos_per_box' => 1500, 'no_of_box' => 7,
            'quantity_produced' => '10500', 'quantity_produced_kg' => '105.0000',
            'quantity_rejection_kg' => '2.1', 'qc_rejection_kg' => '2',
        ]);
        $b1->materialConsumptions()->create(['item_id' => $resin->id, 'warehouse_id' => $rmStore->id, 'quantity_issued_kg' => '110']);
        $b1->scraps()->create(['type' => 'lumps', 'quantity_kg' => '1']);

        // Machine B row 2 — CT 12 × 5 × 7 h / pack 1500 → 7 expected
        // boxes; piece-grain 6300/10500 = 60.0 (the 6 counted boxes imply
        // 9000 pieces — this fixture's deliberate box/piece mismatch is
        // exactly what the piece grain exposes). No QC weighing →
        // production figure confirms.
        $b2 = $entry([
            'work_center_id' => $machineB->id, 'item_id' => $bottleB->id,
            'batch_number' => '20260727-M02-002',
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '7',
            'nos_per_box' => 1500, 'no_of_box' => 6,
            'quantity_produced' => '6300', 'quantity_produced_kg' => '63.0000',
            'quantity_rejection_kg' => '0.8',
        ]);
        $b2->materialConsumptions()->create(['item_id' => $resin->id, 'warehouse_id' => $rmStore->id, 'quantity_issued_kg' => '70']);
        $b2->scraps()->create(['type' => 'lumps', 'quantity_kg' => '0.2']);

        // Still running — completed-only reports must never show it.
        ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machineA->id,
            'item_id' => $bottleA->id, 'warehouse_id' => $fgStore->id,
            'production_date' => '2026-07-27', 'batch_number' => '20260727-M01-999',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null, 'quantity_scrap' => '0',
        ]);

        return [
            'shift' => $shift, 'machineA' => $machineA, 'machineB' => $machineB,
            'resin' => $resin, 'rmStore' => $rmStore,
        ];
    }

    public function test_production_report_rows_and_ratio_of_sums_totals(): void
    {
        $this->actingAsViewer();
        $this->fixture();

        $response = $this->getJson('/api/v1/production/reports/production?date=2026-07-27')
            ->assertOk()
            ->assertJsonCount(4, 'data.rows');

        // Row detail (machine A entry 1): every column of the daily sheet.
        // Keys are the ProductionMetrics names VERBATIM — the frontend
        // types are written against them.
        $response->assertJsonPath('data.rows.0.batch_number', '20260727-M01-001')
            ->assertJsonPath('data.rows.0.work_center.code', 'MC-01')
            ->assertJsonPath('data.rows.0.shift.name', 'Morning')
            ->assertJsonPath('data.rows.0.item.sku', 'BTL-840')
            ->assertJsonPath('data.rows.0.running_hours', '8.00')
            ->assertJsonPath('data.rows.0.expected_pieces', '13584.91')
            ->assertJsonPath('data.rows.0.expected_boxes', 16)
            ->assertJsonPath('data.rows.0.actual_boxes', 7)
            ->assertJsonPath('data.rows.0.actual_pieces', '5880')
            ->assertJsonPath('data.rows.0.good_production_kg', '70.5600')
            ->assertJsonPath('data.rows.0.rejection_kg_production', '7.7529')
            ->assertJsonPath('data.rows.0.rejection_kg_qc', '7.7500')
            ->assertJsonPath('data.rows.0.lumps_kg', '0.5500')
            ->assertJsonPath('data.rows.0.efficiency_pct', 43.3);

        // The missing-standards entry reports its actuals with null
        // expectations — never a fake number.
        $response->assertJsonPath('data.rows.1.batch_number', '20260727-M01-002')
            ->assertJsonPath('data.rows.1.expected_boxes', null)
            ->assertJsonPath('data.rows.1.actual_boxes', 5)
            ->assertJsonPath('data.rows.1.efficiency_pct', null);

        $response->assertJsonPath('data.rows.2.efficiency_pct', 87.5)
            // 60.0 arrives as integer 60 after the JSON round-trip.
            ->assertJsonPath('data.rows.3.efficiency_pct', 60);

        // Column totals: plain sums (the missing-standards entry's actual
        // boxes and pieces DO count here — only the ratio excludes them).
        $response->assertJsonPath('data.totals.entries', 4)
            ->assertJsonPath('data.totals.expected_boxes', 31)
            ->assertJsonPath('data.totals.actual_boxes', 25)
            ->assertJsonPath('data.totals.actual_pieces', '26880')
            ->assertJsonPath('data.totals.good_production_kg', '288.9600')
            ->assertJsonPath('data.totals.rejection_kg_production', '10.6529')
            ->assertJsonPath('data.totals.rejection_kg_qc', '9.7500')
            ->assertJsonPath('data.totals.lumps_kg', '1.7500');

        // THE rule: 20/31 = 64.516… → 64.5 (ratio of BOX sums — the WB2
        // totals-row formula, deliberately untouched by the piece-grain
        // row change). The average of the piece-grain row percentages
        // would be (43.3 + 87.5 + 60.0) / 3 = 63.6 — asserting 64.5
        // proves the aggregation is the totals-row ratio, not a mean.
        $response->assertJsonPath('data.totals.efficiency_pct', 64.5);
    }

    public function test_rows_with_null_expected_contribute_to_neither_sum(): void
    {
        $this->actingAsViewer();
        $machineA = $this->fixture()['machineA'];

        // Machine A alone: eligible 7/16 = 43.75 → 43.8. The missing-
        // standards entry's 5 actual boxes are in the column total (12)
        // but NOT in the ratio — 12/16 would be 75.0, and averaging would
        // be meaningless with one null row.
        $this->getJson('/api/v1/production/reports/production?date=2026-07-27&work_center_id='.$machineA->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.totals.expected_boxes', 16)
            ->assertJsonPath('data.totals.actual_boxes', 12)
            ->assertJsonPath('data.totals.efficiency_pct', 43.8);
    }

    public function test_production_report_with_no_expectations_has_null_total_efficiency(): void
    {
        $this->actingAsViewer();
        $this->fixture();

        // A date with zero entries: totals exist, ratio is null (no
        // denominator), never 0 or 100.
        $this->getJson('/api/v1/production/reports/production?date=2026-07-26')
            ->assertOk()
            ->assertJsonCount(0, 'data.rows')
            ->assertJsonPath('data.totals.entries', 0)
            ->assertJsonPath('data.totals.efficiency_pct', null);
    }

    public function test_reconciliation_report_orders_worst_unaccounted_first(): void
    {
        $this->actingAsViewer();
        $this->fixture();

        $response = $this->getJson('/api/v1/production/reports/reconciliation?date_from=2026-07-01&date_to=2026-07-31')
            ->assertOk()
            ->assertJsonCount(4, 'data.rows');

        // Hand-computed unaccounted (issued − good − confirmed rejection −
        // lumps): B2 = 70−63−0.8−0.2 = 6.0; B1 = 110−105−2−1 = 2.0;
        // A1 = 80−70.56−7.75−0.55 = 1.14; A2 = 50−50.4 = −0.4. Worst
        // |unaccounted| first.
        $response->assertJsonPath('data.rows.0.batch_number', '20260727-M02-002')
            ->assertJsonPath('data.rows.0.reconciliation_unaccounted_kg', '6.0000')
            ->assertJsonPath('data.rows.0.unaccounted_band', 'investigate')
            ->assertJsonPath('data.rows.0.confirmed_rejection_kg', '0.8000')
            ->assertJsonPath('data.rows.1.batch_number', '20260727-M02-001')
            ->assertJsonPath('data.rows.1.reconciliation_unaccounted_kg', '2.0000')
            ->assertJsonPath('data.rows.2.batch_number', '20260727-M01-001')
            ->assertJsonPath('data.rows.2.reconciliation_unaccounted_kg', '1.1400')
            ->assertJsonPath('data.rows.2.issued_kg', '80.0000')
            ->assertJsonPath('data.rows.2.good_production_kg', '70.5600')
            ->assertJsonPath('data.rows.2.work_center.code', 'MC-01')
            ->assertJsonPath('data.rows.2.lumps_kg', '0.5500')
            // QC weighed A1's rejection, so QC wins as confirmed.
            ->assertJsonPath('data.rows.2.confirmed_rejection_kg', '7.7500')
            // Issue-vs-norm variance rides along: (80−70.56)/70.56 → 13.4.
            ->assertJsonPath('data.rows.2.variance_pct', 13.4)
            ->assertJsonPath('data.rows.2.variance_band', 'investigate')
            ->assertJsonPath('data.rows.3.batch_number', '20260727-M01-002')
            ->assertJsonPath('data.rows.3.reconciliation_unaccounted_kg', '-0.4000')
            ->assertJsonPath('data.rows.3.unaccounted_band', 'ok');
    }

    public function test_ranged_reports_reject_windows_beyond_92_days(): void
    {
        $this->actingAsViewer();

        // 92 days exactly (2026-01-01 → 2026-04-03) passes…
        $this->getJson('/api/v1/production/reports/reconciliation?date_from=2026-01-01&date_to=2026-04-03')
            ->assertOk();

        // …day 93 is a clear 422, not a slow melt.
        $this->getJson('/api/v1/production/reports/reconciliation?date_from=2026-01-01&date_to=2026-04-04')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);

        $this->getJson('/api/v1/production/reports/reconciliation?date_from=2026-01-01&date_to=2026-06-30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_traceability_report_is_404_with_the_flag_off(): void
    {
        config(['production.traceability_enabled' => false]);
        $this->actingAsViewer();

        // An explicitly disabled deployment exposes no traceability report.
        $this->getJson('/api/v1/production/reports/traceability?date_from=2026-07-01&date_to=2026-07-31')
            ->assertNotFound();
        $this->getJson('/api/v1/production/reports/traceability')->assertNotFound();
    }

    public function test_traceability_report_maps_lots_and_bags_to_machines_and_segments(): void
    {
        config(['production.traceability_enabled' => true]);
        $user = $this->actingAsViewer();
        $fixture = $this->fixture();
        $service = app(TraceabilityService::class);

        $lot = $service->createLot([
            'item_id' => $fixture['resin']->id, 'supplier_lot_no' => 'RIL-2026-0714',
            'received_date' => '2026-07-20', 'bag_count' => 2,
            'bag_weight_kg' => '25', 'total_received_kg' => '50',
            'warehouse_id' => $fixture['rmStore']->id,
        ], $user->id);
        [$bag1, $bag2] = $lot->bags->sortBy('id')->values();

        // A running segment on machine A; bag 1 fully feeds it.
        $segment = ShiftProductionEntry::create([
            'shift_id' => $fixture['shift']->id, 'work_center_id' => $fixture['machineA']->id,
            'item_id' => $fixture['resin']->id, 'warehouse_id' => $fixture['rmStore']->id,
            'production_date' => '2026-07-28', 'batch_number' => '20260728-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null, 'quantity_scrap' => '0',
        ]);
        $service->loadBagToDayBin([
            'barcode' => $bag1->barcode, 'work_center_id' => $fixture['machineA']->id,
            'shift_production_entry_id' => $segment->id,
        ], $user->id);

        // Bag 2: partial weighed load to machine B, outside any segment.
        $service->loadBagToDayBin([
            'material_bag_id' => $bag2->id, 'work_center_id' => $fixture['machineB']->id,
            'quantity_kg' => '7.5',
        ], $user->id);

        $response = $this->getJson('/api/v1/production/reports/traceability?date_from=2026-07-01&date_to=2026-07-31')
            ->assertOk()
            ->assertJsonCount(1, 'data.lots');

        $response->assertJsonPath('data.lots.0.supplier_lot_no', 'RIL-2026-0714')
            ->assertJsonPath('data.lots.0.item.sku', 'RM-PET')
            ->assertJsonPath('data.lots.0.bags.0.barcode', $bag1->barcode)
            ->assertJsonPath('data.lots.0.bags.0.status', 'consumed')
            ->assertJsonPath('data.lots.0.bags.0.remaining_kg', '0.0000')
            ->assertJsonPath('data.lots.0.bags.0.fed.0.machine.code', 'MC-01')
            ->assertJsonPath('data.lots.0.bags.0.fed.0.segment.batch_number', '20260728-M01-001')
            ->assertJsonPath('data.lots.0.bags.0.fed.0.loaded_kg', '25.0000')
            ->assertJsonPath('data.lots.0.bags.1.barcode', $bag2->barcode)
            ->assertJsonPath('data.lots.0.bags.1.status', 'in_store')
            ->assertJsonPath('data.lots.0.bags.1.remaining_kg', '17.5000')
            ->assertJsonPath('data.lots.0.bags.1.fed.0.machine.code', 'MC-02')
            // Loaded outside a batch window: the machine still shows, the
            // segment is honestly null.
            ->assertJsonPath('data.lots.0.bags.1.fed.0.segment', null)
            ->assertJsonPath('data.lots.0.bags.1.fed.0.loaded_kg', '7.5000');

        // item_id filter: a different material's lot stays out.
        $other = Item::create(['sku' => 'RM-MB', 'name' => 'Masterbatch', 'uom' => 'Kgs']);
        $service->createLot([
            'item_id' => $other->id, 'received_date' => '2026-07-21',
            'bag_count' => 1, 'total_received_kg' => '10',
        ], $user->id);

        $this->getJson('/api/v1/production/reports/traceability?date_from=2026-07-01&date_to=2026-07-31&item_id='.$fixture['resin']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.lots')
            ->assertJsonPath('data.lots.0.item.sku', 'RM-PET');
    }

    public function test_reports_need_a_production_permission(): void
    {
        // No production.* permission at all → 403 (the suite's other tests
        // prove production.view alone is sufficient).
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/production/reports/production?date=2026-07-27')->assertForbidden();
        $this->getJson('/api/v1/production/reports/reconciliation?date_from=2026-07-01&date_to=2026-07-31')
            ->assertForbidden();
    }
}
