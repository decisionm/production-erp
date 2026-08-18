<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Core\Exports\CsvStreamer;
use App\Modules\Core\Models\ExportRun;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Exports\CecExport;
use App\Modules\Production\Exports\TraceabilityReportExport;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\LogStatus;
use App\Modules\Production\Models\MachineDowntimeLog;
use App\Modules\Production\Models\PowerInterruptionLog;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftSummary;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Production kinds of the Download / Export Center (MASTER-PLAN Phase
 * 4.5): each file IS its report endpoint, downloaded — the same filters
 * (the report FormRequest's own rules, the 92-day cap included), the same
 * rows in the same order, every cell the report's own figure. The daily
 * sheet ends in the "Day total" row the screen pins; the shift KPI summary
 * lays the report's scopes out as rows (each shift with records, then the
 * day); the traceability drill-down is one row per deepest visible level.
 * The CEC slot is catalogued BLOCKED with its reason verbatim and answers
 * 409 — no layout is invented. None of these files carries a rate, a cost
 * or a supplier: production reports have none (FC-06 has nothing to gate).
 *
 * Fixture: ReportEndpointsTest's day (2 machines × 2 completed Morning
 * entries, one missing standards) plus a Night entry, a supervisor's
 * summary row, a power cut, and — on a DEACTIVATED shift — one closed
 * downtime log, so the shift rows have to find a shift the picker no
 * longer lists.
 */
class ProductionExportsTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-27';

    private Shift $morning;

    private Shift $afternoon;

    private Shift $night;

    private WorkCenter $machineA;

    private WorkCenter $machineB;

    private Item $resin;

    private Warehouse $rmStore;

    private Warehouse $fgStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->morning = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->afternoon = Shift::create(['name' => 'Afternoon', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => false]);
        $this->night = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);
        $this->machineA = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $this->machineB = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2']);
        $this->fgStore = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
        $this->rmStore = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $bottleA = Item::create(['sku' => 'BTL-840', 'name' => 'Bottle 840-pack', 'uom' => 'NOS', 'nominal_weight_grams' => '12', 'nos_per_box' => 840]);
        $bottleB = Item::create(['sku' => 'BTL-1500', 'name' => 'Bottle 1500-pack', 'uom' => 'NOS', 'nominal_weight_grams' => '10', 'nos_per_box' => 1500]);

        $entry = fn (array $attributes): ShiftProductionEntry => ShiftProductionEntry::create($attributes + [
            'shift_id' => $this->morning->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => self::DATE,
            'batch_status' => BatchStatus::Completed,
            'quantity_scrap' => '0',
        ]);

        $a1 = $entry([
            'work_center_id' => $this->machineA->id, 'item_id' => $bottleA->id, 'batch_number' => '20260727-M01-001',
            'standard_cycle_time' => '10.6', 'active_cavities' => 5, 'running_hours' => '8', 'nos_per_box' => 840, 'no_of_box' => 7,
            'quantity_produced' => '5880', 'quantity_produced_kg' => '70.5600', 'quantity_rejection_kg' => '7.7529', 'qc_rejection_kg' => '7.75',
        ]);
        $a1->materialConsumptions()->create(['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '80']);
        $a1->scraps()->create(['type' => 'lumps', 'quantity_kg' => '0.55']);

        $a2 = $entry([
            'work_center_id' => $this->machineA->id, 'item_id' => $bottleA->id, 'batch_number' => '20260727-M01-002',
            'running_hours' => '8', 'nos_per_box' => 840, 'no_of_box' => 5, 'quantity_produced' => '4200', 'quantity_produced_kg' => '50.4000',
        ]);
        $a2->materialConsumptions()->create(['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '50']);

        $b1 = $entry([
            'work_center_id' => $this->machineB->id, 'item_id' => $bottleB->id, 'batch_number' => '20260727-M02-001',
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8', 'nos_per_box' => 1500, 'no_of_box' => 7,
            'quantity_produced' => '10500', 'quantity_produced_kg' => '105.0000', 'quantity_rejection_kg' => '2.1', 'qc_rejection_kg' => '2',
        ]);
        $b1->materialConsumptions()->create(['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '110']);
        $b1->scraps()->create(['type' => 'lumps', 'quantity_kg' => '1']);

        $b2 = $entry([
            'work_center_id' => $this->machineB->id, 'item_id' => $bottleB->id, 'batch_number' => '20260727-M02-002',
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '7', 'nos_per_box' => 1500, 'no_of_box' => 6,
            'quantity_produced' => '6300', 'quantity_produced_kg' => '63.0000', 'quantity_rejection_kg' => '0.8',
        ]);
        $b2->materialConsumptions()->create(['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '70']);
        $b2->scraps()->create(['type' => 'lumps', 'quantity_kg' => '0.2']);

        // The Night shift's one batch on machine A.
        $n1 = $entry([
            'shift_id' => $this->night->id, 'work_center_id' => $this->machineA->id, 'item_id' => $bottleA->id, 'batch_number' => '20260727-M01-003',
            'standard_cycle_time' => '10.6', 'active_cavities' => 5, 'running_hours' => '8', 'nos_per_box' => 840, 'no_of_box' => 10,
            'quantity_produced' => '8400', 'quantity_produced_kg' => '100.8000', 'quantity_rejection_kg' => '1.2',
        ]);
        $n1->materialConsumptions()->create(['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '104']);

        // Still running — completed-only reports must never show it.
        ShiftProductionEntry::create([
            'shift_id' => $this->morning->id, 'work_center_id' => $this->machineA->id, 'item_id' => $bottleA->id,
            'warehouse_id' => $this->fgStore->id, 'production_date' => self::DATE, 'batch_number' => '20260727-M01-999',
            'batch_status' => BatchStatus::InProgress, 'quantity_produced' => null, 'quantity_scrap' => '0',
        ]);

        // The supervisor's inputs for the Morning shift.
        $supervisor = Employee::create(['employee_code' => 'SUP-01', 'name' => 'Karthik Raman', 'date_of_joining' => '2024-01-01', 'status' => 'active']);
        ShiftSummary::create([
            'shift_id' => $this->morning->id, 'production_date' => self::DATE, 'supervisor_id' => $supervisor->id,
            'target_production_kg' => '300.0000', 'power_consumption_units' => '120.0000', 'remarks' => 'Smooth run',
        ]);
        PowerInterruptionLog::create([
            'shift_id' => $this->morning->id, 'production_date' => self::DATE,
            'from_time' => '2026-07-27 09:00:00', 'to_time' => '2026-07-27 09:30:00', 'idle_hours' => '0.5000',
        ]);
        // The deactivated Afternoon shift's only record: a closed breakdown.
        MachineDowntimeLog::create([
            'work_center_id' => $this->machineB->id, 'shift_id' => $this->afternoon->id, 'production_date' => self::DATE,
            'nature_of_problem' => 'Heater band', 'from_time' => '2026-07-27 15:00:00', 'to_time' => '2026-07-27 15:30:00',
            'total_minutes' => 30, 'status' => LogStatus::Closed,
        ]);
    }

    // ---- shift_summary --------------------------------------------------------

    public function test_the_shift_summary_file_is_the_report_per_shift_with_records_then_the_day_each_as_the_endpoint_answers(): void
    {
        $this->actAs(['production.view']);

        $csv = $this->csv($this->postJson('/api/v1/exports/shift_summary', ['production_date' => self::DATE])->assertOk());

        $this->assertSame(
            // Phase 7 (P7-03 (f)): `machines_running` / `machines_down` →
            // `machines_running_now` / `machines_down_now` — the file reads
            // the report's honest *_now keys (current state, not a fact of
            // the date); the JSON aliases stay this release.
            ['scope', 'shift_id', 'shift', 'production_date', 'supervisor_code', 'supervisor', 'target_production_kg', 'actual_production_kg', 'rejection_kg', 'net_good_output_kg', 'efficiency_percent', 'rejection_percent', 'machines_running_now', 'machines_down_now', 'idle_time_hours', 'no_of_mold_changes', 'power_consumption_units', 'unit_per_kg', 'power_interruption_hours', 'remarks'],
            $csv['headers'],
        );
        // Every shift with something recorded on the date, in the picker's
        // order (start_time) — the deactivated Afternoon shift included, on
        // the strength of its one downtime log — then the day.
        $this->assertSame(
            [['shift', 'Morning'], ['shift', 'Afternoon'], ['shift', 'Night'], ['day', '']],
            array_map(fn (array $row) => [$row['scope'], $row['shift']], $csv['rows']),
        );

        // Each row is the endpoint's own answer for that scope, cell for cell.
        foreach ($csv['rows'] as $row) {
            $query = ['production_date' => self::DATE] + ($row['scope'] === 'shift' ? ['shift_id' => (int) $row['shift_id']] : []);
            $screen = $this->getJson('/api/v1/production/shift-summaries/report?'.http_build_query($query))->assertOk()->json('data');

            $this->assertSame((string) ($screen['shift_id'] ?? ''), $row['shift_id']);
            $this->assertSame(self::DATE, $row['production_date']);
            $this->assertSame((string) ($screen['supervisor']['employee_code'] ?? ''), $row['supervisor_code']);
            $this->assertSame((string) ($screen['supervisor']['name'] ?? ''), $row['supervisor']);
            foreach ([
                'target_production_kg', 'actual_production_kg', 'rejection_kg', 'net_good_output_kg', 'efficiency_percent',
                'rejection_percent', 'machines_running_now', 'machines_down_now', 'idle_time_hours', 'no_of_mold_changes',
                'power_consumption_units', 'unit_per_kg', 'power_interruption_hours', 'remarks',
            ] as $key) {
                $this->assertSame($this->cell($screen[$key]), $row[$key], "{$key} on the {$row['scope']} row of ".($row['shift'] ?: 'the day'));
            }
            // The aliases the file used to read are still on the JSON this
            // release (the screen's fallback) and equal the *_now values.
            $this->assertSame($screen['machines_running_now'], $screen['machines_running']);
            $this->assertSame($screen['machines_down_now'], $screen['machines_down']);
        }

        // The figures themselves, pinned once so the file is read as a person would.
        $morning = $csv['rows'][0];
        $this->assertSame('SUP-01', $morning['supervisor_code']);
        $this->assertSame('Karthik Raman', $morning['supervisor']);
        $this->assertSame('300.0000', $morning['target_production_kg']);
        $this->assertSame('288.9600', $morning['actual_production_kg']);
        $this->assertSame('10.6529', $morning['rejection_kg']);
        $this->assertSame('1', $morning['machines_running_now']);
        $this->assertArrayNotHasKey('machines_running', $morning, 'the alias column is gone from the file');
        $this->assertSame('0.5000', $morning['power_interruption_hours']);
        $this->assertSame('Smooth run', $morning['remarks']);
        $afternoon = $csv['rows'][1];
        $this->assertSame('0.0000', $afternoon['actual_production_kg']);
        $this->assertSame('0.5000', $afternoon['idle_time_hours']);
        $this->assertSame('', $afternoon['supervisor']);
        $day = $csv['rows'][3];
        $this->assertSame('', $day['shift_id']);
        $this->assertSame('389.7600', $day['actual_production_kg'], 'the day-wide rollup — the report\'s own sum, not the file\'s');
        $this->assertSame('300.0000', $day['target_production_kg']);
        $this->assertSame('0.5000', $day['idle_time_hours']);
        $this->assertSame('', $day['supervisor'], 'day-wide has no single accountable name');

        // With a shift picked: exactly that shift's report, one row.
        $one = $this->csv($this->postJson('/api/v1/exports/shift_summary', ['production_date' => self::DATE, 'shift_id' => $this->night->id])->assertOk());
        $this->assertCount(1, $one['rows']);
        $this->assertSame(['shift', 'Night', '100.8000'], [$one['rows'][0]['scope'], $one['rows'][0]['shift'], $one['rows'][0]['actual_production_kg']]);

        // A date with nothing recorded: the day row alone, honestly zero.
        $empty = $this->csv($this->postJson('/api/v1/exports/shift_summary', ['production_date' => '2026-07-26'])->assertOk());
        $this->assertSame([['day', '', '0.0000']], array_map(fn (array $r) => [$r['scope'], $r['shift'], $r['actual_production_kg']], $empty['rows']));

        // The endpoint's own grammar: production_date required, shift_id must exist.
        $this->postJson('/api/v1/exports/shift_summary', [])->assertUnprocessable()->assertJsonValidationErrors('production_date');
        $this->postJson('/api/v1/exports/shift_summary', ['production_date' => self::DATE, 'shift_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    }

    public function test_the_shift_summary_row_count_is_the_kinds_count_and_the_run_records_it(): void
    {
        $this->actAs(['production.view']);

        $this->csv($this->postJson('/api/v1/exports/shift_summary', ['production_date' => self::DATE])->assertOk());
        $run = ExportRun::query()->latest('id')->first();
        $this->assertSame('shift_summary', $run->kind);
        $this->assertTrue($run->completed);
        $this->assertSame(4, $run->row_count, 'three shifts with records + the day');

        // The cap is judged on the same count.
        config(['exports.row_cap' => 3]);
        $this->postJson('/api/v1/exports/shift_summary', ['production_date' => self::DATE])
            ->assertUnprocessable()
            ->assertJsonPath('message', '4 rows match; the cap is 3 — narrow the range');
    }

    // ---- production_report ---------------------------------------------------

    public function test_the_production_report_file_is_the_daily_sheet_rows_then_the_day_total_row(): void
    {
        $this->actAs(['production.view']);

        foreach ([
            ['date' => self::DATE],
            ['date' => self::DATE, 'shift_id' => $this->morning->id],
            ['date' => self::DATE, 'work_center_id' => $this->machineA->id],
            ['date' => self::DATE, 'shift_id' => $this->night->id, 'work_center_id' => $this->machineB->id],
        ] as $filters) {
            $screen = $this->getJson('/api/v1/production/reports/production?'.http_build_query($filters))->assertOk()->json('data');
            $csv = $this->csv($this->postJson('/api/v1/exports/production_report', $filters)->assertOk());

            $entryRows = array_values(array_filter($csv['rows'], fn (array $row) => $row['row'] === 'entry'));
            $totalRows = array_values(array_filter($csv['rows'], fn (array $row) => $row['row'] === 'day_total'));

            $this->assertSame(
                array_map(fn (array $r) => (string) $r['entry_id'], $screen['rows']),
                array_column($entryRows, 'entry_id'),
                'entry ids and order, filters: '.json_encode($filters),
            );
            $this->assertCount($screen['rows'] === [] ? 0 : 1, $totalRows, 'the pinned Day total row exists only when there are rows');
            $this->assertSame(count($screen['rows']) + count($totalRows), count($csv['rows']));

            foreach ($entryRows as $i => $row) {
                $expected = $screen['rows'][$i];
                $this->assertSame((string) $expected['batch_number'], $row['batch_number']);
                $this->assertSame((string) $expected['production_date'], $row['production_date']);
                $this->assertSame((string) $expected['shift']['name'], $row['shift']);
                $this->assertSame((string) $expected['work_center']['code'], $row['machine']);
                $this->assertSame((string) $expected['work_center']['name'], $row['machine_name']);
                $this->assertSame((string) $expected['item']['sku'], $row['item_sku']);
                $this->assertSame((string) $expected['item']['name'], $row['item_name']);
                foreach (['running_hours', 'expected_pieces', 'expected_boxes', 'actual_boxes', 'actual_pieces', 'good_production_kg', 'rejection_kg_production', 'rejection_kg_qc', 'lumps_kg', 'efficiency_pct', 'efficiency_band'] as $key) {
                    $this->assertSame($this->cell($expected[$key]), $row[$key], "{$key} on {$expected['batch_number']}");
                }
            }

            if ($totalRows !== []) {
                $total = $totalRows[0];
                $this->assertSame(self::DATE, $total['production_date']);
                $this->assertSame('', $total['entry_id']);
                $this->assertSame('', $total['batch_number']);
                foreach (['expected_boxes', 'actual_boxes', 'actual_pieces', 'good_production_kg', 'rejection_kg_production', 'rejection_kg_qc', 'lumps_kg', 'efficiency_pct'] as $key) {
                    $this->assertSame($this->cell($screen['totals'][$key]), $total[$key], "totals.{$key}");
                }
                $this->assertSame('', $total['expected_pieces'], 'the totals row carries no expected-pieces sum — the report sends the finished ratio, not its parts');
            }
        }

        // The whole day, read as a person would: 5 entries, then the total
        // whose efficiency is the report's ratio of sums (never an average).
        $csv = $this->csv($this->postJson('/api/v1/exports/production_report', ['date' => self::DATE])->assertOk());
        $this->assertSame(
            ['row', 'entry_id', 'batch_number', 'production_date', 'shift', 'machine', 'machine_name', 'item_sku', 'item_name', 'running_hours', 'expected_pieces', 'expected_boxes', 'actual_boxes', 'actual_pieces', 'good_production_kg', 'rejection_kg_production', 'rejection_kg_qc', 'lumps_kg', 'efficiency_pct', 'efficiency_band'],
            $csv['headers'],
        );
        $this->assertSame(['20260727-M01-001', '20260727-M01-002', '20260727-M01-003', '20260727-M02-001', '20260727-M02-002', ''], array_column($csv['rows'], 'batch_number'));
        $this->assertSame('43.3', $csv['rows'][0]['efficiency_pct']);
        $this->assertSame('', $csv['rows'][1]['efficiency_pct'], 'missing standards: null, never a fake number');
        $this->assertSame('day_total', $csv['rows'][5]['row']);
        $this->assertSame('35280', $csv['rows'][5]['actual_pieces']);

        // A date with no entries: an empty file — no rows, no total row.
        $this->assertSame([], $this->csv($this->postJson('/api/v1/exports/production_report', ['date' => '2026-07-26'])->assertOk())['rows']);

        // The report's own grammar.
        $this->postJson('/api/v1/exports/production_report', [])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson('/api/v1/exports/production_report', ['date' => self::DATE, 'work_center_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('work_center_id');
    }

    // ---- reconciliation_report -----------------------------------------------

    public function test_the_reconciliation_file_is_the_report_worst_first_and_refuses_the_ranges_the_report_refuses(): void
    {
        $this->actAs(['production.view']);

        foreach ([
            ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'],
            ['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'shift_id' => $this->morning->id],
            ['date_from' => '2026-07-28', 'date_to' => '2026-07-31'],
        ] as $filters) {
            $screen = $this->getJson('/api/v1/production/reports/reconciliation?'.http_build_query($filters))->assertOk()->json('data');
            $csv = $this->csv($this->postJson('/api/v1/exports/reconciliation_report', $filters)->assertOk());

            $this->assertSame(
                array_map(fn (array $r) => (string) $r['entry_id'], $screen['rows']),
                array_column($csv['rows'], 'entry_id'),
                'ids and order (worst unaccounted first), filters: '.json_encode($filters),
            );
            $this->assertSame(count($screen['rows']), count($csv['rows']));

            foreach ($csv['rows'] as $i => $row) {
                $expected = $screen['rows'][$i];
                $this->assertSame((string) $expected['batch_number'], $row['batch_number']);
                $this->assertSame((string) $expected['shift']['name'], $row['shift']);
                $this->assertSame((string) $expected['work_center']['code'], $row['machine']);
                $this->assertSame((string) $expected['item']['sku'], $row['item_sku']);
                foreach (['issued_kg', 'good_production_kg', 'confirmed_rejection_kg', 'lumps_kg', 'reconciliation_unaccounted_kg', 'unaccounted_band', 'variance_pct', 'variance_band'] as $key) {
                    $this->assertSame($this->cell($expected[$key]), $row[$key], "{$key} on {$expected['batch_number']}");
                }
            }
        }

        $csv = $this->csv($this->postJson('/api/v1/exports/reconciliation_report', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'])->assertOk());
        $this->assertSame(
            ['entry_id', 'batch_number', 'production_date', 'shift', 'machine', 'machine_name', 'item_sku', 'item_name', 'issued_kg', 'good_production_kg', 'confirmed_rejection_kg', 'lumps_kg', 'reconciliation_unaccounted_kg', 'unaccounted_band', 'variance_pct', 'variance_band'],
            $csv['headers'],
        );
        $this->assertSame('20260727-M02-002', $csv['rows'][0]['batch_number']);
        $this->assertSame('6.0000', $csv['rows'][0]['reconciliation_unaccounted_kg']);
        $this->assertSame('-0.4000', end($csv['rows'])['reconciliation_unaccounted_kg'], 'a negative figure is written as itself, not quoted or prefixed');

        // The report's grammar, the 92-day cap included — the cap is a
        // closure on the report request that reads its own input, and the
        // export must feed it the body, not a bare instance.
        $this->postJson('/api/v1/exports/reconciliation_report', ['date_from' => '2026-01-01', 'date_to' => '2026-04-03'])->assertOk();
        $this->postJson('/api/v1/exports/reconciliation_report', ['date_from' => '2026-01-01', 'date_to' => '2026-04-04'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
        $this->postJson('/api/v1/exports/reconciliation_report', ['date_from' => '2026-07-31', 'date_to' => '2026-07-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
        $this->postJson('/api/v1/exports/reconciliation_report', ['date_from' => '2026-07-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
    }

    // ---- traceability_report -------------------------------------------------

    public function test_the_traceability_file_is_the_drill_down_one_row_per_deepest_visible_level(): void
    {
        config(['production.traceability_enabled' => true]);
        $user = $this->actAs(['production.view']);
        $service = app(TraceabilityService::class);

        $lot = $service->createLot([
            'item_id' => $this->resin->id, 'supplier_lot_no' => 'RIL-2026-0714', 'received_date' => '2026-07-20',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50', 'warehouse_id' => $this->rmStore->id,
        ], $user->id);
        [$bag1, $bag2] = $lot->bags->sortBy('id')->values();
        $segment = ShiftProductionEntry::create([
            'shift_id' => $this->morning->id, 'work_center_id' => $this->machineA->id, 'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id, 'production_date' => '2026-07-28', 'batch_number' => '20260728-M01-001',
            'batch_status' => BatchStatus::InProgress, 'quantity_produced' => null, 'quantity_scrap' => '0',
        ]);
        $service->loadBagToDayBin(['barcode' => $bag1->barcode, 'work_center_id' => $this->machineA->id, 'shift_production_entry_id' => $segment->id], $user->id);
        $service->loadBagToDayBin(['material_bag_id' => $bag2->id, 'work_center_id' => $this->machineB->id, 'quantity_kg' => '7.5'], $user->id);
        // A second lot, one bag, never loaded — one row with no destination.
        $masterbatch = Item::create(['sku' => 'RM-MB', 'name' => 'Masterbatch', 'uom' => 'Kgs']);
        $service->createLot(['item_id' => $masterbatch->id, 'received_date' => '2026-07-21', 'bag_count' => 1, 'total_received_kg' => '10'], $user->id);

        foreach ([
            ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'],
            ['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'item_id' => $this->resin->id],
            ['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'lot_id' => $lot->id],
            ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'],
        ] as $filters) {
            $screen = $this->getJson('/api/v1/production/reports/traceability?'.http_build_query($filters))->assertOk()->json('data');
            $csv = $this->csv($this->postJson('/api/v1/exports/traceability_report', $filters)->assertOk());

            $expected = [];
            foreach ($screen['lots'] as $lotRow) {
                $lotNo = (string) ($lotRow['supplier_lot_no'] ?? '');
                if ($lotRow['bags'] === []) {
                    $expected[] = [$lotNo, '', ''];
                }
                foreach ($lotRow['bags'] as $bag) {
                    if ($bag['fed'] === []) {
                        $expected[] = [$lotNo, $bag['barcode'], ''];
                    }
                    foreach ($bag['fed'] as $fed) {
                        $expected[] = [$lotNo, $bag['barcode'], $fed['machine']['code']];
                    }
                }
            }
            $this->assertSame(
                $expected,
                array_map(fn (array $r) => [$r['supplier_lot_no'], $r['bag_barcode'], $r['machine']], $csv['rows']),
                'lot → bag → destination rows, filters: '.json_encode($filters),
            );
        }

        $csv = $this->csv($this->postJson('/api/v1/exports/traceability_report', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'])->assertOk());
        $this->assertSame(
            ['lot_id', 'supplier_lot_no', 'item_sku', 'item_name', 'received_date', 'bag_count', 'total_received_kg', 'bag_id', 'bag_barcode', 'bag_status', 'bag_original_kg', 'bag_remaining_kg', 'machine', 'machine_name', 'batch_number', 'loaded_kg', 'loads'],
            $csv['headers'],
        );
        $this->assertCount(3, $csv['rows']);
        [$fedA, $fedB, $unloaded] = $csv['rows'];
        $this->assertSame(['RIL-2026-0714', 'RM-PET', 'PET Resin', '2026-07-20', '2'], [$fedA['supplier_lot_no'], $fedA['item_sku'], $fedA['item_name'], $fedA['received_date'], $fedA['bag_count']]);
        $this->assertSame([$bag1->barcode, 'consumed', '0.0000', 'MC-01', 'Machine 1', '20260728-M01-001', '25.0000', '1'], [$fedA['bag_barcode'], $fedA['bag_status'], $fedA['bag_remaining_kg'], $fedA['machine'], $fedA['machine_name'], $fedA['batch_number'], $fedA['loaded_kg'], $fedA['loads']]);
        $this->assertSame([$bag2->barcode, 'in_store', '17.5000', 'MC-02', '', '7.5000'], [$fedB['bag_barcode'], $fedB['bag_status'], $fedB['bag_remaining_kg'], $fedB['machine'], $fedB['batch_number'], $fedB['loaded_kg']]);
        $this->assertSame(['RM-MB', '', '', ''], [$unloaded['item_sku'], $unloaded['machine'], $unloaded['loaded_kg'], $unloaded['loads']]);
        $this->assertSame('', $unloaded['supplier_lot_no']);
        $this->assertNotSame('', $unloaded['bag_barcode']);

        $run = ExportRun::query()->latest('id')->first();
        $this->assertSame(3, $run->row_count);
        $this->assertTrue($run->completed);

        // The report's grammar: the 92-day cap through the export too.
        $this->postJson('/api/v1/exports/traceability_report', ['date_from' => '2026-01-01', 'date_to' => '2026-04-04'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
        $this->postJson('/api/v1/exports/traceability_report', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'lot_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors(['lot_id']);
    }

    public function test_with_traceability_off_the_kind_is_blocked_as_the_report_route_is_absent(): void
    {
        config(['production.traceability_enabled' => false]);
        $this->actAs(['production.view']);

        $kind = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->firstWhere('key', 'traceability_report');
        $this->assertSame('blocked', $kind['status']);
        $this->assertSame(TraceabilityReportExport::DISABLED_REASON, $kind['blocked_reason']);

        $this->postJson('/api/v1/exports/traceability_report', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'])
            ->assertStatus(409)
            ->assertJsonPath('message', TraceabilityReportExport::DISABLED_REASON);
        $this->getJson('/api/v1/production/reports/traceability?date_from=2026-07-01&date_to=2026-07-31')->assertNotFound();

        config(['production.traceability_enabled' => true]);
        $this->assertSame('available', collect($this->getJson('/api/v1/exports')->json('data'))->firstWhere('key', 'traceability_report')['status']);
    }

    // ---- cec ------------------------------------------------------------------

    public function test_the_cec_slot_is_catalogued_blocked_with_the_reason_verbatim_and_answers_409(): void
    {
        $user = $this->actAs(['production.view']);

        $kind = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->firstWhere('key', 'cec');
        $this->assertNotNull($kind);
        $this->assertSame('production', $kind['module']);
        $this->assertSame('blocked', $kind['status']);
        $this->assertSame('CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED', $kind['blocked_reason']);
        $this->assertSame(CecExport::BLOCKED_REASON, $kind['blocked_reason']);
        // date + shift documented for the form, neither required.
        $this->assertSame(
            [['date', 'date', false], ['shift_id', 'integer', false]],
            array_map(fn (array $f) => [$f['name'], $f['type'], $f['required']], $kind['filters']),
        );

        foreach ([[], ['date' => self::DATE], ['date' => self::DATE, 'shift_id' => $this->morning->id]] as $body) {
            $response = $this->postJson('/api/v1/exports/cec', $body)->assertStatus(409);
            $this->assertSame(['message' => 'CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED', 'kind' => 'cec'], $response->json());
        }

        // Every attempt is on the record, refused, with the reason.
        $runs = ExportRun::query()->where('kind', 'cec')->orderBy('id')->get();
        $this->assertCount(3, $runs);
        foreach ($runs as $run) {
            $this->assertSame($user->id, $run->user_id);
            $this->assertFalse($run->completed);
            $this->assertSame(0, $run->row_count);
            $this->assertSame(CecExport::BLOCKED_REASON, $run->refusal_reason);
        }

        // A blocked kind has ONE answer — its reason — whatever the body: a
        // malformed date is not judged first (its documented filters feed the
        // catalogue's form, not a gate before the block).
        $this->postJson('/api/v1/exports/cec', ['date' => 'someday'])
            ->assertStatus(409)
            ->assertJsonPath('message', CecExport::BLOCKED_REASON);
        $this->assertSame(0, app(CecExport::class)->count([], $user));
    }

    // ---- catalogue / permission ----------------------------------------------

    public function test_the_production_kinds_are_catalogued_for_production_readers_only_and_none_names_a_rate_or_a_supplier(): void
    {
        $this->actAs(['production.view']);
        $catalogue = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->keyBy('key');

        foreach (['shift_summary', 'production_report', 'reconciliation_report', 'traceability_report', 'cec'] as $key) {
            $this->assertTrue($catalogue->has($key), "{$key} is offered to production.view");
            $this->assertSame('production', $catalogue[$key]['module']);
        }
        $this->assertSame(
            [['production_date', 'date', true], ['shift_id', 'integer', false]],
            array_map(fn (array $f) => [$f['name'], $f['type'], $f['required']], $catalogue['shift_summary']['filters']),
        );
        $this->assertSame(
            [['date', 'date', true], ['shift_id', 'integer', false], ['work_center_id', 'integer', false]],
            array_map(fn (array $f) => [$f['name'], $f['type'], $f['required']], $catalogue['production_report']['filters']),
        );
        $this->assertSame(
            [['date_from', 'date', true], ['date_to', 'date', true], ['shift_id', 'integer', false]],
            array_map(fn (array $f) => [$f['name'], $f['type'], $f['required']], $catalogue['reconciliation_report']['filters']),
        );
        $this->assertSame(
            [['date_from', 'date', true], ['date_to', 'date', true], ['lot_id', 'integer', false], ['item_id', 'integer', false]],
            array_map(fn (array $f) => [$f['name'], $f['type'], $f['required']], $catalogue['traceability_report']['filters']),
        );

        // FC-06 has nothing to gate on these files: no column names a rate,
        // a cost, an amount, a price or a vendor, for any reader — the
        // production reports carry none.
        config(['production.traceability_enabled' => true]);
        foreach ([
            'shift_summary' => ['production_date' => self::DATE],
            'production_report' => ['date' => self::DATE],
            'reconciliation_report' => ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'],
            'traceability_report' => ['date_from' => '2026-07-01', 'date_to' => '2026-07-31'],
        ] as $key => $filters) {
            $headers = $this->csv($this->postJson("/api/v1/exports/{$key}", $filters)->assertOk())['headers'];
            foreach ($headers as $header) {
                $this->assertDoesNotMatchRegularExpression('/rate|cost|amount|price|vendor|party/i', $header, "{$key}: {$header}");
            }
        }

        $this->app['auth']->forgetGuards();

        // production.manage suffices too (a manager reads what a viewer reads).
        $this->actAs(['production.manage']);
        $this->assertTrue(collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->keyBy('key')->has('production_report'));

        $this->app['auth']->forgetGuards();

        // A reader without production standing is offered none of them and may run none.
        $this->actAs(['tally-sync.view', 'finance.view']);
        $offered = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->pluck('key')->all();
        foreach (['shift_summary', 'production_report', 'reconciliation_report', 'traceability_report', 'cec'] as $key) {
            $this->assertNotContains($key, $offered);
        }
        $this->postJson('/api/v1/exports/production_report', ['date' => self::DATE])->assertForbidden();
        $this->postJson('/api/v1/exports/cec', [])->assertForbidden();
        $this->postJson('/api/v1/exports/shift_summary', ['production_date' => self::DATE])->assertForbidden();
    }

    // ---- helpers ------------------------------------------------------------

    /** A JSON value as the CSV writes it (CsvStreamer::escapeCell before quoting): null → '', bools as words, numbers as printed. */
    private function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => (string) $value,
        };
    }

    /**
     * @return array{raw: string, headers: list<string>, rows: list<array<string, string>>}
     */
    private function csv(TestResponse $response): array
    {
        $raw = $response->streamedContent();
        $this->assertStringStartsWith(CsvStreamer::BOM, $raw);
        $body = substr($raw, strlen(CsvStreamer::BOM));
        $this->assertStringEndsWith("\r\n", $body);

        $lines = explode("\r\n", rtrim($body, "\r\n"));
        $headers = str_getcsv(array_shift($lines), ',', '"', '');
        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            $rows[] = array_combine($headers, $cells);
        }

        return ['raw' => $raw, 'headers' => $headers, 'rows' => $rows];
    }

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
