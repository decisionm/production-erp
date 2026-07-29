<?php

namespace Tests\Feature;

use App\Modules\Production\Services\ProductionCalculationEngine;
use Tests\TestCase;

/**
 * The factory master workbook's "Calculation Example" sheet, asserted
 * cell-for-cell. It is the acceptance fixture for the whole configurable
 * production release — if this drifts, the app and the factory's own sheet
 * have stopped agreeing, which is the exact failure the workbook exists to
 * prevent.
 *
 * Fixture: 8 h scheduled, 1 h planned downtime, 1 h unplanned, CT 12 s,
 * 5 cavities, 12.9 g unit weight, 810 pcs/box, 11 boxes actual, 64 pcs
 * rejected, 1 kg lumps, 115 kg resin, 1.7646 kg masterbatch.
 */
class ProductionCalculationEngineTest extends TestCase
{
    private ProductionCalculationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ProductionCalculationEngine;
    }

    public function test_workbook_fixture_targets_and_downtime_impacts(): void
    {
        $targets = $this->engine->targets(
            scheduledHours: '8',
            plannedDowntimeHours: '1',
            unplannedDowntimeHours: '1',
            cycleTime: '12',
            cavities: 5,
        );

        $this->assertSame(12000, $targets['full_target']);
        $this->assertSame(10500, $targets['adjusted_target']);
        $this->assertSame(9000, $targets['runtime_target']);
        $this->assertSame(1500, $targets['planned_downtime_impact']);
        $this->assertSame(1500, $targets['unplanned_downtime_impact']);
        $this->assertSame('6.000000', $targets['net_runtime_hours']);
    }

    public function test_workbook_fixture_actual_pieces_weights_and_reconciliation(): void
    {
        // 11 boxes × 810 = 8,910 good pieces.
        $actual = $this->engine->actualToPieces('boxes', 11, nosPerBox: 810, nosPerTray: 162, nosPerPouch: null);
        $this->assertSame(8910, $actual['pieces']);

        $goodKg = $this->engine->piecesToKg(8910, '12.9');
        $rejectionKg = $this->engine->piecesToKg(64, '12.9');

        $this->assertSame('114.9390', $goodKg);
        $this->assertSame('0.8256', $rejectionKg);

        $recon = $this->engine->materialReconciliation(
            goodKg: $goodKg,
            rejectionKg: $rejectionKg,
            lumpsKg: '1',
            resinKg: '115',
            masterbatchKg: '1.7646',
        );

        $this->assertSame('116.7646', $recon['accounted_output_kg']);
        $this->assertSame('116.7646', $recon['total_input_kg']);
        // The workbook's float arithmetic lands on 1.42e-14; exact decimal
        // arithmetic gives the true zero the sheet is approximating.
        $this->assertSame('0.0000', $recon['variance_kg']);
    }

    public function test_workbook_fixture_efficiencies(): void
    {
        $eff = $this->engine->efficiencies(
            goodPieces: 8910,
            fullTarget: 12000,
            adjustedTarget: 10500,
            runtimeTarget: 9000,
        );

        $this->assertSame(74.25, $eff['shift_attainment_pct']);
        $this->assertSame(84.86, $eff['adjusted_efficiency_pct']);
        $this->assertSame(99.0, $eff['running_efficiency_pct']);
    }

    public function test_the_two_calculation_versions_genuinely_differ(): void
    {
        // CT 10.6 / 5 cavities / 8 h — the WB2 row that pins the legacy
        // formula. Unfloored it is 13,585; floored, 13,580. Both are
        // "correct" for their own contract, which is exactly why an entry
        // must record which one produced its figures.
        $legacy = $this->engine->targetPieces('8', '10.6', 5, ProductionCalculationEngine::VERSION_LEGACY);
        $current = $this->engine->targetPieces('8', '10.6', 5, ProductionCalculationEngine::VERSION_CURRENT);

        $this->assertSame(13585, $legacy);
        $this->assertSame(13580, $current);
        $this->assertNotSame($legacy, $current);
    }

    public function test_downtime_longer_than_the_shift_cannot_invent_capacity(): void
    {
        // A machine down for more than the shift produced nothing; the
        // runtime target must be 0, never negative and never null-as-if-
        // unknown.
        $targets = $this->engine->targets('8', '6', '5', '12', 5);

        $this->assertSame(12000, $targets['full_target']);
        $this->assertSame(3000, $targets['adjusted_target']);
        $this->assertSame(0, $targets['runtime_target']);
        $this->assertSame('0', $targets['net_runtime_hours']);
    }

    public function test_missing_inputs_yield_null_never_a_fabricated_zero(): void
    {
        $this->assertNull($this->engine->targetPieces('8', null, 5));
        $this->assertNull($this->engine->targetPieces('8', '12', null));
        $this->assertNull($this->engine->targetPieces(null, '12', 5));
        // Zero cavities is not "no cavities configured" — it is invalid.
        $this->assertNull($this->engine->targetPieces('8', '12', 0));
        $this->assertNull($this->engine->piecesToKg(100, null));
        $this->assertNull($this->engine->efficiencies(100, 0, null, null)['shift_attainment_pct']);
    }

    public function test_actual_entry_converts_from_every_supported_basis(): void
    {
        $this->assertSame(8910, $this->engine->actualToPieces('boxes', 11, 810, 162, null)['pieces']);
        $this->assertSame(1782, $this->engine->actualToPieces('trays', 11, 810, 162, null)['pieces']);
        $this->assertSame(550, $this->engine->actualToPieces('pouches', 11, 810, 162, 50)['pieces']);
        $this->assertSame(8910, $this->engine->actualToPieces('pieces', 8910, 810, 162, null)['pieces']);

        // Loose pieces ride along with a container basis — a part-filled
        // last box is counted, not discarded.
        $this->assertSame(8960, $this->engine->actualToPieces('boxes', 11, 810, 162, null, loosePieces: 50)['pieces']);
    }

    public function test_a_basis_with_no_packing_standard_returns_null_pieces(): void
    {
        // Pouches with no pouch standard: report that it cannot be
        // converted rather than silently treating the count as pieces.
        $result = $this->engine->actualToPieces('pouches', 11, 810, 162, null);

        $this->assertNull($result['pieces']);
        $this->assertSame('pouches', $result['basis']);
    }
}
