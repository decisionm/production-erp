<?php

namespace App\Modules\Production\Services;

/**
 * The expected-output computation every entry stamped production_v2_floor,
 * legacy_v1 or nothing at all was approved under — the WB2 workbook formula
 * as ShiftProductionEntryService::productionMetrics() inlined it before
 * Phase 5.5, moved here VERBATIM and frozen.
 *
 *   expected pieces = 3600 × cavities × net hours ÷ CT   (NOT floored, 8dp)
 *   net hours       = running hours − completion downtime ÷ 60, floored at 0
 *   expected boxes  = ROUND(expected pieces ÷ pack, 0)  — WB2 col W, half-up
 *   expected pouches = expected pieces ÷ pouch, per production.packing_rounding
 *   efficiency      = actual pieces ÷ expected pieces × 100, 1dp
 *
 * HISTORICAL ONLY. Do not "fix" the unfloored fraction, the strictly-
 * positive hours guard, or any rounding here: the live instance holds
 * approved entries whose signed figures are exactly these, and a figure
 * that moves under an accountant's signature is a worse defect than the
 * one-shot gap it would close (ExpectedOutputDivergenceTest documents the
 * gap; production_v3_unified closes it for every NEW entry). The golden
 * pins in EstimationUnifiedTest hold this class byte-for-byte.
 */
class LegacyEntryMetrics implements EntryExpectedOutput
{
    public function compute(
        ?string $cycleTime,
        ?int $cavities,
        ?string $hours,
        string $downtimeMinutes,
        PackQuantities $pack,
        ?string $actualPieces,
    ): array {
        // Expected pieces = 3600/CT × active cavities × running hours (WB2's
        // EST BOX numerator, always at the STANDARD cycle time — the snapshot
        // taken at Start Batch). Computed as one division at 8dp: chaining
        // 4dp bc truncations loses the second decimal (144000/10.6 must
        // round to 13584.91, not 13584.90).
        //
        // Downtime logged AT COMPLETION nets out of the hours before the
        // WB2 formula (owner's rule, 30-Jul: a power cut or mould change
        // must not count against efficiency — the paper report nets B/D
        // time out of the day the same way). With no such events the hours
        // string is left completely untouched, so a batch without downtime
        // lines computes byte-identically to before netting existed.
        $netHours = $hours;
        if ($hours !== null && bccomp($downtimeMinutes, '0', 2) === 1) {
            $netHours = bcsub($hours, bcdiv($downtimeMinutes, '60', 6), 6);
            if (bccomp($netHours, '0', 6) === -1) {
                // Floored at zero — expected output goes honest-null; the
                // raw typed figure stays on running_hours untouched.
                $netHours = '0';
            }
        }

        $expectedPiecesRaw = null;
        if ($cycleTime !== null && bccomp($cycleTime, '0', 4) === 1
            && $cavities !== null && $cavities > 0
            && $netHours !== null && bccomp($netHours, '0', 4) === 1) {
            $expectedPiecesRaw = bcdiv(bcmul(bcmul('3600', (string) $cavities, 4), $netHours, 4), $cycleTime, 8);
        }

        // Expected boxes = ROUND(expected_pieces / pack, 0) — WB2 col W.
        $nosPerBox = $pack->nos_per_box;
        $expectedBoxes = null;
        if ($expectedPiecesRaw !== null && $nosPerBox !== null && $nosPerBox > 0) {
            $expectedBoxes = (int) $this->bcRoundHalfUp(bcdiv($expectedPiecesRaw, (string) $nosPerBox, 8), 0);
        }

        // Expected pouches = expected_pieces / pouch standard — a packing
        // suggestion, not a workbook figure, so it rounds per
        // production.packing_rounding.
        $nosPerPouch = $pack->nos_per_pouch;
        $expectedPouches = null;
        if ($expectedPiecesRaw !== null && $nosPerPouch !== null && $nosPerPouch > 0) {
            $expectedPouches = (int) $this->applyPackingRounding(bcdiv($expectedPiecesRaw, (string) $nosPerPouch, 8), 0);
        }

        // Efficiency = actual PIECES / expected pieces × 100 — piece-grain,
        // not the WB2 col Y box ratio it used to be (the owner's live batch
        // proved the box grain wrong, 30-Jul).
        $efficiency = null;
        if ($expectedPiecesRaw !== null && bccomp($expectedPiecesRaw, '0', 8) === 1 && $actualPieces !== null) {
            $efficiency = round((float) bcmul(bcdiv($actualPieces, $expectedPiecesRaw, 8), '100', 8), 1);
        }

        return [
            'expected_pieces' => $expectedPiecesRaw !== null ? $this->bcRoundHalfUp($expectedPiecesRaw, 2) : null,
            // Figures this formula never produced. Null, never computed
            // retroactively — that would be a new number on an old entry.
            'expected_pieces_gross' => null,
            'downtime_impact_pieces' => null,
            'expected_boxes' => $expectedBoxes,
            'expected_pouches' => $expectedPouches,
            'net_running_hours' => $netHours !== null ? $this->bcRoundHalfUp($netHours, 2) : null,
            'efficiency_pct' => $efficiency,
        ];
    }

    /**
     * The configurable rounding for packing suggestions —
     * production.packing_rounding: ceil (default, a part-filled container
     * still needs packing), round (half-up, same as bcRoundHalfUp), or
     * floor. bcmath-safe on the non-negative quantities this deals in. The
     * WB2 expected-boxes formula deliberately does NOT go through here.
     */
    private function applyPackingRounding(string $value, int $scale = 0): string
    {
        $mode = (string) config('production.packing_rounding', 'ceil');

        if ($mode === 'round') {
            return $this->bcRoundHalfUp($value, $scale);
        }

        // bcmath's own behaviour at $scale is truncation — which IS floor
        // for the non-negative quantities packing deals in.
        $truncated = bcadd($value, '0', $scale);

        // Compare at the value's full precision so ceil only bumps when a
        // real remainder was dropped (an exact 136.000 must stay 136).
        $dot = strpos($value, '.');
        $precision = max($scale, $dot === false ? 0 : strlen($value) - $dot - 1);

        if ($mode === 'floor' || bccomp($value, $truncated, $precision) === 0) {
            return $truncated;
        }

        return bcadd($truncated, bcdiv('1', bcpow('10', (string) $scale, 0), $scale), $scale);
    }

    /**
     * bcmath truncates; the workbook formulas ROUND. Half-up on the
     * non-negative quantities this deals in: add 5 at the first dropped
     * digit, then truncate.
     */
    private function bcRoundHalfUp(string $value, int $scale): string
    {
        $offset = bcdiv('5', bcpow('10', (string) ($scale + 1), 0), $scale + 1);

        return bcadd($value, $offset, $scale);
    }
}
