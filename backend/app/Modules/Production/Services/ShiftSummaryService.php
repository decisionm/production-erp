<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\LogStatus;
use App\Modules\Production\Models\MachineDowntimeLog;
use App\Modules\Production\Models\MoldChangeLog;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftSummary;

/**
 * The Shift KPI Summary is a computed rollup of the Production Report and
 * Idle Time Report, not an independent data source — see
 * TALLY-SYNC-MASTER-PLAN.md §10's shift-KPI analysis. Only two fields on
 * this table are genuinely new raw inputs (target_production_kg,
 * power_consumption_units); everything else in report() is arithmetic over
 * shift_production_entries (Phase 2a) and Phase 2b's downtime/mold-change
 * logs.
 */
class ShiftSummaryService
{
    /**
     * @param  array{shift_id: int, production_date?: string, supervisor_id?: int, target_production_kg?: string, power_consumption_units?: string, remarks?: string}  $data
     */
    public function upsert(array $data, ?int $createdBy): ShiftSummary
    {
        $productionDate = $data['production_date'] ?? now()->toDateString();

        // Not ShiftSummary::updateOrCreate() — its match query compares the
        // raw string against a `date`-cast column, which Eloquent persists
        // in full datetime format ("Y-m-d 00:00:00"), so a plain "Y-m-d"
        // match string would silently miss the existing row and violate the
        // (shift_id, production_date) unique constraint on the second save.
        $summary = ShiftSummary::query()
            ->where('shift_id', $data['shift_id'])
            ->whereDate('production_date', $productionDate)
            ->first() ?? new ShiftSummary(['shift_id' => $data['shift_id'], 'production_date' => $productionDate]);

        $summary->fill([
            'supervisor_id' => $data['supervisor_id'] ?? null,
            'target_production_kg' => $data['target_production_kg'] ?? null,
            'power_consumption_units' => $data['power_consumption_units'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $createdBy,
        ])->save();

        return $summary->fresh(['shift', 'supervisor']);
    }

    public function find(int $shiftId, string $productionDate): ?ShiftSummary
    {
        return ShiftSummary::query()
            ->with(['shift', 'supervisor'])
            ->where('shift_id', $shiftId)
            ->whereDate('production_date', $productionDate)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function report(int $shiftId, string $productionDate): array
    {
        $summary = $this->find($shiftId, $productionDate);

        $entries = ShiftProductionEntry::query()
            ->where('shift_id', $shiftId)
            ->whereDate('production_date', $productionDate)
            ->get();

        $completed = $entries->where('batch_status', BatchStatus::Completed);

        $actualProductionKg = $completed->reduce(
            fn (string $carry, ShiftProductionEntry $entry) => bcadd($carry, (string) ($entry->quantity_produced_kg ?? '0'), 4),
            '0.0000',
        );
        $rejectionKg = $completed->reduce(
            fn (string $carry, ShiftProductionEntry $entry) => bcadd($carry, (string) ($entry->quantity_rejection_kg ?? '0'), 4),
            '0.0000',
        );

        $targetProductionKg = $summary?->target_production_kg;
        $powerConsumptionUnits = $summary?->power_consumption_units;

        $efficiencyPercent = $targetProductionKg && bccomp((string) $targetProductionKg, '0', 4) > 0
            ? (float) bcdiv(bcmul($actualProductionKg, '100', 4), (string) $targetProductionKg, 4)
            : null;

        $rejectionPercent = bccomp($actualProductionKg, '0', 4) > 0
            ? (float) bcdiv(bcmul($rejectionKg, '100', 4), $actualProductionKg, 4)
            : null;

        $netGoodOutputKg = bcsub($actualProductionKg, $rejectionKg, 4);

        $unitPerKg = $powerConsumptionUnits && bccomp($actualProductionKg, '0', 4) > 0
            ? (float) bcdiv((string) $powerConsumptionUnits, $actualProductionKg, 4)
            : null;

        // Machines Down is a live snapshot (currently-open breakdowns),
        // mirroring how Machines Running counts currently-in-progress
        // batches — both answer "right now," not "at some point this
        // shift." Idle Time only sums *closed* downtime logs: an open one
        // is still accumulating time nobody's decided the final total of
        // yet, so counting it here would make the number visibly tick
        // backward once it's finally closed.
        $downtimeLogs = MachineDowntimeLog::query()
            ->where('shift_id', $shiftId)
            ->whereDate('production_date', $productionDate)
            ->get();

        $machinesDown = $downtimeLogs->where('status', LogStatus::Open)->pluck('work_center_id')->unique()->count();
        $idleTimeHours = bcdiv(
            (string) $downtimeLogs->where('status', LogStatus::Closed)->reduce(
                fn (string $carry, MachineDowntimeLog $log) => bcadd($carry, (string) $log->total_minutes, 2),
                '0.00',
            ),
            '60',
            4,
        );

        $noOfMoldChanges = MoldChangeLog::query()
            ->where('shift_id', $shiftId)
            ->whereDate('production_date', $productionDate)
            ->count();

        return [
            'shift_id' => $shiftId,
            'production_date' => $productionDate,
            'target_production_kg' => $targetProductionKg,
            'actual_production_kg' => $actualProductionKg,
            'rejection_kg' => $rejectionKg,
            'net_good_output_kg' => $netGoodOutputKg,
            'efficiency_percent' => $efficiencyPercent,
            'rejection_percent' => $rejectionPercent,
            'machines_running' => $entries->where('batch_status', BatchStatus::InProgress)->pluck('work_center_id')->unique()->count(),
            'machines_down' => $machinesDown,
            'idle_time_hours' => $idleTimeHours,
            'no_of_mold_changes' => $noOfMoldChanges,
            'power_consumption_units' => $powerConsumptionUnits,
            'unit_per_kg' => $unitPerKg,
            'remarks' => $summary?->remarks,
            'supervisor' => $summary?->supervisor,
        ];
    }
}
