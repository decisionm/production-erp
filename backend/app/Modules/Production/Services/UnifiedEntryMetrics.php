<?php

namespace App\Modules\Production\Services;

/**
 * production_v3_unified — the completion metrics through the SAME engine
 * call as the Start Batch preview (P5.5-03).
 *
 * The preview asks ProductionCalculationEngine::targetPieces() for planned
 * hours; this asks the engine's targets() for the running hours net of the
 * completion-recorded downtime, and reads runtime_target — the identical
 * targetPieces() with the identical flooring (cycles floor before cavities
 * multiply, because a machine cannot complete a partial shot). With no
 * downtime the two screens therefore agree to the piece and to the box.
 *
 * What targets() adds over the bare call is the pair the Results card can
 * finally print: expected_pieces_gross (the target before downtime) and
 * downtime_impact_pieces (what the recorded stoppages cost, in whole shots'
 * worth). Both are the engine's own figures, not a second arithmetic.
 *
 * Presentation: expected_pieces stays a 2dp string ('11250.00') so the
 * resource shape is one shape across versions; efficiency stays piece-grain
 * at 1dp, from the engine's efficiencies(); net_running_hours 2dp half-up.
 * Boxes go through expectedBoxes() (the workbook's nearest-rounding EST BOX
 * policy) and pouches through packingContainers() — the same two calls the
 * preview makes.
 */
class UnifiedEntryMetrics implements EntryExpectedOutput
{
    public function __construct(private readonly ProductionCalculationEngine $engine) {}

    public function compute(
        ?string $cycleTime,
        ?int $cavities,
        ?string $hours,
        string $downtimeMinutes,
        PackQuantities $pack,
        ?string $actualPieces,
    ): array {
        // Only completion-recorded events net here (known_before_start =
        // false, reduces_runtime reasons — the caller already filtered):
        // planned downtime attached at Start shaped that screen's adjusted
        // target, and netting it here too would double-count it. So it goes
        // in as UNPLANNED against a scheduled span of the running hours the
        // supervisor typed, with nothing planned — that is exactly the
        // "runtime" leg of the engine's three targets.
        $downtimeHours = bccomp($downtimeMinutes, '0', 2) === 1
            ? bcdiv($downtimeMinutes, '60', 6)
            : null;

        $targets = $this->engine->targets(
            scheduledHours: $hours,
            plannedDowntimeHours: null,
            unplannedDowntimeHours: $downtimeHours,
            cycleTime: $cycleTime,
            cavities: $cavities,
            version: ProductionCalculationEngine::VERSION_UNIFIED,
        );

        $expectedPieces = $targets['runtime_target'];

        $efficiency = $this->engine->efficiencies(
            goodPieces: $actualPieces,
            fullTarget: $targets['full_target'],
            adjustedTarget: $targets['adjusted_target'],
            runtimeTarget: $expectedPieces,
            decimals: 1,
        )['running_efficiency_pct'];

        $netHours = $targets['net_runtime_hours'];

        return [
            'expected_pieces' => $expectedPieces !== null ? bcadd((string) $expectedPieces, '0', 2) : null,
            'expected_pieces_gross' => $targets['full_target'],
            'downtime_impact_pieces' => $targets['unplanned_downtime_impact'],
            'expected_boxes' => $this->engine->expectedBoxes($expectedPieces, $pack->nos_per_box),
            'expected_pouches' => $this->engine->packingContainers($expectedPieces, $pack->nos_per_pouch),
            // +0.005 then truncate at 2dp IS round-half-up — bcmath has no
            // rounding of its own (the same trick masterbatchKg uses).
            'net_running_hours' => $netHours !== null ? bcadd($netHours, '0.005', 2) : null,
            'efficiency_pct' => $efficiency,
        ];
    }
}
