<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\LogStatus;
use App\Modules\Production\Models\MachineDowntimeLog;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\MoldChangeLog;
use App\Modules\Production\Models\PowerInterruptionLog;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftStockCount;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\MachineDowntimeLogService;
use App\Modules\Production\Services\MoldChangeLogService;
use App\Modules\Production\Services\PowerInterruptionLogService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Production\Services\ShiftStockCountService;
use App\Modules\Production\Services\ShiftSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5.7 (WS-A) — the Shift KPI Summary's contract, pinned for the first
 * time. ShiftSummaryService::report(?int $shiftId, string $productionDate)
 * had no test of its own; the CEC (WS-B) and the page's reconcile line
 * (WS-C) are about to compose its figures, so what it answers is written
 * down here, figure by figure, against a day built through the REAL paths:
 * batches started and completed by ShiftProductionEntryService, logs opened
 * and closed by their own services, the supervisor's row by upsert().
 *
 * The day under test is HISTORICAL — two weeks before the frozen "now" — so
 * every assertion also proves the report answers for the date it is asked
 * about and never derives one from the clock. Three shifts, three machines,
 * two items:
 *
 *   Shift A (06–14): M1 ran X 5000 pcs / 100 scrap (60.0000 kg / 1.2000 kg);
 *                    M2 ran Y 3000 pcs (30.0000 kg); a CLOSED 30-minute
 *                    breakdown on M1; a 30-minute power cut; a stock count;
 *                    supervisor target 100 kg, power 200 units, a remark.
 *   Shift B (14–22): M1 ran X 4000 pcs / 50 scrap (48.0000 / 0.6000); a
 *                    closed mould change on M2 (45 min); a closed 15-minute
 *                    breakdown on M2; supervisor target 50 kg, no power figure.
 *   Shift C (22–06): M2 ran Y 2500 pcs / 25 scrap (25.0000 / 0.2500); M3
 *                    started X and is STILL RUNNING; an OPEN breakdown on
 *                    M3; a 15-minute power cut; a stock count; no
 *                    supervisor row at all.
 *
 * Plus one batch the day before and one today, which must never bleed in.
 *
 * Two things the report says about itself, added here and asserted below:
 * `machines_running_now` / `machines_down_now` (the old keys kept as
 * aliases for one release) — those two counts test the CURRENT state of the
 * date's batches and breakdowns, not what ran that shift — and
 * `efficiency_basis` / `kpi_inputs`, which name the supervisor-typed inputs
 * every KPI that has one divides by. No figure's arithmetic changed.
 */
class ShiftSummaryReportTest extends TestCase
{
    use RefreshDatabase;

    /** The day under test — historical, and asked for by name. */
    private const DATE = '2026-08-03';

    private const DAY_BEFORE = '2026-08-02';

    /** The frozen clock: two weeks after the day under test. */
    private const NOW = '2026-08-17 10:00:00';

    private const TODAY = '2026-08-17';

    private User $user;

    private Shift $a;

    private Shift $b;

    private Shift $c;

    private WorkCenter $m1;

    private WorkCenter $m2;

    private WorkCenter $m3;

    private Item $x;

    private Item $y;

    private Warehouse $fg;

    private Employee $supervisor;

    /** @var array<string, list<ShiftProductionEntry>> completed batches of the day, by shift letter */
    private array $completed = ['a' => [], 'b' => [], 'c' => []];

    private ShiftProductionEntry $stillRunning;

    private MachineDowntimeLog $breakdownA;

    private MachineDowntimeLog $breakdownB;

    private MachineDowntimeLog $breakdownC;

    private MoldChangeLog $mouldChangeB;

    private PowerInterruptionLog $powerCutA;

    private PowerInterruptionLog $powerCutC;

    private ShiftStockCount $countA;

    private ShiftStockCount $countC;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        $this->user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $this->user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($this->user);

        $this->a = Shift::create(['name' => 'Shift A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->b = Shift::create(['name' => 'Shift B', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);
        $this->c = Shift::create(['name' => 'Shift C', 'start_time' => '22:00', 'end_time' => '06:00', 'is_active' => true]);
        $this->m1 = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        $this->m2 = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'display_sequence' => 2]);
        $this->m3 = WorkCenter::create(['code' => 'MC-03', 'name' => 'Machine 3', 'display_sequence' => 3]);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
        // 12 g and 10 g: kg figures below are pieces × weight / 1000, at the
        // weight Start Batch freezes on the run (item master, no standard).
        $this->x = Item::create(['sku' => 'BTL-X', 'name' => 'Bottle X', 'uom' => 'Nos.', 'nominal_weight_grams' => '12']);
        $this->y = Item::create(['sku' => 'BTL-Y', 'name' => 'Bottle Y', 'uom' => 'Nos.', 'nominal_weight_grams' => '10']);
        $this->supervisor = Employee::create(['employee_code' => 'SUP-01', 'name' => 'Karthik Raman', 'date_of_joining' => '2024-01-01', 'status' => 'active']);

        $this->seedTheDay();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixture, built through the real paths --------------------------------

    private function seedTheDay(): void
    {
        // Batches: Start Batch then Complete Batch, exactly as the floor does.
        $this->completed['a'][] = $this->runBatch($this->a, $this->m1, $this->x, self::DATE, '5000', '100');
        $this->completed['a'][] = $this->runBatch($this->a, $this->m2, $this->y, self::DATE, '3000', '0');
        $this->completed['b'][] = $this->runBatch($this->b, $this->m1, $this->x, self::DATE, '4000', '50');
        $this->completed['c'][] = $this->runBatch($this->c, $this->m2, $this->y, self::DATE, '2500', '25');
        $this->stillRunning = $this->startBatch($this->c, $this->m3, $this->x, self::DATE);

        // Neighbouring days: the day before, and today — neither is the 3rd.
        $this->runBatch($this->a, $this->m1, $this->x, self::DAY_BEFORE, '1000', '0');
        $this->runBatch($this->a, $this->m2, $this->y, self::TODAY, '700', '0');

        // Shift A: a breakdown, fixed the same shift; a power cut; a count;
        // the supervisor's own inputs.
        $downtime = app(MachineDowntimeLogService::class);
        $this->breakdownA = $downtime->open([
            'work_center_id' => $this->m1->id, 'shift_id' => $this->a->id, 'production_date' => self::DATE,
            'nature_of_problem' => 'Heater band', 'from_time' => '2026-08-03 08:00:00',
        ], $this->user->id);
        $this->breakdownA = $downtime->close($this->breakdownA, ['remedy' => 'Replaced', 'to_time' => '2026-08-03 08:30:00']);

        $this->powerCutA = app(PowerInterruptionLogService::class)->create([
            'shift_id' => $this->a->id, 'production_date' => self::DATE,
            'from_time' => '2026-08-03 09:00:00', 'to_time' => '2026-08-03 09:30:00',
        ], $this->user->id);

        $this->countA = app(ShiftStockCountService::class)->create([
            'shift_id' => $this->a->id, 'production_date' => self::DATE,
            'location_label' => 'Bin A', 'item_id' => $this->x->id, 'quantity_kg' => '100.0000',
        ], $this->user->id);

        $summaries = app(ShiftSummaryService::class);
        $summaries->upsert([
            'shift_id' => $this->a->id, 'production_date' => self::DATE, 'supervisor_id' => $this->supervisor->id,
            'target_production_kg' => '100', 'power_consumption_units' => '200', 'remarks' => 'Smooth run',
        ], $this->user->id);

        // Shift B: a mould change logged start-to-finish, a short breakdown,
        // a target but no power reading.
        $mould = Mold::create(['code' => 'MLD-Y', 'name' => 'Mould Y', 'cavity_count' => 4]);
        $this->mouldChangeB = app(MoldChangeLogService::class)->open([
            'work_center_id' => $this->m2->id, 'shift_id' => $this->b->id, 'production_date' => self::DATE,
            'changed_from_item_id' => $this->y->id, 'changed_to_item_id' => $this->x->id, 'changed_to_mold_id' => $mould->id,
            'from_time' => '2026-08-03 15:00:00', 'to_time' => '2026-08-03 15:45:00',
        ], $this->user->id);
        $this->breakdownB = $downtime->open([
            'work_center_id' => $this->m2->id, 'shift_id' => $this->b->id, 'production_date' => self::DATE,
            'nature_of_problem' => 'Hydraulic leak', 'from_time' => '2026-08-03 17:00:00',
        ], $this->user->id);
        $this->breakdownB = $downtime->close($this->breakdownB, ['to_time' => '2026-08-03 17:15:00']);
        $summaries->upsert([
            'shift_id' => $this->b->id, 'production_date' => self::DATE,
            'target_production_kg' => '50', 'remarks' => 'One mould change',
        ], $this->user->id);

        // Shift C: an OPEN breakdown (nobody has closed it yet), a power cut,
        // a count — and no supervisor row.
        $this->breakdownC = $downtime->open([
            'work_center_id' => $this->m3->id, 'shift_id' => $this->c->id, 'production_date' => self::DATE,
            'nature_of_problem' => 'Screw jammed', 'from_time' => '2026-08-04 01:00:00',
        ], $this->user->id);
        $this->powerCutC = app(PowerInterruptionLogService::class)->create([
            'shift_id' => $this->c->id, 'production_date' => self::DATE,
            'from_time' => '2026-08-03 23:00:00', 'to_time' => '2026-08-03 23:15:00',
        ], $this->user->id);
        $this->countC = app(ShiftStockCountService::class)->create([
            'shift_id' => $this->c->id, 'production_date' => self::DATE,
            'location_label' => 'Bin C', 'item_id' => $this->y->id, 'quantity_kg' => '50.0000',
        ], $this->user->id);
    }

    private function startBatch(Shift $shift, WorkCenter $machine, Item $item, string $date): ShiftProductionEntry
    {
        return app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => $date,
        ], $this->user->id);
    }

    private function runBatch(Shift $shift, WorkCenter $machine, Item $item, string $date, string $pieces, string $scrap): ShiftProductionEntry
    {
        return app(ShiftProductionEntryService::class)->completeBatch(
            $this->startBatch($shift, $machine, $item, $date),
            ['quantity_produced' => $pieces, 'quantity_scrap' => $scrap],
            $this->user->id,
        );
    }

    /** @return array<string, mixed> */
    private function report(?Shift $shift, string $date = self::DATE): array
    {
        return app(ShiftSummaryService::class)->report($shift?->id, $date);
    }

    /**
     * Σ of a column over the completed batches this test itself made for a
     * shift (or the whole day) — the figure the report has to agree with.
     *
     * @param  list<ShiftProductionEntry>  $entries
     */
    private function sum(array $entries, string $column): string
    {
        return array_reduce(
            $entries,
            fn (string $carry, ShiftProductionEntry $e) => bcadd($carry, (string) ($e->fresh()->{$column} ?? '0'), 4),
            '0.0000',
        );
    }

    /** @return list<ShiftProductionEntry> */
    private function allCompleted(): array
    {
        return array_merge($this->completed['a'], $this->completed['b'], $this->completed['c']);
    }

    // ---- the date is the one asked for ----------------------------------------

    public function test_a_past_date_is_served_as_asked_and_never_derived_from_today(): void
    {
        $this->assertSame(self::TODAY, now()->toDateString(), 'the clock is frozen two weeks after the day under test');

        $day = $this->report(null);

        $this->assertSame(self::DATE, $day['production_date']);
        $this->assertNull($day['shift_id']);

        // The 3rd's completed batches, and only those: 60 + 30 + 48 + 25.
        // The 1000 pcs of the day before and today's 700 pcs are elsewhere.
        $this->assertSame('163.0000', $day['actual_production_kg']);
        $this->assertSame('163.0000', $this->sum($this->allCompleted(), 'quantity_produced_kg'));

        // Yesterday's and today's own reports carry their own batch, nothing else.
        $this->assertSame('12.0000', $this->report(null, self::DAY_BEFORE)['actual_production_kg']);
        $this->assertSame('7.0000', $this->report(null, self::TODAY)['actual_production_kg']);
    }

    // ---- per shift: kg == Σ completed batches ----------------------------------

    public function test_each_shifts_actual_kg_is_the_sum_of_its_completed_batches_and_the_entries_index_agrees(): void
    {
        foreach (['a' => $this->a, 'b' => $this->b, 'c' => $this->c] as $letter => $shift) {
            $report = $this->report($shift);

            $this->assertSame($shift->id, $report['shift_id']);
            $this->assertSame(self::DATE, $report['production_date']);
            $this->assertSame(
                $this->sum($this->completed[$letter], 'quantity_produced_kg'),
                $report['actual_production_kg'],
                "Shift {$letter}: actual_production_kg is Σ quantity_produced_kg of its completed batches",
            );

            // The SAME figure the entries index answers for that date/shift —
            // the reconcile line the page draws (WS-C) compares exactly these.
            $rows = $this->getJson('/api/v1/production/shift-production-entries?'.http_build_query([
                'production_date' => self::DATE, 'shift_id' => $shift->id, 'batch_status' => 'completed', 'per_page' => 100,
            ]))->assertOk()->json('data');
            $this->assertCount(count($this->completed[$letter]), $rows);
            $indexed = array_reduce($rows, fn (string $carry, array $row) => bcadd($carry, (string) ($row['quantity_produced_kg'] ?? '0'), 4), '0.0000');
            $this->assertSame($indexed, $report['actual_production_kg'], "Shift {$letter}: the entries index and the summary agree");
        }

        // Pinned once, as a person reads them.
        $this->assertSame('90.0000', $this->report($this->a)['actual_production_kg']);
        $this->assertSame('48.0000', $this->report($this->b)['actual_production_kg']);
        $this->assertSame('25.0000', $this->report($this->c)['actual_production_kg']);
    }

    public function test_a_batch_still_running_contributes_no_kg_until_it_is_completed(): void
    {
        $this->assertSame(BatchStatus::InProgress, $this->stillRunning->fresh()->batch_status);
        $this->assertSame('25.0000', $this->report($this->c)['actual_production_kg'], 'only M2\'s completed batch counts');
        $this->assertCount(1, $this->report($this->c)['items_manufactured']);
    }

    // ---- rejection figures ------------------------------------------------------

    public function test_rejection_figures_are_consistent_with_the_entries(): void
    {
        foreach (['a' => $this->a, 'b' => $this->b, 'c' => $this->c] as $letter => $shift) {
            $report = $this->report($shift);
            $entries = $this->completed[$letter];

            $rejection = $this->sum($entries, 'quantity_rejection_kg');
            $actual = $this->sum($entries, 'quantity_produced_kg');

            $this->assertSame($rejection, $report['rejection_kg'], "Shift {$letter}: rejection_kg is Σ quantity_rejection_kg");
            $this->assertSame(bcsub($actual, $rejection, 4), $report['net_good_output_kg'], "Shift {$letter}: net = actual − rejection");
            $this->assertSame(
                (float) bcdiv(bcmul($rejection, '100', 4), $actual, 4),
                $report['rejection_percent'],
                "Shift {$letter}: rejection_percent = rejection / actual × 100",
            );

            // items_manufactured: one row per item, counts and kg from the same rows.
            foreach ($report['items_manufactured'] as $row) {
                $ofItem = array_values(array_filter($entries, fn (ShiftProductionEntry $e) => $e->item_id === $row['item']['id']));
                $this->assertCount($row['batches'], $ofItem);
                $this->assertSame($this->sum($ofItem, 'quantity_produced'), $row['quantity_produced']);
                $this->assertSame($this->sum($ofItem, 'quantity_produced_kg'), $row['quantity_produced_kg']);
                $this->assertSame($this->sum($ofItem, 'quantity_scrap'), $row['quantity_rejected']);
                $this->assertSame($this->sum($ofItem, 'quantity_rejection_kg'), $row['quantity_rejection_kg']);
            }
        }

        // Pinned: 100 pcs × 12 g = 1.2 kg on 90 kg.
        $a = $this->report($this->a);
        $this->assertSame('1.2000', $a['rejection_kg']);
        $this->assertSame('88.8000', $a['net_good_output_kg']);
        $this->assertSame(1.3333, $a['rejection_percent']);
    }

    // ---- each log lands in its shift --------------------------------------------

    public function test_each_log_lands_in_its_shift_and_nowhere_else(): void
    {
        $a = $this->report($this->a);
        $b = $this->report($this->b);
        $c = $this->report($this->c);

        // Downtime: A's closed 30 min, B's closed 15 min, C's still open.
        $this->assertSame([$this->breakdownA->id], collect($a['downtime_logs'])->pluck('id')->all());
        $this->assertSame([$this->breakdownB->id], collect($b['downtime_logs'])->pluck('id')->all());
        $this->assertSame([$this->breakdownC->id], collect($c['downtime_logs'])->pluck('id')->all());
        $this->assertSame('0.5000', $a['idle_time_hours']);
        $this->assertSame('0.2500', $b['idle_time_hours']);
        $this->assertSame('0.0000', $c['idle_time_hours'], 'an OPEN breakdown is not idle time yet — nobody has decided its total');
        $this->assertSame(LogStatus::Closed->value, $a['downtime_logs'][0]['status']);
        $this->assertSame('Machine 1', $a['downtime_logs'][0]['work_center']);
        $this->assertSame(LogStatus::Open->value, $c['downtime_logs'][0]['status']);
        $this->assertNull($c['downtime_logs'][0]['to_time']);

        // Mould changes: B's only.
        $this->assertCount(0, $a['mold_change_logs']);
        $this->assertSame([$this->mouldChangeB->id], collect($b['mold_change_logs'])->pluck('id')->all());
        $this->assertCount(0, $c['mold_change_logs']);
        $this->assertSame([0, 1, 0], [$a['no_of_mold_changes'], $b['no_of_mold_changes'], $c['no_of_mold_changes']]);
        $this->assertSame('BTL-Y', $b['mold_change_logs'][0]['changed_from']);
        $this->assertSame('BTL-X', $b['mold_change_logs'][0]['changed_to']);
        $this->assertSame('MLD-Y', $b['mold_change_logs'][0]['changed_to_mold']);

        // Power cuts: A's 30 min and C's 15 min — plant-wide, kept apart from idle time.
        $this->assertSame([$this->powerCutA->id], collect($a['power_interruption_logs'])->pluck('id')->all());
        $this->assertCount(0, $b['power_interruption_logs']);
        $this->assertSame([$this->powerCutC->id], collect($c['power_interruption_logs'])->pluck('id')->all());
        $this->assertSame(['0.5000', '0.0000', '0.2500'], [$a['power_interruption_hours'], $b['power_interruption_hours'], $c['power_interruption_hours']]);

        // Stock counts: A's and C's.
        $this->assertSame([$this->countA->id], collect($a['stock_counts'])->pluck('id')->all());
        $this->assertCount(0, $b['stock_counts']);
        $this->assertSame([$this->countC->id], collect($c['stock_counts'])->pluck('id')->all());
        $this->assertSame('Bin A', $a['stock_counts'][0]['location_label']);
        $this->assertSame('BTL-X', $a['stock_counts'][0]['item']['sku']);
        $this->assertSame(0, bccomp('100', (string) $a['stock_counts'][0]['quantity_kg'], 4));
    }

    // ---- the day == the three shifts ---------------------------------------------

    public function test_the_day_wide_report_is_the_sum_of_the_three_shifts(): void
    {
        $day = $this->report(null);
        $shifts = [$this->report($this->a), $this->report($this->b), $this->report($this->c)];

        $sum = fn (string $key) => array_reduce($shifts, fn (string $carry, array $r) => bcadd($carry, (string) ($r[$key] ?? '0'), 4), '0.0000');

        // The kg figures — exact, to the last digit.
        foreach (['actual_production_kg', 'rejection_kg', 'net_good_output_kg'] as $key) {
            $this->assertSame($sum($key), $day[$key], "day {$key} == Σ shifts");
        }
        $this->assertSame('163.0000', $day['actual_production_kg']);
        $this->assertSame('2.0500', $day['rejection_kg']);
        $this->assertSame('160.9500', $day['net_good_output_kg']);

        // The supervisor inputs: the day sums whatever was typed; a shift
        // with no row (C) contributes nothing rather than blocking the total.
        $this->assertSame('150.0000', $day['target_production_kg']);
        $this->assertSame('200.0000', $day['power_consumption_units']);

        // Hours and counts.
        $this->assertSame($sum('idle_time_hours'), $day['idle_time_hours']);
        $this->assertSame('0.7500', $day['idle_time_hours']);
        $this->assertSame($sum('power_interruption_hours'), $day['power_interruption_hours']);
        $this->assertSame('0.7500', $day['power_interruption_hours']);
        $this->assertSame(1, $day['no_of_mold_changes']);
        $this->assertCount(3, $day['downtime_logs']);
        $this->assertCount(1, $day['mold_change_logs']);
        $this->assertCount(2, $day['power_interruption_logs']);
        $this->assertCount(2, $day['stock_counts']);

        // The ratios are recomputed from the DAY's totals — never a sum of
        // the shifts' percentages: 163 / 150 = 108.67 %, 2.05 / 163 = 1.26 %.
        $this->assertSame((float) bcdiv(bcmul('163.0000', '100', 4), '150.0000', 4), $day['efficiency_percent']);
        $this->assertSame(108.6666, $day['efficiency_percent']);
        $this->assertSame((float) bcdiv(bcmul('2.0500', '100', 4), '163.0000', 4), $day['rejection_percent']);
        $this->assertSame((float) bcdiv('200.0000', '163.0000', 4), $day['unit_per_kg']);

        // items_manufactured: per item, the day is the shifts' rows added up.
        $byItem = collect($day['items_manufactured'])->keyBy('item.sku');
        $this->assertSame(['BTL-X', 'BTL-Y'], $byItem->keys()->sort()->values()->all());
        $this->assertSame(2, $byItem['BTL-X']['batches']);
        $this->assertSame('9000.0000', $byItem['BTL-X']['quantity_produced']);
        $this->assertSame('108.0000', $byItem['BTL-X']['quantity_produced_kg']);
        $this->assertSame('150.0000', $byItem['BTL-X']['quantity_rejected']);
        $this->assertSame('1.8000', $byItem['BTL-X']['quantity_rejection_kg']);
        $this->assertSame(2, $byItem['BTL-Y']['batches']);
        $this->assertSame('55.0000', $byItem['BTL-Y']['quantity_produced_kg']);
        $this->assertSame('0.2500', $byItem['BTL-Y']['quantity_rejection_kg']);

        // Day-wide has no single accountable name; the remarks are joined.
        $this->assertNull($day['supervisor']);
        $this->assertSame('Smooth run | One mould change', $day['remarks']);
    }

    public function test_the_day_idle_hours_come_from_the_raw_minutes_not_from_the_shifts_rounded_hours(): void
    {
        // Three 10-minute breakdowns on another day, one per shift, closed.
        // Each shift shows 10/60 truncated to four places (0.1666); the day
        // divides the RAW 30 minutes once (0.5000). So the day's hours are
        // not the sum of the shifts' displayed hours — the day is the more
        // precise figure, computed from the source, not from the rounded
        // shift figures. Pinned so nobody "fixes" the day down to 0.4998, and
        // so a composer (the CEC) knows to reconcile kg, which is exact, and
        // not to expect displayed hours to add up to the last digit.
        $date = '2026-07-20';
        $downtime = app(MachineDowntimeLogService::class);
        // M3's breakdown of the day under test is still open, and open()
        // refuses a second open log on a machine — close it first (that
        // changes nothing on the 20th).
        $downtime->close($this->breakdownC, ['to_time' => '2026-08-04 02:00:00']);
        foreach ([[$this->a, $this->m1, '08:00'], [$this->b, $this->m2, '16:00'], [$this->c, $this->m3, '23:00']] as [$shift, $machine, $at]) {
            $log = $downtime->open([
                'work_center_id' => $machine->id, 'shift_id' => $shift->id, 'production_date' => $date,
                'nature_of_problem' => 'Ten minutes', 'from_time' => "{$date} {$at}:00",
            ], $this->user->id);
            $downtime->close($log, ['to_time' => Carbon::parse("{$date} {$at}:00")->addMinutes(10)->toDateTimeString()]);
        }

        $this->assertSame('0.1666', $this->report($this->a, $date)['idle_time_hours']);
        $this->assertSame('0.1666', $this->report($this->b, $date)['idle_time_hours']);
        $this->assertSame('0.1666', $this->report($this->c, $date)['idle_time_hours']);
        $this->assertSame('0.5000', $this->report(null, $date)['idle_time_hours']);
    }

    // ---- efficiency: the supervisor's target, or nothing ---------------------------

    public function test_efficiency_is_null_without_a_supervisor_target_and_the_report_names_its_basis(): void
    {
        $a = $this->report($this->a);
        $b = $this->report($this->b);
        $c = $this->report($this->c);
        $day = $this->report(null);

        // Shift A: target 100 typed → 90 / 100.
        $this->assertSame('100.0000', $a['target_production_kg']);
        $this->assertSame(90.0, $a['efficiency_percent']);
        $this->assertSame('supervisor_target', $a['efficiency_basis']);
        $this->assertSame(['target_production_kg' => 'supervisor', 'power_consumption_units' => 'supervisor'], $a['kpi_inputs']);
        $this->assertSame('200.0000', $a['power_consumption_units']);
        $this->assertSame((float) bcdiv('200.0000', '90.0000', 4), $a['unit_per_kg']);
        $this->assertSame('SUP-01', $a['supervisor']->employee_code);
        $this->assertSame('Smooth run', $a['remarks']);

        // Shift B: target 50 typed, no power reading → efficiency yes, unit/kg no.
        $this->assertSame(96.0, $b['efficiency_percent']);
        $this->assertSame('supervisor_target', $b['efficiency_basis']);
        $this->assertNull($b['power_consumption_units']);
        $this->assertNull($b['unit_per_kg']);
        $this->assertSame(['target_production_kg' => 'supervisor', 'power_consumption_units' => null], $b['kpi_inputs']);
        $this->assertNull($b['supervisor'], 'a row with no supervisor named');

        // Shift C: no supervisor row at all → no target, no efficiency, and
        // the report SAYS so instead of inventing a denominator.
        $this->assertNull($c['target_production_kg']);
        $this->assertNull($c['efficiency_percent']);
        $this->assertNull($c['efficiency_basis']);
        $this->assertNull($c['power_consumption_units']);
        $this->assertNull($c['unit_per_kg']);
        $this->assertSame(['target_production_kg' => null, 'power_consumption_units' => null], $c['kpi_inputs']);
        $this->assertNull($c['supervisor']);
        $this->assertNull($c['remarks']);

        // The day: the summed typed target is still the supervisors' figure.
        $this->assertSame('supervisor_target', $day['efficiency_basis']);
        $this->assertSame(['target_production_kg' => 'supervisor', 'power_consumption_units' => 'supervisor'], $day['kpi_inputs']);
    }

    public function test_a_zero_target_yields_no_efficiency_and_no_basis(): void
    {
        app(ShiftSummaryService::class)->upsert([
            'shift_id' => $this->c->id, 'production_date' => self::DATE, 'target_production_kg' => '0',
        ], $this->user->id);

        $c = $this->report($this->c);

        // A typed target of zero cannot be divided by; the input is on
        // record but no efficiency is claimed against it.
        $this->assertSame('0.0000', $c['target_production_kg']);
        $this->assertNull($c['efficiency_percent']);
        $this->assertNull($c['efficiency_basis']);
        $this->assertSame('supervisor', $c['kpi_inputs']['target_production_kg']);
    }

    // ---- machines running / down: "now", not the date's fact -----------------------

    public function test_machines_running_and_down_are_labelled_now_and_the_old_keys_alias_them(): void
    {
        $a = $this->report($this->a);
        $c = $this->report($this->c);
        $day = $this->report(null);

        // Shift A RAN two machines and HAD a breakdown — but both batches
        // are completed and the breakdown is closed, so "now" is nothing.
        $this->assertSame(0, $a['machines_running_now']);
        $this->assertSame(0, $a['machines_down_now']);

        // Shift C: M3's batch is still in progress and its breakdown open.
        $this->assertSame(1, $c['machines_running_now']);
        $this->assertSame(1, $c['machines_down_now']);
        $this->assertSame(1, $day['machines_running_now']);
        $this->assertSame(1, $day['machines_down_now']);

        // The old keys are aliases for one release — same values, same rows.
        foreach ([$a, $c, $day] as $report) {
            $this->assertSame($report['machines_running_now'], $report['machines_running']);
            $this->assertSame($report['machines_down_now'], $report['machines_down']);
        }

        // Proof that they test the CURRENT state: close the breakdown and
        // complete the batch NOW (two weeks later) — nothing about the 3rd's
        // history changed, yet both counts drop to zero. The batch's kg, by
        // contrast, land on the 3rd, the date it is filed under.
        app(MachineDowntimeLogService::class)->close($this->breakdownC, ['to_time' => now()->toDateTimeString()]);
        app(ShiftProductionEntryService::class)->completeBatch($this->stillRunning, ['quantity_produced' => '1000'], $this->user->id);

        $after = $this->report($this->c);
        $this->assertSame(0, $after['machines_running_now']);
        $this->assertSame(0, $after['machines_down_now']);
        $this->assertSame('37.0000', $after['actual_production_kg'], '25 + 1000 × 12 g');
    }

    // ---- nothing recorded ---------------------------------------------------------

    public function test_a_date_with_nothing_recorded_answers_honest_zeros_and_nulls(): void
    {
        foreach ([$this->report(null, '2026-07-01'), $this->report($this->a, '2026-07-01')] as $report) {
            $this->assertSame('2026-07-01', $report['production_date']);
            $this->assertNull($report['target_production_kg']);
            $this->assertSame('0.0000', $report['actual_production_kg']);
            $this->assertSame('0.0000', $report['rejection_kg']);
            $this->assertSame('0.0000', $report['net_good_output_kg']);
            $this->assertNull($report['efficiency_percent']);
            $this->assertNull($report['efficiency_basis']);
            $this->assertNull($report['rejection_percent']);
            $this->assertSame(0, $report['machines_running_now']);
            $this->assertSame(0, $report['machines_down_now']);
            $this->assertSame(0, $report['machines_running']);
            $this->assertSame(0, $report['machines_down']);
            $this->assertSame('0.0000', $report['idle_time_hours']);
            $this->assertSame(0, $report['no_of_mold_changes']);
            $this->assertNull($report['power_consumption_units']);
            $this->assertNull($report['unit_per_kg']);
            $this->assertSame('0.0000', $report['power_interruption_hours']);
            $this->assertSame(['target_production_kg' => null, 'power_consumption_units' => null], $report['kpi_inputs']);
            $this->assertNull($report['supervisor']);
            $this->assertEmpty($report['remarks']);
            foreach (['items_manufactured', 'downtime_logs', 'mold_change_logs', 'power_interruption_logs', 'stock_counts'] as $list) {
                $this->assertCount(0, $report[$list], $list);
            }
        }

        // The one cosmetic asymmetry, pinned as it is: a shift's remarks are
        // the row's (null when there is no row); the day's are the rows'
        // remarks joined, which is '' when there are none.
        $this->assertNull($this->report($this->a, '2026-07-01')['remarks']);
        $this->assertSame('', $this->report(null, '2026-07-01')['remarks']);
    }

    // ---- the endpoint -----------------------------------------------------------

    public function test_the_endpoint_serves_the_same_report_with_the_honesty_keys_under_the_one_grammar(): void
    {
        $data = $this->getJson('/api/v1/production/shift-summaries/report?'.http_build_query([
            'production_date' => self::DATE, 'shift_id' => $this->c->id,
        ]))->assertOk()->json('data');

        $this->assertSame(self::DATE, $data['production_date']);
        $this->assertSame($this->c->id, $data['shift_id']);
        $this->assertSame('25.0000', $data['actual_production_kg']);
        foreach (['machines_running_now', 'machines_down_now', 'machines_running', 'machines_down', 'efficiency_basis', 'kpi_inputs'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame(1, $data['machines_running_now']);
        $this->assertNull($data['efficiency_basis']);
        $this->assertSame(['target_production_kg' => null, 'power_consumption_units' => null], $data['kpi_inputs']);

        // The day-wide call: shift_id omitted.
        $day = $this->getJson('/api/v1/production/shift-summaries/report?production_date='.self::DATE)->assertOk()->json('data');
        $this->assertNull($day['shift_id']);
        $this->assertSame('163.0000', $day['actual_production_kg']);
        $this->assertSame('supervisor_target', $day['efficiency_basis']);

        // The ONE grammar: production_date required, shift_id must exist.
        $this->getJson('/api/v1/production/shift-summaries/report')->assertUnprocessable()->assertJsonValidationErrors('production_date');
        $this->getJson('/api/v1/production/shift-summaries/report?production_date=2026-08-03&shift_id=999')->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    }
}
