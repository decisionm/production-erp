<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\LogStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\MachineDowntimeLog;
use App\Modules\Production\Models\PowerInterruptionLog;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftSummary;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\CecReportService;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5.7 (P5.7-02, WS-B) — GET /production/cec, the CEC's DATA, live
 * before its FORMAT is: a THIN composition of the two things the factory
 * already computes for a date — the Shift KPI Summary
 * (ShiftSummaryService::report, per shift and for the day) and the
 * completed entries of the entries index (the Completed Today read:
 * production_date · shift_id · batch_status=completed · per_page 100),
 * grouped by machine. Every figure on it IS one of theirs; the only
 * arithmetic here is a plain sum, labelled as one. The response says so
 * itself (`format` BLOCKED, `figures_from`), and the CEC export KIND stays
 * blocked (ProductionExportsTest) — no layout is invented anywhere.
 *
 * Fixture (2026-08-10): Morning — machine 1 runs two batches (one on full
 * standards, one whose standard was never set), machine 2 one QC-weighed
 * batch that is approved and stamped onto a shift voucher; Night — one
 * batch on machine 1 with its own synced voucher; a running batch and a
 * cancelled one that must never appear; the Morning supervisor's target/
 * power row, a closed breakdown and a power cut. The Afternoon shift has
 * nothing recorded and is not listed.
 */
class CecReportTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-10';

    private Shift $morning;

    private Shift $afternoon;

    private Shift $night;

    private WorkCenter $m1;

    private WorkCenter $m2;

    private Item $bottleA;

    private Item $bottleB;

    private Warehouse $fg;

    private ShiftProductionEntry $a1;

    private ShiftProductionEntry $a2;

    private ShiftProductionEntry $b1;

    private ShiftProductionEntry $n1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->morning = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->afternoon = Shift::create(['name' => 'Afternoon', 'start_time' => '14:00', 'end_time' => '22:00']);
        $this->night = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->m1 = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        $this->m2 = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'display_sequence' => 2]);
        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->bottleA = Item::create(['sku' => 'BTL-840', 'name' => 'Bottle 840-pack', 'uom' => 'NOS', 'nominal_weight_grams' => '12', 'nos_per_box' => 840]);
        $this->bottleB = Item::create(['sku' => 'BTL-1500', 'name' => 'Bottle 1500-pack', 'uom' => 'NOS', 'nominal_weight_grams' => '10', 'nos_per_box' => 1500]);

        $this->a1 = $this->entry([
            'work_center_id' => $this->m1->id, 'item_id' => $this->bottleA->id, 'batch_number' => '20260810-M01-001',
            'standard_cycle_time' => '10.6', 'active_cavities' => 5, 'running_hours' => '8', 'nos_per_box' => 840, 'no_of_box' => 7,
            'quantity_produced' => '5880', 'quantity_produced_kg' => '70.5600', 'quantity_rejection_kg' => '7.7529',
        ]);
        // Completion-recorded downtime — the metrics net it, the CEC reads
        // the netted total off them.
        $power = DowntimeReason::create([
            'code' => 'DT-POWER', 'category' => 'Utilities', 'description' => 'Power outage',
            'planning_type' => 'unplanned', 'reduces_runtime' => true,
            'requires_note' => false, 'selectable_at_start' => true, 'is_active' => true,
        ]);
        $this->a1->downtimeEvents()->create([
            'work_center_id' => $this->m1->id, 'downtime_reason_id' => $power->id, 'production_date' => self::DATE,
            'minutes' => '30', 'is_planned' => false, 'known_before_start' => false,
        ]);

        // No standard on this run: expected output and efficiency are null.
        $this->a2 = $this->entry([
            'work_center_id' => $this->m1->id, 'item_id' => $this->bottleA->id, 'batch_number' => '20260810-M01-002',
            'running_hours' => '8', 'nos_per_box' => 840, 'no_of_box' => 5,
            'quantity_produced' => '4200', 'quantity_produced_kg' => '50.4000',
        ]);

        // QC weighed the rejection; approved; on the shift's Stock Journal.
        $this->b1 = $this->entry([
            'work_center_id' => $this->m2->id, 'item_id' => $this->bottleB->id, 'batch_number' => '20260810-M02-001',
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8', 'nos_per_box' => 1500, 'no_of_box' => 7,
            'quantity_produced' => '10500', 'quantity_produced_kg' => '105.0000', 'quantity_rejection_kg' => '2.1', 'qc_rejection_kg' => '2',
            'status' => ShiftProductionEntryStatus::Approved,
        ]);
        $shiftVoucher = TallySyncEntry::create([
            'syncable_type' => (new Shift)->getMorphClass(),
            'syncable_id' => $this->morning->id,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => ['voucher_number' => 'SJ-20260810-M'],
            'status' => TallySyncStatus::Pending,
        ]);
        $this->b1->forceFill(['tally_sync_entry_id' => $shiftVoucher->id])->save();

        $this->n1 = $this->entry([
            'shift_id' => $this->night->id, 'work_center_id' => $this->m1->id, 'item_id' => $this->bottleA->id, 'batch_number' => '20260810-M01-003',
            'standard_cycle_time' => '10.6', 'active_cavities' => 5, 'running_hours' => '8', 'nos_per_box' => 840, 'no_of_box' => 10,
            'quantity_produced' => '8400', 'quantity_produced_kg' => '100.8000', 'quantity_rejection_kg' => '1.2',
        ]);
        TallySyncEntry::create([
            'syncable_type' => $this->n1->getMorphClass(),
            'syncable_id' => $this->n1->id,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => "SPE-{$this->n1->id}"],
            'status' => TallySyncStatus::Synced,
            'synced_at' => '2026-08-10 12:00:00',
        ]);

        // Never on the CEC: still running, and withdrawn.
        $this->entry([
            'work_center_id' => $this->m1->id, 'item_id' => $this->bottleA->id, 'batch_number' => '20260810-M01-999',
            'batch_status' => BatchStatus::InProgress, 'quantity_produced' => null,
        ]);
        $this->entry([
            'work_center_id' => $this->m2->id, 'item_id' => $this->bottleB->id, 'batch_number' => '20260810-M02-998',
            'batch_status' => BatchStatus::Cancelled, 'quantity_produced' => '999', 'quantity_produced_kg' => '9.9900',
        ]);
        // Another day entirely.
        $this->entry([
            'work_center_id' => $this->m1->id, 'item_id' => $this->bottleA->id, 'batch_number' => '20260809-M01-001',
            'production_date' => '2026-08-09', 'quantity_produced' => '100', 'quantity_produced_kg' => '1.2000',
        ]);

        $supervisor = Employee::create(['employee_code' => 'SUP-01', 'name' => 'Karthik Raman', 'date_of_joining' => '2024-01-01', 'status' => 'active']);
        ShiftSummary::create([
            'shift_id' => $this->morning->id, 'production_date' => self::DATE, 'supervisor_id' => $supervisor->id,
            'target_production_kg' => '300.0000', 'power_consumption_units' => '120.0000', 'remarks' => 'Smooth run',
        ]);
        PowerInterruptionLog::create([
            'shift_id' => $this->morning->id, 'production_date' => self::DATE,
            'from_time' => '2026-08-10 09:00:00', 'to_time' => '2026-08-10 09:30:00', 'idle_hours' => '0.5000',
        ]);
        MachineDowntimeLog::create([
            'work_center_id' => $this->m2->id, 'shift_id' => $this->morning->id, 'production_date' => self::DATE,
            'nature_of_problem' => 'Heater band', 'from_time' => '2026-08-10 10:00:00', 'to_time' => '2026-08-10 10:30:00',
            'total_minutes' => 30, 'status' => LogStatus::Closed,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function entry(array $attributes): ShiftProductionEntry
    {
        return ShiftProductionEntry::create($attributes + [
            'shift_id' => $this->morning->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => self::DATE,
            'batch_status' => BatchStatus::Completed,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function cec(string $query): array
    {
        return $this->getJson('/api/v1/production/cec?'.$query)->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function shiftSummary(?int $shiftId): array
    {
        $query = ['production_date' => self::DATE] + ($shiftId === null ? [] : ['shift_id' => $shiftId]);

        return $this->getJson('/api/v1/production/shift-summaries/report?'.http_build_query($query))->assertOk()->json('data');
    }

    /** @return array<int, array<string, mixed>> the entries index's completed rows for the date/shift, keyed by id */
    private function completedIndex(?int $shiftId): array
    {
        $query = ['production_date' => self::DATE, 'batch_status' => 'completed', 'per_page' => 100]
            + ($shiftId === null ? [] : ['shift_id' => $shiftId]);
        $response = $this->getJson('/api/v1/production/shift-production-entries?'.http_build_query($query))->assertOk();
        $this->assertSame(1, $response->json('meta.last_page'));

        return collect($response->json('data'))->keyBy('id')->all();
    }

    /** @return list<array<string, mixed>> every batch on the CEC, in order */
    private function batchesOf(array $shiftBlock): array
    {
        return collect($shiftBlock['machines'])->flatMap(fn (array $machine) => $machine['batches'])->values()->all();
    }

    // ---- gate and grammar --------------------------------------------------

    public function test_a_production_reader_is_required(): void
    {
        $this->getJson('/api/v1/production/cec?date='.self::DATE)->assertUnauthorized();

        $this->actAs(['inventory.view']);
        $this->getJson('/api/v1/production/cec?date='.self::DATE)->assertForbidden();

        $this->actAs(['production.view']);
        $this->getJson('/api/v1/production/cec?date='.self::DATE)->assertOk();
    }

    public function test_the_date_is_required_as_y_m_d_and_the_shift_must_exist(): void
    {
        $this->actAs(['production.view']);

        $this->getJson('/api/v1/production/cec')->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->getJson('/api/v1/production/cec?date=10-08-2026')->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->getJson('/api/v1/production/cec?date=someday')->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->getJson('/api/v1/production/cec?date='.self::DATE.'&shift_id=999')->assertUnprocessable()->assertJsonValidationErrors(['shift_id']);
        $this->getJson('/api/v1/production/cec?date='.self::DATE.'&shift_id=abc')->assertUnprocessable()->assertJsonValidationErrors(['shift_id']);
        // An empty shift_id is the day-wide read, not a malformed one.
        $this->getJson('/api/v1/production/cec?date='.self::DATE.'&shift_id=')->assertOk()->assertJsonPath('data.scope', 'day');
    }

    public function test_the_response_says_the_format_is_blocked_and_names_its_two_sources(): void
    {
        $this->actAs(['production.view']);

        $cec = $this->cec('date='.self::DATE);

        $this->assertSame('BLOCKED — SOURCE DOCUMENT REQUIRED', $cec['format']);
        $this->assertSame(['shift_summary', 'shift_production_entries'], $cec['figures_from']);
        $this->assertSame(self::DATE, $cec['production_date']);
        $this->assertNull($cec['shift_id']);
        $this->assertSame('day', $cec['scope']);
        $this->assertSame(CecReportService::FORMAT, $cec['format']);
    }

    // ---- CEC == Shift Summary ----------------------------------------------

    public function test_the_day_read_lists_every_shift_with_records_and_each_summary_is_the_shift_summary_report_verbatim(): void
    {
        $this->actAs(['production.view']);

        $cec = $this->cec('date='.self::DATE);

        // Every shift with something recorded, in the picker's order; the
        // Afternoon shift recorded nothing and is not listed.
        $this->assertSame(
            [[$this->morning->id, 'Morning'], [$this->night->id, 'Night']],
            array_map(fn (array $block) => [$block['shift']['id'], $block['shift']['name']], $cec['shifts']),
        );

        foreach ($cec['shifts'] as $block) {
            $this->assertSame($this->shiftSummary($block['shift']['id']), $block['summary'], "the {$block['shift']['name']} summary");
        }

        // The day block is the whole-day rollup, verbatim.
        $this->assertSame($this->shiftSummary(null), $cec['day']['summary']);
        $this->assertNull($cec['day']['summary']['shift_id']);
        $this->assertSame('300.0000', $cec['shifts'][0]['summary']['target_production_kg']);
    }

    public function test_the_shift_read_is_that_shift_alone(): void
    {
        $this->actAs(['production.view']);

        $cec = $this->cec('date='.self::DATE.'&shift_id='.$this->night->id);

        $this->assertSame('shift', $cec['scope']);
        $this->assertSame($this->night->id, $cec['shift_id']);
        $this->assertCount(1, $cec['shifts']);
        $this->assertSame('Night', $cec['shifts'][0]['shift']['name']);
        $this->assertSame($this->shiftSummary($this->night->id), $cec['shifts'][0]['summary']);
        $this->assertNull($cec['day']);
        $this->assertSame([$this->n1->id], array_column($this->batchesOf($cec['shifts'][0]), 'entry_id'));
    }

    // ---- CEC == Completed Production (the entries index) -------------------

    public function test_every_batch_figure_is_the_entries_index_figure_for_that_entry(): void
    {
        $this->actAs(['production.view']);

        $cec = $this->cec('date='.self::DATE);

        foreach ($cec['shifts'] as $block) {
            $index = $this->completedIndex($block['shift']['id']);
            $batches = $this->batchesOf($block);

            // Exactly the index's completed rows — the running batch, the
            // cancelled one and the other day's are on neither.
            $this->assertEqualsCanonicalizing(array_keys($index), array_column($batches, 'entry_id'), "the {$block['shift']['name']} entries");

            foreach ($batches as $batch) {
                $row = $index[$batch['entry_id']];
                $label = "entry {$batch['entry_id']}";

                $this->assertSame($row['batch_number'], $batch['batch_number'], $label);
                $this->assertSame(['id' => $row['item']['id'], 'sku' => $row['item']['sku'], 'name' => $row['item']['name']], $batch['item'], $label);
                $this->assertSame($row['metrics']['expected_pieces'], $batch['expected_pieces'], $label);
                $this->assertSame($row['metrics']['actual_pieces'], $batch['actual_pieces'], $label);
                $this->assertSame($row['metrics']['good_production_kg'], $batch['good_production_kg'], $label);
                $this->assertSame($row['metrics']['rejection_kg_production'], $batch['rejection_kg'], $label);
                $this->assertSame($row['metrics']['rejection_kg_qc'], $batch['rejection_kg_qc'], $label);
                $this->assertSame($row['metrics']['efficiency_pct'], $batch['efficiency_pct'], $label);
                $this->assertSame($row['metrics']['efficiency_band'], $batch['efficiency_band'], $label);
                $this->assertSame($row['metrics']['expected_boxes'], $batch['expected_boxes'], $label);
                $this->assertSame($row['no_of_box'], $batch['packs'], $label);
                $this->assertSame($row['metrics']['downtime_minutes_total'], $batch['downtime_minutes_total'], $label);
                $this->assertSame($row['metrics']['calculation_version'], $batch['calculation_version'], $label);
                $this->assertSame($row['status'], $batch['approval_status'], $label);
                $this->assertSame($row['tally']['status'] ?? null, $batch['tally_status'], $label);
                $this->assertSame($row['tally'], $batch['tally'], $label);
            }
        }
    }

    public function test_the_figures_pinned_once_as_a_person_reads_them(): void
    {
        $this->actAs(['production.view']);

        $morning = $this->cec('date='.self::DATE)['shifts'][0];
        $this->assertSame('Morning', $morning['shift']['name']);

        // Machines in the picker's order, each with its own batches.
        $this->assertSame(
            [[$this->m1->id, 'MC-01'], [$this->m2->id, 'MC-02']],
            array_map(fn (array $machine) => [$machine['machine']['id'], $machine['machine']['code']], $morning['machines']),
        );

        $batches = collect($this->batchesOf($morning))->keyBy('entry_id');

        // a1: CT 10.6 × 5 cavities × (8 h − 30 min) → the metrics' expected
        // pieces, netted; efficiency at piece grain; 30.00 downtime minutes.
        $a1 = $batches[$this->a1->id];
        $this->assertSame('12735.85', $a1['expected_pieces']);
        $this->assertSame('30.00', $a1['downtime_minutes_total']);
        $this->assertSame(46.2, $a1['efficiency_pct']);
        $this->assertSame(7, $a1['packs']);
        $this->assertSame('70.5600', $a1['good_production_kg']);
        $this->assertSame('7.7529', $a1['rejection_kg']);
        $this->assertNull($a1['rejection_kg_qc']);
        $this->assertSame('pending', $a1['approval_status']);
        $this->assertNull($a1['tally_status']);
        $this->assertSame(['id' => $this->bottleA->id, 'sku' => 'BTL-840', 'name' => 'Bottle 840-pack'], $a1['item']);

        // a2: no standard → expected output and efficiency honestly null.
        $a2 = $batches[$this->a2->id];
        $this->assertNull($a2['expected_pieces']);
        $this->assertNull($a2['efficiency_pct']);
        $this->assertSame('50.4000', $a2['good_production_kg']);
        $this->assertNull($a2['rejection_kg']);

        // b1: QC weighed 2.0000 against production's 2.1000; approved; on
        // the shift's pending Stock Journal.
        $b1 = $batches[$this->b1->id];
        $this->assertSame('2.1000', $b1['rejection_kg']);
        $this->assertSame('2.0000', $b1['rejection_kg_qc']);
        $this->assertSame('approved', $b1['approval_status']);
        $this->assertSame('pending', $b1['tally_status']);
        $this->assertSame('SJ-20260810-M', $b1['tally']['voucher_number']);

        // n1 on the Night shift: its own synced voucher.
        $night = $this->cec('date='.self::DATE.'&shift_id='.$this->night->id)['shifts'][0];
        $n1 = $this->batchesOf($night)[0];
        $this->assertSame('synced', $n1['tally_status']);
        $this->assertSame("SPE-{$this->n1->id}", $n1['tally']['voucher_number']);
    }

    // ---- the sums: plain, labelled, and reconciling with the summary --------

    public function test_the_sums_are_plain_sums_of_the_batch_figures_and_say_so(): void
    {
        $this->actAs(['production.view']);

        $cec = $this->cec('date='.self::DATE);

        foreach ($cec['shifts'] as $block) {
            foreach ($block['machines'] as $machine) {
                $this->assertSame($this->plainSums($machine['batches']), $this->figuresOf($machine['sums']), "{$block['shift']['name']} / {$machine['machine']['code']}");
                $this->assertSame(CecReportService::SUMS_BASIS, $machine['sums']['basis']);
            }
            $this->assertSame($this->plainSums($this->batchesOf($block)), $this->figuresOf($block['sums']), $block['shift']['name']);
            $this->assertSame(CecReportService::SUMS_BASIS, $block['sums']['basis']);
        }

        // The day's sums are the plain sums over every shift's batches.
        $allBatches = collect($cec['shifts'])->flatMap(fn (array $block) => $this->batchesOf($block))->values()->all();
        $this->assertSame($this->plainSums($allBatches), $this->figuresOf($cec['day']['sums']));
        $this->assertSame(CecReportService::SUMS_BASIS, $cec['day']['sums']['basis']);

        // Read as a person: Morning machine 1 = a1 + a2.
        $m1 = $cec['shifts'][0]['machines'][0]['sums'];
        $this->assertSame(2, $m1['batches']);
        $this->assertSame('120.9600', $m1['good_production_kg']);
        $this->assertSame('7.7529', $m1['rejection_kg']);
        $this->assertSame('12735.8500', $m1['expected_pieces']);
        $this->assertSame('10080.0000', $m1['actual_pieces']);
        $this->assertSame(12, $m1['packs']);
        $this->assertSame('30.00', $m1['downtime_minutes_total']);
        // a2 carried no expected pieces and no rejection: the sum says which
        // figures it could not include, rather than passing them off as zero.
        $this->assertSame(['expected_pieces' => 1, 'rejection_kg' => 1], $m1['skipped_nulls']);
        // A ratio is never summed: the shift's efficiency is the summary's
        // efficiency_percent, against the supervisor's target.
        $this->assertArrayNotHasKey('efficiency_pct', $m1);
    }

    public function test_completed_production_reconciles_with_the_shift_summary_on_the_cec(): void
    {
        $this->actAs(['production.view']);

        $cec = $this->cec('date='.self::DATE);

        // Σ completed batches' good kg (labelled sum) == the Shift Summary's
        // actual_production_kg, per shift and for the day; the same for the
        // production-side rejection kg the summary sums.
        foreach ($cec['shifts'] as $block) {
            $this->assertSame($block['summary']['actual_production_kg'], $block['sums']['good_production_kg'], $block['shift']['name']);
            $this->assertSame($block['summary']['rejection_kg'], $block['sums']['rejection_kg'], $block['shift']['name']);
        }
        $this->assertSame($cec['day']['summary']['actual_production_kg'], $cec['day']['sums']['good_production_kg']);
        $this->assertSame($cec['day']['summary']['rejection_kg'], $cec['day']['sums']['rejection_kg']);

        // And the day IS the sum of its shifts (the totals of the three-shift
        // reports == the all-shifts report), on both sources.
        $shiftActual = array_reduce($cec['shifts'], fn (string $carry, array $block) => bcadd($carry, $block['summary']['actual_production_kg'], 4), '0.0000');
        $this->assertSame($cec['day']['summary']['actual_production_kg'], $shiftActual);
        $this->assertSame('326.7600', $shiftActual);
    }

    public function test_a_shift_with_no_completed_batch_carries_its_summary_and_empty_sums(): void
    {
        $this->actAs(['production.view']);

        // The Afternoon shift's only record: a power cut.
        PowerInterruptionLog::create([
            'shift_id' => $this->afternoon->id, 'production_date' => self::DATE,
            'from_time' => '2026-08-10 15:00:00', 'to_time' => '2026-08-10 16:00:00', 'idle_hours' => '1.0000',
        ]);

        $cec = $this->cec('date='.self::DATE);
        $afternoon = collect($cec['shifts'])->firstWhere('shift.id', $this->afternoon->id);

        $this->assertNotNull($afternoon);
        $this->assertSame($this->shiftSummary($this->afternoon->id), $afternoon['summary']);
        $this->assertSame('1.0000', $afternoon['summary']['power_interruption_hours']);
        $this->assertSame([], $afternoon['machines']);
        $this->assertSame(0, $afternoon['sums']['batches']);
        // Nothing to sum is null, never an invented zero — but the summary's
        // own '0.0000' actual is the summary's, carried as it is.
        $this->assertNull($afternoon['sums']['good_production_kg']);
        $this->assertSame('0.0000', $afternoon['summary']['actual_production_kg']);
    }

    public function test_every_page_of_the_completed_entries_is_walked(): void
    {
        $this->actAs(['production.view']);

        // 101 more completed Night batches on machine 2 → two index pages.
        for ($i = 1; $i <= 101; $i++) {
            $this->entry([
                'shift_id' => $this->night->id, 'work_center_id' => $this->m2->id, 'item_id' => $this->bottleB->id,
                'batch_number' => sprintf('20260810-M02-%03d', 100 + $i),
                'quantity_produced' => '10', 'quantity_produced_kg' => '0.1000',
            ]);
        }

        $index = $this->getJson('/api/v1/production/shift-production-entries?'.http_build_query([
            'production_date' => self::DATE, 'shift_id' => $this->night->id, 'batch_status' => 'completed', 'per_page' => 100,
        ]))->assertOk();
        $this->assertSame(2, $index->json('meta.last_page'));
        $this->assertSame(102, $index->json('meta.total'));

        $night = $this->cec('date='.self::DATE.'&shift_id='.$this->night->id)['shifts'][0];
        $this->assertSame(102, $night['sums']['batches']);
        $this->assertCount(102, $this->batchesOf($night));
        $this->assertSame($night['summary']['actual_production_kg'], $night['sums']['good_production_kg']);
        $this->assertSame('110.9000', $night['sums']['good_production_kg']);
    }

    /**
     * The sums recomputed in the test the plain way — bcadd over each batch's
     * figure at 4dp (2dp for minutes), integers for packs, a null skipped
     * and counted — so the service's sums are asserted, not trusted.
     *
     * @param  list<array<string, mixed>>  $batches
     * @return array<string, mixed>
     */
    private function plainSums(array $batches): array
    {
        $out = ['batches' => count($batches)];
        $skipped = [];
        foreach (['expected_pieces' => 4, 'actual_pieces' => 4, 'good_production_kg' => 4, 'rejection_kg' => 4, 'packs' => null, 'downtime_minutes_total' => 2] as $key => $scale) {
            $sum = null;
            foreach ($batches as $batch) {
                if ($batch[$key] === null) {
                    $skipped[$key] = ($skipped[$key] ?? 0) + 1;

                    continue;
                }
                $sum = $scale === null
                    ? ($sum ?? 0) + (int) $batch[$key]
                    : bcadd($sum ?? bcadd('0', '0', $scale), (string) $batch[$key], $scale);
            }
            $out[$key] = $sum;
        }
        $out['skipped_nulls'] = $skipped;

        return $out;
    }

    /** @return array<string, mixed> a sums block without its basis sentence */
    private function figuresOf(array $sums): array
    {
        unset($sums['basis']);

        return $sums;
    }
}
