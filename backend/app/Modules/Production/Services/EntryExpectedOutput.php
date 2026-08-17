<?php

namespace App\Modules\Production\Services;

/**
 * The expected-output block of a completed entry's metrics — expected
 * pieces / boxes / pouches, the netted hours and the piece-grain efficiency
 * — computed by WHICHEVER formula the entry's calculation_version stamp
 * names. Two implementations, selected by ShiftProductionEntryService::
 * productionMetrics():
 *
 *   LegacyEntryMetrics  — production_v2_floor, legacy_v1 and null: the
 *                         inline WB2 computation those entries were approved
 *                         under, byte-for-byte.
 *   UnifiedEntryMetrics — production_v3_unified: the engine's targets(),
 *                         the same targetPieces() the Start Batch preview
 *                         calls.
 *
 * Everything else in the metrics — rejection, lumps, issued kg, the
 * reconciliation, the bands, the approval gate — is version-independent
 * and stays in productionMetrics(). Nothing here reaches Tally.
 */
interface EntryExpectedOutput
{
    /**
     * @param  string|null  $cycleTime  the entry's snapshotted standard cycle time, seconds
     * @param  int|null  $cavities  active cavities
     * @param  string|null  $hours  running hours as typed at completion (the raw figure; netting happens here)
     * @param  string  $downtimeMinutes  completion-recorded, runtime-reducing minutes at 2dp ('0.00' when none)
     * @param  PackQuantities  $pack  the run's pack counts through the ONE resolver
     * @param  string|null  $actualPieces  quantity_produced as a decimal string
     * @return array{
     *     expected_pieces: ?string, expected_pieces_gross: ?int, downtime_impact_pieces: ?int,
     *     expected_boxes: ?int, expected_pouches: ?int,
     *     net_running_hours: ?string, efficiency_pct: ?float,
     * }
     */
    public function compute(
        ?string $cycleTime,
        ?int $cavities,
        ?string $hours,
        string $downtimeMinutes,
        PackQuantities $pack,
        ?string $actualPieces,
    ): array;
}
