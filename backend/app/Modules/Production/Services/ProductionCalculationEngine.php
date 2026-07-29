<?php

namespace App\Modules\Production\Services;

/**
 * The one authoritative production calculation engine.
 *
 * Versioned deliberately. Two formula sets exist in this factory's history
 * and they disagree, so which one produced a number is part of the number's
 * meaning:
 *
 *   legacy_v1          — 3600 × cavities × hours ÷ CT, NOT floored, with
 *                        half-up ROUND for boxes. Pinned cell-for-cell to
 *                        the older WB2 workbook the accountant reconciles
 *                        against. HISTORICAL ONLY: entries approved under
 *                        it keep their figures forever.
 *   production_v2_floor — FLOOR(hours × 3600 ÷ CT) × cavities, per the
 *                        29-Jul factory master workbook. Authoritative for
 *                        every new configurable-production batch. A machine
 *                        cannot complete a partial shot, so the cycle count
 *                        floors before cavities multiply.
 *
 * An entry stamps its version at Start and is never recalculated by a later
 * engine change. That is the whole point of versioning rather than
 * migrating: an approved figure that silently moves destroys the factory's
 * trust in the parallel paper run far faster than a wrong figure they can
 * see and query.
 *
 * Precision: bcmath throughout, 8dp internally, rounded only at the
 * presentation boundaries named in each method. Percentages are returned as
 * plain floats at 4dp — they are display values, never accounting inputs.
 */
class ProductionCalculationEngine
{
    public const VERSION_LEGACY = 'legacy_v1';

    public const VERSION_CURRENT = 'production_v2_floor';

    /**
     * Target pieces for a span of running time.
     *
     * @param  string  $hours  decimal hours (already net of whatever downtime the caller is excluding)
     */
    public function targetPieces(?string $hours, ?string $cycleTime, ?int $cavities, string $version = self::VERSION_CURRENT): ?int
    {
        if ($hours === null || $cycleTime === null || $cavities === null || $cavities <= 0) {
            return null;
        }
        if (bccomp($cycleTime, '0', 4) !== 1 || bccomp($hours, '0', 6) === -1) {
            return null;
        }

        // Zero hours is a KNOWN zero, not an unknown. A machine down for the
        // whole shift has a runtime target of 0 pieces — reporting null
        // there would read as "we could not work it out" and would silently
        // drop the machine out of running-efficiency reporting.
        if (bccomp($hours, '0', 6) === 0) {
            return 0;
        }

        $seconds = bcmul($hours, '3600', 6);

        if ($version === self::VERSION_LEGACY) {
            // Unfloored: the WB2 formula, kept so historical entries can be
            // re-rendered exactly as they were approved.
            return (int) round((float) bcdiv(bcmul($seconds, (string) $cavities, 6), $cycleTime, 6));
        }

        // A partial shot produces nothing — floor the cycles, then multiply.
        $cycles = (int) bcdiv($seconds, $cycleTime, 0);

        return $cycles * $cavities;
    }

    /**
     * The three targets that make downtime legible, plus their impacts.
     *
     * full     — capacity of the scheduled shift, before any downtime
     * adjusted — after downtime that was KNOWN before the shift started
     * runtime  — after all downtime; what the machine could actually have made
     *
     * The two impact figures are the point of the exercise: they separate
     * "we planned to lose this" from "we lost this unexpectedly", so a
     * supervisor is not judged on a scheduled mould change.
     *
     * @return array{
     *     full_target: ?int, adjusted_target: ?int, runtime_target: ?int,
     *     net_runtime_hours: ?string, planned_downtime_impact: ?int,
     *     unplanned_downtime_impact: ?int,
     * }
     */
    public function targets(
        ?string $scheduledHours,
        ?string $plannedDowntimeHours,
        ?string $unplannedDowntimeHours,
        ?string $cycleTime,
        ?int $cavities,
        string $version = self::VERSION_CURRENT,
    ): array {
        $planned = $plannedDowntimeHours ?? '0';
        $unplanned = $unplannedDowntimeHours ?? '0';

        $afterPlanned = $scheduledHours !== null ? $this->floorAtZero(bcsub($scheduledHours, $planned, 6)) : null;
        $netRuntime = $afterPlanned !== null ? $this->floorAtZero(bcsub($afterPlanned, $unplanned, 6)) : null;

        $full = $this->targetPieces($scheduledHours, $cycleTime, $cavities, $version);
        $adjusted = $this->targetPieces($afterPlanned, $cycleTime, $cavities, $version);
        $runtime = $this->targetPieces($netRuntime, $cycleTime, $cavities, $version);

        return [
            'full_target' => $full,
            'adjusted_target' => $adjusted,
            'runtime_target' => $runtime,
            'net_runtime_hours' => $netRuntime,
            'planned_downtime_impact' => $full !== null && $adjusted !== null ? $full - $adjusted : null,
            'unplanned_downtime_impact' => $adjusted !== null && $runtime !== null ? $adjusted - $runtime : null,
        ];
    }

    /**
     * Estimated boxes for a target — the factory's EST BOX column.
     *
     * Deliberately a SEPARATE rounding stage from targetPieces(). The two
     * roundings answer different questions and must not be merged:
     *
     *   FLOOR on cycles  — physics. A machine cannot complete a fractional
     *                      shot, so a partial cycle yields no pieces.
     *   ROUND on boxes   — the factory's own reporting convention. EST BOX
     *                      is a target to compare actual boxes against, and
     *                      the workbook rounds it to nearest.
     *
     * Flooring here as well would quietly lower every target by up to a box
     * and inflate efficiency; ceiling would do the reverse. Neither matches
     * the sheet the factory reconciles against, so nearest is the default
     * and the policy is configurable (production.est_box_rounding) in case
     * Vincent rules that only completely filled boxes count.
     *
     * Worked check (factory row): CT 12.2s, 5 cavities, 8h, 840/box —
     * FLOOR(28800/12.2)=2360 cycles × 5 = 11,800 pieces; 11,800/840 =
     * 14.0476 → 14 boxes, matching the workbook's
     * ROUND((3600/12.2)×5×8÷840, 0) = 14.
     */
    public function expectedBoxes(?int $pieces, ?int $piecesPerBox, ?string $policy = null): ?int
    {
        if ($pieces === null || $piecesPerBox === null || $piecesPerBox <= 0) {
            return null;
        }

        $exact = bcdiv((string) $pieces, (string) $piecesPerBox, 8);

        return (int) match ($policy ?? config('production.est_box_rounding', 'round')) {
            'floor' => bcdiv($exact, '1', 0),
            'ceil' => bccomp($exact, bcdiv($exact, '1', 0), 8) === 1
                ? bcadd(bcdiv($exact, '1', 0), '1', 0)
                : bcdiv($exact, '1', 0),
            default => $this->roundHalfUp($exact),
        };
    }

    /**
     * Production efficiency the way the factory reads it: actual boxes over
     * the DISPLAYED estimated boxes.
     *
     * The denominator is the rounded, displayed figure, not the exact
     * fraction — otherwise the percentage on screen would not reconcile
     * with the two box numbers printed beside it, which is precisely the
     * arithmetic a supervisor checks by hand.
     *
     * Factory row: 10 actual / 14 estimated = 71.43%.
     */
    public function boxEfficiency(?int $actualBoxes, ?int $displayedEstimatedBoxes): ?float
    {
        return $this->pct($actualBoxes, $displayedEstimatedBoxes);
    }

    /**
     * Total rejection, per the factory workbook: QC rejection kg + lumps.
     *
     * Two policy points, BOTH pending Vincent, both configurable rather
     * than guessed:
     *
     *  - Precedence: the workbook uses the QC-weighed rejection, not the
     *    piece-count-derived production figure, when both exist. QC put it
     *    on a scale; production multiplied a count by a nominal weight.
     *  - The workbook does NOT include its separate QC-lumps column in this
     *    sum. That looks like an omission but may be deliberate, so this
     *    mirrors the sheet and flags it rather than silently "fixing" it.
     *
     * @return array{total_rejection_kg: ?string, rejection_source: string, qc_difference_kg: ?string}
     */
    public function totalRejection(
        ?string $productionRejectionKg,
        ?string $qcRejectionKg,
        ?string $lumpsKg,
        ?string $precedence = null,
    ): array {
        $precedence ??= (string) config('production.rejection_precedence', 'qc');

        $chosen = $precedence === 'production'
            ? ($productionRejectionKg ?? $qcRejectionKg)
            : ($qcRejectionKg ?? $productionRejectionKg);

        $source = $chosen === null
            ? 'none'
            : (($precedence === 'production' && $productionRejectionKg !== null) ? 'production'
                : (($precedence === 'qc' && $qcRejectionKg !== null) ? 'qc'
                    : ($qcRejectionKg !== null ? 'qc' : 'production')));

        // The QC-vs-production gap: what the scale said minus what the
        // piece count implied. A persistent gap means the nominal weight
        // is wrong, which is worth seeing rather than averaging away.
        $difference = ($productionRejectionKg !== null && $qcRejectionKg !== null)
            ? bcsub($productionRejectionKg, $qcRejectionKg, 4)
            : null;

        return [
            'total_rejection_kg' => $chosen !== null ? bcadd($chosen, $lumpsKg ?? '0', 4) : null,
            'rejection_source' => $source,
            'qc_difference_kg' => $difference,
        ];
    }

    /**
     * ACCOUNTED raw-material consumption: good production kg + total
     * rejection kg.
     *
     * This is NOT measured consumption and must never be presented as it.
     * It is what the output implies was consumed, derived from piece counts
     * and a nominal weight. Actual resin, masterbatch and other input are
     * captured separately and reconciled against this figure — the gap
     * between them is the whole point of the reconciliation, and collapsing
     * the two would erase it.
     */
    public function accountedConsumptionKg(?string $goodProductionKg, ?string $totalRejectionKg): ?string
    {
        if ($goodProductionKg === null) {
            return null;
        }

        return bcadd($goodProductionKg, $totalRejectionKg ?? '0', 4);
    }

    private function roundHalfUp(string $value): string
    {
        $truncated = bcdiv($value, '1', 0);
        $fraction = bcsub($value, $truncated, 8);

        return bccomp($fraction, '0.5', 8) >= 0 ? bcadd($truncated, '1', 0) : $truncated;
    }

    /**
     * Pieces → kg at the snapshotted unit weight. Four decimals: the
     * reconciliation is in kg and the factory's own sheet carries 4dp
     * (1.7646 kg of masterbatch).
     */
    public function piecesToKg(?int $pieces, ?string $unitWeightGrams): ?string
    {
        if ($pieces === null || $unitWeightGrams === null || bccomp($unitWeightGrams, '0', 4) !== 1) {
            return null;
        }

        return bcdiv(bcmul((string) $pieces, $unitWeightGrams, 8), '1000', 4);
    }

    /**
     * Material reconciliation.
     *
     * variance = (resin + masterbatch + other polymer) − (good + rejection + lumps)
     *
     * Polymer inputs stay separate on the way in — the factory tracks resin
     * and masterbatch as different materials with different costs — and are
     * only summed here, at the point the question is "did the mass balance".
     *
     * @return array{accounted_output_kg: ?string, total_input_kg: ?string, variance_kg: ?string}
     */
    public function materialReconciliation(
        ?string $goodKg,
        ?string $rejectionKg,
        ?string $lumpsKg,
        ?string $resinKg,
        ?string $masterbatchKg,
        ?string $otherPolymerKg = null,
    ): array {
        $accounted = null;
        if ($goodKg !== null) {
            $accounted = bcadd(bcadd($goodKg, $rejectionKg ?? '0', 4), $lumpsKg ?? '0', 4);
        }

        $input = null;
        if ($resinKg !== null || $masterbatchKg !== null || $otherPolymerKg !== null) {
            $input = bcadd(bcadd($resinKg ?? '0', $masterbatchKg ?? '0', 4), $otherPolymerKg ?? '0', 4);
        }

        return [
            'accounted_output_kg' => $accounted,
            'total_input_kg' => $input,
            'variance_kg' => $accounted !== null && $input !== null ? bcsub($input, $accounted, 4) : null,
        ];
    }

    /**
     * The three efficiency views. Each answers a different question and the
     * factory needs all three:
     *
     *   attainment — did we make the shift's worth? (includes every loss)
     *   adjusted   — did we make what we planned after known downtime?
     *   running    — how well did the machine run while it was running?
     *
     * Returned as percentages at 2dp for display. Null when the target is
     * null or zero — never a fabricated 0% or 100%.
     *
     * @return array{shift_attainment_pct: ?float, adjusted_efficiency_pct: ?float, running_efficiency_pct: ?float}
     */
    public function efficiencies(?int $goodPieces, ?int $fullTarget, ?int $adjustedTarget, ?int $runtimeTarget): array
    {
        return [
            'shift_attainment_pct' => $this->pct($goodPieces, $fullTarget),
            'adjusted_efficiency_pct' => $this->pct($goodPieces, $adjustedTarget),
            'running_efficiency_pct' => $this->pct($goodPieces, $runtimeTarget),
        ];
    }

    /**
     * Convert an actual-production entry in whatever basis the supervisor
     * counted in into pieces. The basis is recorded rather than inferred:
     * "11 boxes" and "8910 pieces" are the same quantity but not the same
     * statement, and the approval screen needs to show which was typed.
     *
     * @param  string  $basis  boxes|trays|pouches|pieces
     * @return array{pieces: ?int, basis: string, per_unit: ?int}
     */
    public function actualToPieces(
        string $basis,
        ?int $count,
        ?int $nosPerBox,
        ?int $nosPerTray,
        ?int $nosPerPouch,
        ?int $loosePieces = null,
    ): array {
        $per = match ($basis) {
            'boxes' => $nosPerBox,
            'trays' => $nosPerTray,
            'pouches' => $nosPerPouch,
            default => 1,
        };

        $pieces = null;
        if ($count !== null && $per !== null && $per > 0) {
            $pieces = $count * $per + ($loosePieces ?? 0);
        } elseif ($basis === 'pieces' && $count !== null) {
            $pieces = $count;
        }

        return ['pieces' => $pieces, 'basis' => $basis, 'per_unit' => $basis === 'pieces' ? 1 : $per];
    }

    private function pct(?int $actual, ?int $target): ?float
    {
        if ($actual === null || $target === null || $target <= 0) {
            return null;
        }

        return round((float) bcmul(bcdiv((string) $actual, (string) $target, 8), '100', 8), 2);
    }

    /** Downtime cannot push a span below zero — that would invent capacity. */
    private function floorAtZero(string $value): string
    {
        return bccomp($value, '0', 6) === -1 ? '0' : $value;
    }
}
