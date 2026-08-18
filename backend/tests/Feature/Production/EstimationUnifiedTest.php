<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionDowntimeEvent;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BatchEstimationService;
use App\Modules\Production\Services\ProductionCalculationEngine;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5.5 P5.5-03 — ONE estimation, versioned.
 *
 * Until this phase two formulas answered "what should this run have made":
 * the Start Batch preview floored the cycle count through the engine and
 * knew no downtime; the completion metrics inlined the WB2 workbook
 * formula unfloored, net of the downtime logged at completion. The same
 * run could therefore be told "5 boxes expected" at Start and "6 boxes
 * expected" at Approval (ExpectedOutputDivergenceTest). And although every
 * entry stamps a calculation_version at Start, nothing read it — the
 * docblock's "never recalculated by a later engine change" was a promise
 * without a mechanism.
 *
 * Now:
 *   - production_v3_unified — the engine's targetPieces() (cycles FLOORED
 *     before cavities multiply) is the ONE formula. The preview feeds it
 *     planned hours (downtime not yet known: `downtime_netted: false`);
 *     the completion metrics feed it running hours NET of the
 *     completion-recorded downtime, through the engine's targets(). Zero
 *     downtime → the two agree to the piece.
 *   - production_v2_floor / legacy_v1 / null (started before this change)
 *     — the metrics keep the inline WB2 computation BYTE-FOR-BYTE
 *     (LegacyEntryMetrics), selected by the stamp. History is never
 *     recomputed under a new formula.
 *   - New entries stamp production_v3_unified at Start.
 *   - Efficiency stays piece-grain, 1dp, in both.
 *
 * The owner-decided inputs (cycle times, cavities, pack counts) in every
 * row below are the workbook's own figures — nothing is interpolated.
 */
class EstimationUnifiedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The formulas are under test, not the readiness gate — the same
        // minimal-fixture rationale as ExpectedOutputEngineTest.
        config()->set('production.readiness.enforced', false);
    }

    // =================================================================
    // Table-driven: the workbook cases, preview vs completion under v3
    // =================================================================

    /**
     * Owner figures only: (hours, CT, cavities, pieces/box) → what the
     * FLOORED formula yields, and — for the boundary pins — what the
     * unfloored WB2 inline yielded for the same inputs.
     *
     * @return array<string, array{
     *     hours: string, ct: string, cavities: int, perBox: int,
     *     v3Pieces: int, v3Boxes: int, legacyPieces: string, legacyBoxes: int,
     * }>
     */
    public static function workbookCases(): array
    {
        return [
            // WB2 row 6: 144000/10.6 = 13584.9056… unfloored; FLOOR(28800/10.6)=2716 × 5 = 13580.
            'WB2 row 6 — CT 10.6, 5 cav, 8 h, 840/box' => [
                'hours' => '8', 'ct' => '10.6', 'cavities' => 5, 'perBox' => 840,
                'v3Pieces' => 13580, 'v3Boxes' => 16, 'legacyPieces' => '13584.91', 'legacyBoxes' => 16,
            ],
            // WB2 row 7: 28800/12 = 2400 exactly — the two formulas coincide.
            'WB2 row 7 — CT 12, 5 cav, 8 h, 1040/box' => [
                'hours' => '8', 'ct' => '12', 'cavities' => 5, 'perBox' => 1040,
                'v3Pieces' => 12000, 'v3Boxes' => 12, 'legacyPieces' => '12000.00', 'legacyBoxes' => 12,
            ],
            // Partial shift: 28800/13.6 = 2117.647… unfloored; FLOOR(14400/13.6)=1058 × 2 = 2116.
            'partial shift — CT 13.6, 2 cav, 4 h, 161/box' => [
                'hours' => '4', 'ct' => '13.6', 'cavities' => 2, 'perBox' => 161,
                'v3Pieces' => 2116, 'v3Boxes' => 13, 'legacyPieces' => '2117.65', 'legacyBoxes' => 13,
            ],
            // The factory's Daily Production row: FLOOR(28800/12.2)=2360 × 5 = 11800 → 14 boxes.
            'daily report row — CT 12.2, 5 cav, 8 h, 840/box' => [
                'hours' => '8', 'ct' => '12.2', 'cavities' => 5, 'perBox' => 840,
                'v3Pieces' => 11800, 'v3Boxes' => 14, 'legacyPieces' => '11803.28', 'legacyBoxes' => 14,
            ],
            // The workbook's "Calculation Example": 12000 pieces, 810/box → 14.81 → 15.
            'calculation example — CT 12, 5 cav, 8 h, 810/box' => [
                'hours' => '8', 'ct' => '12', 'cavities' => 5, 'perBox' => 810,
                'v3Pieces' => 12000, 'v3Boxes' => 15, 'legacyPieces' => '12000.00', 'legacyBoxes' => 15,
            ],
            // The owner's live batch (30-Jul): 144000/10.8 = 13333.33 unfloored; FLOOR(28800/10.8)=2666 × 5 = 13330.
            'owner batch — CT 10.8, 5 cav, 8 h, 3038/box' => [
                'hours' => '8', 'ct' => '10.8', 'cavities' => 5, 'perBox' => 3038,
                'v3Pieces' => 13330, 'v3Boxes' => 4, 'legacyPieces' => '13333.33', 'legacyBoxes' => 4,
            ],
            // ExpectedOutputDivergenceTest's fixture: the one-shot gap that straddled a box boundary.
            'divergence fixture — CT 100.5, 8 cav, 1 h, 52/box' => [
                'hours' => '1', 'ct' => '100.5', 'cavities' => 8, 'perBox' => 52,
                'v3Pieces' => 280, 'v3Boxes' => 5, 'legacyPieces' => '286.57', 'legacyBoxes' => 6,
            ],
            // The addendum fixture: 12000 pieces, 800/box → 15.
            'addendum — CT 12, 5 cav, 8 h, 800/box' => [
                'hours' => '8', 'ct' => '12', 'cavities' => 5, 'perBox' => 800,
                'v3Pieces' => 12000, 'v3Boxes' => 15, 'legacyPieces' => '12000.00', 'legacyBoxes' => 15,
            ],
        ];
    }

    /**
     * RED BEFORE: for the divergence fixture the preview said 280/5 and
     * the metrics said 286.57/6 for one unchanged run. Under v3 both sides
     * are the same targetPieces() call, so with no downtime they agree on
     * every row — pieces AND boxes.
     */
    #[DataProvider('workbookCases')]
    public function test_under_v3_the_preview_and_the_completion_metrics_agree_for_zero_downtime(
        string $hours, string $ct, int $cavities, int $perBox, int $v3Pieces, int $v3Boxes, string $legacyPieces, int $legacyBoxes,
    ): void {
        $item = Item::create([
            'sku' => 'BTL-'.md5($ct.$cavities.$perBox), 'name' => 'Bottle', 'uom' => 'Nos.',
            'nos_per_box' => $perBox, 'standard_cycle_time' => $ct, 'standard_cavities' => $cavities,
        ]);

        // The Start Batch preview: planned hours straight in, downtime unknown.
        $preview = app(BatchEstimationService::class)->estimate($item, null, $hours, $cavities);
        $this->assertSame($v3Pieces, $preview['expected_pieces']);
        $this->assertSame($v3Boxes, $preview['expected_boxes']);
        $this->assertSame(ProductionCalculationEngine::VERSION_UNIFIED, $preview['calculation_version']);
        $this->assertFalse($preview['downtime_netted'], 'the preview knows no downtime yet and says so');

        // The completion metrics of a v3-stamped run with the same inputs.
        $metrics = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => $ct, 'active_cavities' => $cavities, 'running_hours' => $hours,
        ], ['nos_per_box' => $perBox]));

        $this->assertSame(bcadd((string) $v3Pieces, '0', 2), $metrics['expected_pieces']);
        $this->assertSame($v3Boxes, $metrics['expected_boxes']);
        $this->assertSame(ProductionCalculationEngine::VERSION_UNIFIED, $metrics['calculation_version']);
        $this->assertTrue($metrics['downtime_netted']);

        // THE POINT: one number for one run.
        $this->assertSame($preview['expected_pieces'], (int) $metrics['expected_pieces']);
        $this->assertSame($preview['expected_boxes'], $metrics['expected_boxes']);
    }

    // =================================================================
    // The v2-vs-v3 boundary: the stamp selects the formula
    // =================================================================

    /**
     * GOLDEN: the SAME inputs under production_v2_floor, legacy_v1 and a
     * null stamp yield the inline WB2 figures byte-for-byte — the numbers
     * every approved entry on the live instance was signed against.
     * Only production_v3_unified reads the engine.
     */
    #[DataProvider('workbookCases')]
    public function test_the_stamp_selects_the_formula_and_pre_v3_stamps_keep_the_inline_wb2_figures(
        string $hours, string $ct, int $cavities, int $perBox, int $v3Pieces, int $v3Boxes, string $legacyPieces, int $legacyBoxes,
    ): void {
        foreach ([ProductionCalculationEngine::VERSION_FLOOR, ProductionCalculationEngine::VERSION_LEGACY, null] as $stamp) {
            $metrics = $this->metrics($this->completedEntry([
                'calculation_version' => $stamp,
                'standard_cycle_time' => $ct, 'active_cavities' => $cavities, 'running_hours' => $hours,
            ], ['nos_per_box' => $perBox]));

            $label = $stamp ?? 'null';
            $this->assertSame($legacyPieces, $metrics['expected_pieces'], "{$label}: expected_pieces must be the inline WB2 figure");
            $this->assertSame($legacyBoxes, $metrics['expected_boxes'], "{$label}: expected_boxes must be the inline WB2 figure");
            $this->assertSame($stamp, $metrics['calculation_version']);
            // Figures the legacy formula never had are absent, never invented.
            $this->assertNull($metrics['expected_pieces_gross']);
            $this->assertNull($metrics['downtime_impact_pieces']);
        }

        $unified = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => $ct, 'active_cavities' => $cavities, 'running_hours' => $hours,
        ], ['nos_per_box' => $perBox]));

        $this->assertSame(bcadd((string) $v3Pieces, '0', 2), $unified['expected_pieces']);
        $this->assertSame($v3Boxes, $unified['expected_boxes']);
        $this->assertSame($v3Pieces, $unified['expected_pieces_gross'], 'no downtime: gross equals net');
        $this->assertSame(0, $unified['downtime_impact_pieces']);
    }

    /**
     * ExpectedOutputDivergenceTest's two edge cases, pinned on both sides
     * of the boundary: a cycle time longer than the run and zero running
     * hours. Legacy keeps its fractional / null answers exactly; v3 gives
     * the engine's known zero.
     */
    public function test_edge_cases_on_both_sides_of_the_boundary(): void
    {
        // CT longer than the whole run — no shot completes.
        $legacyLongCt = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_FLOOR,
            'standard_cycle_time' => '999999', 'active_cavities' => 5, 'running_hours' => '8',
        ], ['nos_per_box' => 810]));
        $this->assertSame('0.14', $legacyLongCt['expected_pieces'], 'legacy: 144000/999999 = 0.144… stays a fraction');
        $this->assertSame(0, $legacyLongCt['expected_boxes']);

        $unifiedLongCt = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => '999999', 'active_cavities' => 5, 'running_hours' => '8',
        ], ['nos_per_box' => 810]));
        $this->assertSame('0.00', $unifiedLongCt['expected_pieces'], 'v3: not one shot completes');
        $this->assertSame(0, $unifiedLongCt['expected_boxes']);
        $this->assertNull($unifiedLongCt['efficiency_pct'], 'a zero target has no ratio');

        // Zero running hours.
        $legacyZero = $this->metrics($this->completedEntry([
            'calculation_version' => null,
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '0',
        ], ['nos_per_box' => 810]));
        $this->assertNull($legacyZero['expected_pieces'], 'legacy: zero hours read as unknown');
        $this->assertNull($legacyZero['expected_boxes']);

        $unifiedZero = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '0',
        ], ['nos_per_box' => 810]));
        $this->assertSame('0.00', $unifiedZero['expected_pieces'], 'v3: zero hours is a KNOWN zero, not an unknown');
        $this->assertSame(0, $unifiedZero['expected_boxes']);
        $this->assertSame('0.00', $unifiedZero['net_running_hours']);
        $this->assertNull($unifiedZero['efficiency_pct']);

        // Missing inputs stay null in both — never a fabricated figure.
        foreach ([ProductionCalculationEngine::VERSION_UNIFIED, ProductionCalculationEngine::VERSION_FLOOR] as $stamp) {
            $noCt = $this->metrics($this->completedEntry([
                'calculation_version' => $stamp, 'active_cavities' => 5, 'running_hours' => '8', 'quantity_produced' => '5880',
            ], ['nos_per_box' => 840]));
            $this->assertNull($noCt['expected_pieces']);
            $this->assertNull($noCt['expected_boxes']);
            $this->assertNull($noCt['efficiency_pct']);
            $this->assertSame('5880', $noCt['actual_pieces']);
        }
    }

    // =================================================================
    // Downtime netting under v3 — the engine's targets(), wired
    // =================================================================

    public function test_v3_nets_completion_downtime_through_the_engine_targets_and_reports_the_impact(): void
    {
        // WB2 row 7 with 30 min of completion-recorded downtime: 8 h → 7.5 h
        // net; FLOOR(27000/12)=2250 × 5 = 11250 (gross 12000, so the
        // downtime cost 750 pieces). 10400 actual → 92.4 %, piece grain, 1dp.
        $metrics = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8',
            'quantity_produced' => '10400', 'no_of_box' => 10,
        ], ['nos_per_box' => 1040], downtimeMinutes: ['20.00', '10.00']));

        $this->assertSame('11250.00', $metrics['expected_pieces']);
        $this->assertSame(11, $metrics['expected_boxes']);
        $this->assertSame(12000, $metrics['expected_pieces_gross']);
        $this->assertSame(750, $metrics['downtime_impact_pieces']);
        $this->assertSame('30.00', $metrics['downtime_minutes_total']);
        $this->assertSame('7.50', $metrics['net_running_hours']);
        $this->assertSame(92.4, $metrics['efficiency_pct']);
        $this->assertTrue($metrics['downtime_netted']);

        // A cycle time that does not divide evenly: FLOOR(27000/10.6)=2547
        // × 5 = 12735 net against 13580 gross — floored on BOTH sides, so
        // the impact is a whole number of shots' worth (845).
        $uneven = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => '10.6', 'active_cavities' => 5, 'running_hours' => '8',
        ], ['nos_per_box' => 840], downtimeMinutes: ['30.00']));
        $this->assertSame('12735.00', $uneven['expected_pieces']);
        $this->assertSame(13580, $uneven['expected_pieces_gross']);
        $this->assertSame(845, $uneven['downtime_impact_pieces']);

        // The same inputs under the old stamp: the unfloored inline figure,
        // untouched — 135000/10.6 = 12735.849…
        $legacy = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_FLOOR,
            'standard_cycle_time' => '10.6', 'active_cavities' => 5, 'running_hours' => '8',
        ], ['nos_per_box' => 840], downtimeMinutes: ['30.00']));
        $this->assertSame('12735.85', $legacy['expected_pieces']);
        $this->assertSame('7.50', $legacy['net_running_hours']);
    }

    public function test_downtime_longer_than_the_run_floors_the_target_at_zero_never_invents_capacity(): void
    {
        $metrics = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '1',
        ], ['nos_per_box' => 1040], downtimeMinutes: ['90.00']));

        $this->assertSame('0.00', $metrics['expected_pieces']);
        $this->assertSame('0.00', $metrics['net_running_hours']);
        $this->assertSame(1500, $metrics['expected_pieces_gross']);
        $this->assertSame(1500, $metrics['downtime_impact_pieces']);
    }

    // =================================================================
    // Efficiency stays piece-grain under v3
    // =================================================================

    public function test_efficiency_is_piece_grain_under_v3_the_owners_batch_still_reads_over_standard(): void
    {
        // 3 boxes + 5,208 loose = 14,322 pieces against 13,330 expected
        // (floored) → 107.4 %, banded over_standard, never a gate — exactly
        // the reading CompletionDowntimeTest pins for the unfloored figure.
        $metrics = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => '10.8', 'active_cavities' => 5, 'running_hours' => '8',
            'quantity_produced' => '14322', 'no_of_box' => 3, 'loose_pieces' => 5208,
        ], ['nos_per_box' => 3038]));

        $this->assertSame('13330.00', $metrics['expected_pieces']);
        $this->assertSame(4, $metrics['expected_boxes']);
        $this->assertSame(3, $metrics['actual_boxes']);
        $this->assertSame(107.4, $metrics['efficiency_pct']);
        $this->assertSame('over_standard', $metrics['efficiency_band']);
        $this->assertFalse($metrics['blocks_approval']);

        // Exactly the standard is 'ok', a hair over is over_standard —
        // the band rule is version-independent.
        $exact = $this->metrics($this->completedEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8', 'quantity_produced' => '12000',
        ], ['nos_per_box' => 1040]));
        $this->assertSame(100.0, $exact['efficiency_pct']);
        $this->assertSame('ok', $exact['efficiency_band']);
    }

    // =================================================================
    // A real batch: stamped v3 at Start, metrics under each version
    // =================================================================

    public function test_a_batch_started_now_stamps_v3_and_its_completed_metrics_follow_the_stamp(): void
    {
        $this->actAs();
        [$shift, $machine, $item, $warehouse] = $this->factoryFloor(cycleTime: '10.6', nosPerBox: 840);
        $reason = DowntimeReason::create([
            'code' => 'DT-POWER', 'category' => 'Utilities', 'description' => 'Power outage',
            'planning_type' => 'unplanned', 'reduces_runtime' => true,
            'requires_note' => false, 'selectable_at_start' => true, 'is_active' => true,
        ]);

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-17',
        ], null);

        // New entries carry the unified stamp — the constant the engine
        // calls current IS the unified one.
        $this->assertSame('production_v3_unified', $entry->calculation_version);
        $this->assertSame(ProductionCalculationEngine::VERSION_UNIFIED, ProductionCalculationEngine::VERSION_CURRENT);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5880',
            'no_of_box' => 7,
            'nos_per_box' => 840,
            'running_hours' => '8',
            'downtime_events' => [['downtime_reason_id' => $reason->id, 'minutes' => 30]],
        ])->assertOk()
            // FLOOR(27000/10.6)=2547 × 5 = 12735; /840 = 15.16 → 15; 5880/12735 = 46.17 → 46.2.
            ->assertJsonPath('data.calculation_version', 'production_v3_unified')
            ->assertJsonPath('data.metrics.calculation_version', 'production_v3_unified')
            ->assertJsonPath('data.metrics.downtime_netted', true)
            ->assertJsonPath('data.metrics.expected_pieces', '12735.00')
            ->assertJsonPath('data.metrics.expected_pieces_gross', 13580)
            ->assertJsonPath('data.metrics.downtime_impact_pieces', 845)
            ->assertJsonPath('data.metrics.expected_boxes', 15)
            ->assertJsonPath('data.metrics.net_running_hours', '7.50')
            ->assertJsonPath('data.metrics.efficiency_pct', 46.2);

        // The SAME row as it would read had it been started before this
        // change (production_v2_floor) or before stamping existed (null):
        // the inline WB2 figures, byte-for-byte — 135000/10.6 = 12735.849…,
        // 5880/12735.849 = 46.169… → 46.2.
        $service = app(ShiftProductionEntryService::class);
        foreach ([ProductionCalculationEngine::VERSION_FLOOR, null] as $stamp) {
            ShiftProductionEntry::query()->whereKey($entry->id)->update(['calculation_version' => $stamp]);
            $metrics = $service->productionMetrics($entry->fresh());

            $this->assertSame('12735.85', $metrics['expected_pieces'], ($stamp ?? 'null').': never recomputed under v3');
            $this->assertSame(15, $metrics['expected_boxes']);
            $this->assertSame('7.50', $metrics['net_running_hours']);
            $this->assertSame(46.2, $metrics['efficiency_pct']);
            $this->assertSame($stamp, $metrics['calculation_version']);
            $this->assertNull($metrics['expected_pieces_gross']);
        }
    }

    // =================================================================
    // GUARD: every creation path stamps a version — none may leave the
    // column null, because null selects the legacy formula silently
    // =================================================================

    /**
     * EVERY door that creates a ShiftProductionEntry in app/ leaves
     * calculation_version non-null. Enumerated — grep `ShiftProductionEntry::create(`
     * (and `->create([` on the model) under app/:
     *
     *   1. ShiftProductionEntryService::startBatch()  — stamps VERSION_CURRENT
     *   2. ShiftProductionEntryService::handover()    — the child INHERITS the
     *      parent's stamp (a run keeps one formula across its segments;
     *      SegmentHandoverTest pins the equality), current only for a parent
     *      that has no stamp at all
     *   3. ShiftPageEntryService::recordRow()         — composes startBatch();
     *      no create of its own (the paper-page ingest therefore stamps the
     *      CURRENT engine for a shift that already ran — an owner question,
     *      Q46, not decided here)
     *
     * No factory or seeder builds this model, and a test's own `create()`
     * (a fixture, not a door) is exempt. Two halves: STATIC — every
     * `ShiftProductionEntry::create(` in app/ names 'calculation_version'
     * inside its attribute array, so a fourth door added later fails here
     * before it ships an unstamped row; RUNTIME — the three doors above,
     * driven, all yield a non-null stamp.
     */
    public function test_every_creation_path_in_app_stamps_a_calculation_version(): void
    {
        // ---- static half -------------------------------------------------
        $sites = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = $file->getContents();
            $offset = 0;
            while (($at = strpos($source, 'ShiftProductionEntry::create(', $offset)) !== false) {
                $open = $at + strlen('ShiftProductionEntry::create');
                $depth = 0;
                $close = null;
                for ($i = $open, $n = strlen($source); $i < $n; $i++) {
                    if ($source[$i] === '(') {
                        $depth++;
                    } elseif ($source[$i] === ')' && --$depth === 0) {
                        $close = $i;
                        break;
                    }
                }
                $this->assertNotNull($close, "unbalanced create( in {$file->getRelativePathname()}");
                $line = substr_count(substr($source, 0, $at), "\n") + 1;
                $sites["{$file->getRelativePathname()}:{$line}"] = str_contains(substr($source, $open, $close - $open), "'calculation_version'");
                $offset = $close;
            }
        }

        $this->assertSame([
            'Modules/Production/Services/ShiftProductionEntryService.php',
            'Modules/Production/Services/ShiftProductionEntryService.php',
        ], array_map(fn (string $site) => explode(':', $site)[0], array_keys($sites)), 'the enumerated doors — startBatch and handover, both in ShiftProductionEntryService; a new site must be added to the docblock and stamp too');
        foreach ($sites as $site => $stamps) {
            $this->assertTrue($stamps, "{$site} creates a ShiftProductionEntry without a calculation_version");
        }

        // ---- runtime half ------------------------------------------------
        $user = $this->actAs();
        [$shift, $machine, $item, $warehouse] = $this->factoryFloor(cycleTime: '10.6', nosPerBox: 840);
        $warehouse->forceFill(['tally_guid' => 'gd-fg'])->save();
        config(['production.traceability_enabled' => true]);
        $evening = Shift::create(['name' => 'Evening', 'start_time' => '14:00', 'end_time' => '22:00']);

        // 1. startBatch
        $service = app(ShiftProductionEntryService::class);
        $started = $service->startBatch([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'production_date' => '2026-08-17',
        ], $user->id);
        $this->assertSame(ProductionCalculationEngine::VERSION_CURRENT, $started->calculation_version);

        // 2. handover — the child
        $child = $service->handover($started, [
            'shift_id' => $evening->id,
            'completion' => ['quantity_produced' => '5880', 'running_hours' => '8'],
        ], $user->id);
        $this->assertNotNull($child->calculation_version);
        $this->assertSame($started->calculation_version, $child->calculation_version);
        $service->cancelTestBatch($child, $user->id, 'guard test — free the machine');

        // 3. the paper page (ShiftPageEntryService::recordRow → startBatch)
        $this->postJson('/api/v1/production/shift-production-entries/page', [
            'shift_id' => $shift->id,
            'production_date' => '2026-08-16',
            'rows' => [[
                'work_center_id' => $machine->id, 'item_id' => $item->id,
                'quantity_produced' => 10080, 'quantity_scrap' => 237, 'running_hours' => 8,
            ]],
        ])->assertOk()->assertJsonPath('data.failed', []);

        $this->assertSame(0, ShiftProductionEntry::query()->whereNull('calculation_version')->count(), 'no door left a row unstamped');
        $this->assertGreaterThanOrEqual(3, ShiftProductionEntry::query()->whereNotNull('calculation_version')->count());
    }

    // =================================================================
    // The preview endpoint says which formula and that downtime is not netted
    // =================================================================

    public function test_the_preview_response_carries_the_calculation_version_and_downtime_netted_false(): void
    {
        $this->actAs();
        [$shift, $machine, $item, $warehouse] = $this->factoryFloor(cycleTime: '12.2', nosPerBox: 840);

        $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
            'item_id' => $item->id,
            'work_center_id' => $machine->id,
            'warehouse_id' => $warehouse->id,
            'shift_id' => $shift->id,
        ]))->assertOk()
            ->assertJsonPath('data.estimation.calculation_version', 'production_v3_unified')
            ->assertJsonPath('data.estimation.downtime_netted', false)
            // The factory's Daily Production row through the endpoint:
            // FLOOR(28800/12.2)=2360 × 5 = 11800 → 14 boxes.
            ->assertJsonPath('data.estimation.expected_pieces', 11800)
            ->assertJsonPath('data.estimation.expected_boxes', 14);
    }

    // =================================================================
    // Engine: v3 floors exactly as v2 did — the version is about WHERE the
    // formula applies, not a third arithmetic
    // =================================================================

    public function test_v3_target_pieces_floor_exactly_as_v2_and_only_legacy_v1_is_unfloored(): void
    {
        $engine = new ProductionCalculationEngine;

        $this->assertSame(13580, $engine->targetPieces('8', '10.6', 5, ProductionCalculationEngine::VERSION_UNIFIED));
        $this->assertSame(13580, $engine->targetPieces('8', '10.6', 5, ProductionCalculationEngine::VERSION_FLOOR));
        $this->assertSame(13585, $engine->targetPieces('8', '10.6', 5, ProductionCalculationEngine::VERSION_LEGACY));
        // The default IS the unified version.
        $this->assertSame(13580, $engine->targetPieces('8', '10.6', 5));
        $this->assertSame('production_v3_unified', ProductionCalculationEngine::VERSION_CURRENT);
    }

    // =================================================================
    // helpers
    // =================================================================

    /**
     * In-memory completed entry with its relations set — productionMetrics()
     * is pure computation, so no DB row is needed. Downtime minutes become
     * completion-recorded, runtime-reducing events (no reason row → counts,
     * the fail-safe the reader documents).
     *
     * @param  list<string>  $downtimeMinutes
     */
    private function completedEntry(array $attributes, array $itemAttributes = [], array $downtimeMinutes = []): ShiftProductionEntry
    {
        $entry = new ShiftProductionEntry($attributes + ['batch_status' => BatchStatus::Completed]);
        $entry->setRelation('item', new Item($itemAttributes + ['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']));
        $entry->setRelation('materialConsumptions', collect());
        $entry->setRelation('scraps', collect());
        $entry->setRelation('downtimeEvents', collect($downtimeMinutes)->map(function (string $minutes) {
            $event = new ProductionDowntimeEvent(['minutes' => $minutes, 'known_before_start' => false]);
            $event->setRelation('reason', null);

            return $event;
        }));

        return $entry;
    }

    private function metrics(ShiftProductionEntry $entry): array
    {
        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);
        $this->assertNotNull($metrics);

        return $metrics;
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'production.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{0: Shift, 1: WorkCenter, 2: Item, 3: Warehouse} */
    private function factoryFloor(string $cycleTime, int $nosPerBox): array
    {
        return [
            Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']),
            WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]),
            Item::create([
                'sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS', 'is_active' => true,
                'standard_cycle_time' => $cycleTime, 'standard_cavities' => 5, 'nos_per_box' => $nosPerBox,
            ]),
            Warehouse::create(['code' => 'WH-1', 'name' => 'FG Store', 'is_active' => true]),
        ];
    }
}
