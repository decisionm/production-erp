<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\BatchEstimationService;
use App\Modules\Production\Services\ProductionCalculationEngine;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADVERSARIAL FINDING (QA attack #5 — EXPECTED OUTPUT, boundary cases):
 *
 * Two different computations answer "what output was expected?" for the
 * SAME batch, at two different points in its life, and they use two
 * different roundings by design:
 *
 *   BatchEstimationService::estimate() (Start Batch preview)
 *     -> ProductionCalculationEngine::targetPieces(): FLOORS the cycle
 *        count first ("a machine cannot complete a fractional shot").
 *
 *   ShiftProductionEntryService::productionMetrics() (post-completion /
 *   the approval screen)
 *     -> inlines the WB2 workbook formula 3600×cavities×hours/CT with NO
 *        flooring, by design, "so it reconciles cell-for-cell with
 *        Vincent's sheet".
 *
 * BatchEstimationService's own docblock names the gap and calls it safe:
 * "The two therefore differ by at most one shot's worth of pieces, which
 * is intentional and documented rather than a discrepancy to chase."
 *
 * That claim is true at the PIECES level and misleading at the BOXES
 * level. Both figures are immediately rounded to a box count for display
 * (ROUND for productionMetrics, ROUND again inside
 * ProductionCalculationEngine::expectedBoxes for the estimate), and a
 * "less than one shot's worth of pieces" gap can straddle a box rounding
 * boundary, producing a full BOX of disagreement between the Start Batch
 * screen and the Approval screen for the exact same CT / cavities / hours
 * / pack-size inputs — nothing about the run changed in between.
 *
 * A supervisor who wrote down "5 boxes expected" off the Start Batch
 * screen and an accountant reading "6 boxes expected" off the Approval
 * screen for the identical run have grounds to distrust the app, and no
 * code path told either of them why the two disagree.
 *
 * RESOLUTION (Phase 5.5, P5.5-03): production_v3_unified. Every entry
 * started after the change is stamped v3 and its metrics read the SAME
 * engine call the preview does (UnifiedEntryMetrics) — the v3 arms below
 * show the two screens agreeing for the identical run. The legacy arms
 * are KEPT, unchanged: the fixtures here carry no stamp (as every entry
 * approved before the change does), and those read the inline WB2 figure
 * forever (LegacyEntryMetrics). This file therefore now pins BOTH the
 * defect as history and its cure as the present.
 */
class ExpectedOutputDivergenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * CT 100.5s, 8 cavities, 1 hour, 52 pcs/box — chosen so the floored
     * cycle count (35) and the raw/unfloored one (35.8209...) land on
     * opposite sides of a box-rounding boundary once divided by 52:
     *   floored:   35 x 8 = 280 pieces   -> 280/52   = 5.3846  -> ROUND 5
     *   unfloored: 3600x8/100.5 = 286.5671... pieces -> /52 = 5.5109 -> ROUND 6
     */
    public function test_start_batch_estimate_and_the_approval_screen_disagree_on_boxes_for_the_same_run(): void
    {
        $item = Item::create([
            'sku' => 'BOX-DIVERGENCE', 'name' => 'Divergence fixture', 'uom' => 'Nos.',
            'nos_per_box' => 52, 'standard_cycle_time' => '100.5', 'standard_cavities' => 8,
        ]);

        // ---- Start Batch preview: floored engine path. -------------------
        $estimate = app(BatchEstimationService::class)->estimate(
            item: $item,
            shift: null,
            plannedHours: '1',
            activeCavities: 8,
        );
        $this->assertSame(280, $estimate['expected_pieces'], 'Sanity: floor(3600/100.5) x 8 = 35 x 8 = 280.');
        $this->assertSame(5, $estimate['expected_boxes'], 'Start Batch screen would show 5 boxes expected.');

        // ---- Approval screen: unfloored WB2 formula, same inputs. --------
        $entry = new ShiftProductionEntry([
            'standard_cycle_time' => '100.5',
            'active_cavities' => 8,
            'running_hours' => '1',
            'batch_status' => BatchStatus::Completed,
        ]);
        $entry->setRelation('item', $item);
        $entry->setRelation('materialConsumptions', collect());
        $entry->setRelation('scraps', collect());

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);
        $this->assertSame('286.57', $metrics['expected_pieces'], 'Sanity: unfloored 3600x8x1/100.5 = 286.5671...');

        // *** THE DIVERGENCE ***
        // Same CT, same cavities, same hours, same pack size — the
        // Approval screen's target is a WHOLE BOX higher than the number
        // the supervisor saw at Start Batch, purely from which of the two
        // rounding regimes happened to run.
        $this->assertSame(6, $metrics['expected_boxes'], 'Approval screen shows 6 boxes expected for the identical run.');
        $this->assertNotSame(
            $estimate['expected_boxes'],
            $metrics['expected_boxes'],
            'Start Batch (5) and Approval (6) disagree on the target box count for one unchanged run.'
        );

        // ---- v3 arm: the SAME run stamped production_v3_unified. ---------
        // The metrics read the engine — the identical targetPieces() the
        // preview called — so Start Batch and Approval now say one number.
        $unified = new ShiftProductionEntry([
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            'standard_cycle_time' => '100.5',
            'active_cavities' => 8,
            'running_hours' => '1',
            'batch_status' => BatchStatus::Completed,
        ]);
        $unified->setRelation('item', $item);
        $unified->setRelation('materialConsumptions', collect());
        $unified->setRelation('scraps', collect());

        $unifiedMetrics = app(ShiftProductionEntryService::class)->productionMetrics($unified);
        $this->assertSame('280.00', $unifiedMetrics['expected_pieces']);
        $this->assertSame($estimate['expected_pieces'], (int) $unifiedMetrics['expected_pieces']);
        $this->assertSame($estimate['expected_boxes'], $unifiedMetrics['expected_boxes'], 'v3: 5 boxes on both screens.');
        $this->assertSame($estimate['calculation_version'], $unifiedMetrics['calculation_version']);
    }

    /**
     * A second symptom of the same unfloored/floored split: when the cycle
     * time is longer than the shift itself, the floored engine correctly
     * reports 0 expected pieces (no cycle can complete). The unfloored WB2
     * path used by productionMetrics() instead reports a small POSITIVE
     * fractional expectation — physically impossible (a partial shot
     * yields nothing) though it happens to still round to 0 boxes here.
     */
    public function test_a_cycle_time_longer_than_the_shift_yields_zero_pieces_in_the_engine_but_a_positive_fraction_in_the_approval_metric(): void
    {
        $engine = app(ProductionCalculationEngine::class);

        // 8h shift, CT far longer than the shift (999999s) — no cycle can
        // ever complete.
        $flooredPieces = $engine->targetPieces('8', '999999', 5);
        $this->assertSame(0, $flooredPieces, 'Physically correct: not one shot completes.');

        $entry = new ShiftProductionEntry([
            'standard_cycle_time' => '999999',
            'active_cavities' => 5,
            'running_hours' => '8',
            'batch_status' => BatchStatus::Completed,
        ]);
        $item = new Item(['nos_per_box' => 810]);
        $entry->setRelation('item', $item);
        $entry->setRelation('materialConsumptions', collect());
        $entry->setRelation('scraps', collect());

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);

        // *** THE DEFECT ***
        // productionMetrics() reports a nonzero expectation (a fraction of
        // a single piece) for a run that physically could not complete
        // even one shot — the unfloored WB2 formula has no floor to catch
        // this boundary the way the engine's own targetPieces() does.
        $this->assertNotSame('0.00', $metrics['expected_pieces']);
        $this->assertGreaterThan(0.0, (float) $metrics['expected_pieces']);

        // ---- v3 arm: stamped production_v3_unified, the same run reads
        // the engine's honest zero — not one shot completes.
        $entry->calculation_version = ProductionCalculationEngine::VERSION_UNIFIED;
        $this->assertSame('0.00', app(ShiftProductionEntryService::class)->productionMetrics($entry)['expected_pieces']);
    }

    /**
     * Zero running_hours: ProductionCalculationEngine::targetPieces()
     * explicitly documents that zero hours is "a KNOWN zero, not an
     * unknown" and returns 0 (never null) so a machine down for a whole
     * shift is not silently dropped from efficiency reporting.
     * productionMetrics() does the opposite for the identical concept:
     * bccomp($hours, '0', 4) === 1 requires hours STRICTLY greater than
     * zero, so running_hours = '0' yields expected_pieces = null — exactly
     * the "we could not work it out" reading the engine's own docblock
     * says must be avoided.
     *
     * Note: CompleteBatchRequest currently validates running_hours with
     * gt:0, so a literal 0 cannot be submitted through the /complete
     * endpoint today — this is a latent inconsistency in the shared
     * service method (reachable by direct calls, imports, or if that
     * validation rule is ever relaxed) rather than one exploitable through
     * the current HTTP surface. Recorded as minor/latent for that reason.
     */
    public function test_zero_running_hours_is_a_known_zero_in_the_engine_but_an_unknown_null_in_the_approval_metric(): void
    {
        $engine = app(ProductionCalculationEngine::class);

        $this->assertSame(0, $engine->targetPieces('0', '12', 5), 'Engine: zero hours is a known zero target.');

        $entry = new ShiftProductionEntry([
            'standard_cycle_time' => '12',
            'active_cavities' => 5,
            'running_hours' => '0',
            'batch_status' => BatchStatus::Completed,
        ]);
        $item = new Item(['nos_per_box' => 810]);
        $entry->setRelation('item', $item);
        $entry->setRelation('materialConsumptions', collect());
        $entry->setRelation('scraps', collect());

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);

        // *** THE INCONSISTENCY ***
        $this->assertNull(
            $metrics['expected_pieces'],
            'productionMetrics() treats zero hours as unknown, contradicting the engine it otherwise mirrors.'
        );

        // ---- v3 arm: stamped production_v3_unified, zero hours is the
        // engine's KNOWN zero on the Approval side too.
        $entry->calculation_version = ProductionCalculationEngine::VERSION_UNIFIED;
        $this->assertSame('0.00', app(ShiftProductionEntryService::class)->productionMetrics($entry)['expected_pieces']);
    }
}
