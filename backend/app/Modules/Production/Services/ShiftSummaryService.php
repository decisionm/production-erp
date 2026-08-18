<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\LogStatus;
use App\Modules\Production\Models\MachineDowntimeLog;
use App\Modules\Production\Models\MoldChangeLog;
use App\Modules\Production\Models\PowerInterruptionLog;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftStockCount;
use App\Modules\Production\Models\ShiftSummary;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Shift KPI Summary is a computed rollup of the Production Report and
 * Idle Time Report, not an independent data source — see
 * docs/archive/TALLY-SYNC-MASTER-PLAN.md §10's shift-KPI analysis. Only two fields on
 * this table are genuinely new raw inputs (target_production_kg,
 * power_consumption_units); everything else in report() is arithmetic over
 * shift_production_entries (Phase 2a) and Phase 2b's downtime/mold-change/
 * power-interruption/stock-count logs.
 *
 * report() takes an optional $shiftId: given, it's the per-shift view;
 * null means "every shift that ran this date," the day-wise rollup —
 * same computation, just without the shift_id filter on each query.
 */
class ShiftSummaryService
{
    /**
     * @param  array{shift_id: int, production_date?: string, supervisor_id?: int, target_production_kg?: string, power_consumption_units?: string, remarks?: string}  $data
     */
    public function upsert(array $data, ?int $createdBy): ShiftSummary
    {
        $productionDate = $data['production_date'] ?? Shift::query()->find($data['shift_id'])?->productionDateFor() ?? now()->toDateString();

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
     * The shifts with ANYTHING recorded on this production date — a summary
     * row, a batch, a downtime / mold-change / power-interruption log or a
     * stock count: the same six tables report() reads — in the shift
     * picker's order (start_time, then id), deactivated and deleted shifts
     * included: an old date's records sit on the shifts that ran it, and
     * report($shiftId, $date) answers for any of them. The Export Center's
     * shift_summary kind reads this to break the day-wide rollup out one
     * shift at a time (ShiftSummaryExport); nothing on a screen calls it.
     * A shift with nothing recorded is not listed — its report would be
     * all zeros, and the day row already says the day.
     *
     * @return Collection<int, Shift>
     */
    public function shiftsWithRecordsOn(string $productionDate): Collection
    {
        $ids = collect([
            ShiftSummary::class,
            ShiftProductionEntry::class,
            MachineDowntimeLog::class,
            MoldChangeLog::class,
            PowerInterruptionLog::class,
            ShiftStockCount::class,
        ])
            ->flatMap(fn (string $model) => $model::query()
                ->whereDate('production_date', $productionDate)
                ->distinct()
                ->pluck('shift_id'))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return Shift::withTrashed()
            ->whereIn('id', $ids)
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function report(?int $shiftId, string $productionDate): array
    {
        $summaries = ShiftSummary::query()
            ->with(['shift', 'supervisor'])
            ->when($shiftId, fn ($query) => $query->where('shift_id', $shiftId))
            ->whereDate('production_date', $productionDate)
            ->get();

        // Day-wide: sum whatever target/power figures each shift's
        // supervisor entered; a shift with no summary row yet just
        // contributes nothing rather than blocking the total.
        $targetProductionKg = $summaries->reduce(
            fn (?string $carry, ShiftSummary $s) => $s->target_production_kg !== null ? bcadd($carry ?? '0', (string) $s->target_production_kg, 4) : $carry,
            null,
        );
        $powerConsumptionUnits = $summaries->reduce(
            fn (?string $carry, ShiftSummary $s) => $s->power_consumption_units !== null ? bcadd($carry ?? '0', (string) $s->power_consumption_units, 4) : $carry,
            null,
        );

        $entries = ShiftProductionEntry::query()
            ->with('item')
            ->when($shiftId, fn ($query) => $query->where('shift_id', $shiftId))
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

        $efficiencyPercent = $targetProductionKg && bccomp($targetProductionKg, '0', 4) > 0
            ? (float) bcdiv(bcmul($actualProductionKg, '100', 4), $targetProductionKg, 4)
            : null;

        $rejectionPercent = bccomp($actualProductionKg, '0', 4) > 0
            ? (float) bcdiv(bcmul($rejectionKg, '100', 4), $actualProductionKg, 4)
            : null;

        $netGoodOutputKg = bcsub($actualProductionKg, $rejectionKg, 4);

        $unitPerKg = $powerConsumptionUnits && bccomp($actualProductionKg, '0', 4) > 0
            ? (float) bcdiv($powerConsumptionUnits, $actualProductionKg, 4)
            : null;

        // One row per item actually produced — the paper form's KPI sheet
        // only has a single "Actual Production" total, but a shift (or a
        // whole day) almost always runs more than one item, and that
        // breakdown is exactly what was missing before: the aggregate
        // told you *how much*, never *what*.
        $itemsManufactured = $completed->groupBy('item_id')->map(function ($group) {
            $item = $group->first()->item;

            return [
                'item' => ['id' => $item->id, 'sku' => $item->sku, 'name' => $item->name],
                'batches' => $group->count(),
                'quantity_produced' => (string) $group->reduce(fn (string $carry, ShiftProductionEntry $e) => bcadd($carry, (string) $e->quantity_produced, 4), '0.0000'),
                'quantity_produced_kg' => (string) $group->reduce(fn (string $carry, ShiftProductionEntry $e) => bcadd($carry, (string) ($e->quantity_produced_kg ?? '0'), 4), '0.0000'),
                'quantity_rejected' => (string) $group->reduce(fn (string $carry, ShiftProductionEntry $e) => bcadd($carry, (string) $e->quantity_scrap, 4), '0.0000'),
                'quantity_rejection_kg' => (string) $group->reduce(fn (string $carry, ShiftProductionEntry $e) => bcadd($carry, (string) ($e->quantity_rejection_kg ?? '0'), 4), '0.0000'),
            ];
        })->values();

        // Machines Down is a live snapshot (currently-open breakdowns),
        // mirroring how Machines Running counts currently-in-progress
        // batches — both answer "right now," not "at some point this
        // shift." Idle Time only sums *closed* downtime logs: an open one
        // is still accumulating time nobody's decided the final total of
        // yet, so counting it here would make the number visibly tick
        // backward once it's finally closed.
        $downtimeLogs = MachineDowntimeLog::query()
            ->with('workCenter')
            ->when($shiftId, fn ($query) => $query->where('shift_id', $shiftId))
            ->whereDate('production_date', $productionDate)
            ->orderBy('from_time')
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

        $moldChangeLogs = MoldChangeLog::query()
            ->with(['workCenter', 'changedFromItem', 'changedFromMold', 'changedToItem', 'changedToMold'])
            ->when($shiftId, fn ($query) => $query->where('shift_id', $shiftId))
            ->whereDate('production_date', $productionDate)
            ->orderBy('from_time')
            ->get();

        // Plant-wide, so — unlike per-machine downtime — every minute here
        // is genuine idle time for the *whole* floor, not folded into
        // idle_time_hours above (that would conflate two different kinds
        // of "down": one machine's breakdown vs. everything losing power
        // at once). Kept as its own figure so neither number silently
        // absorbs the other's meaning.
        $powerInterruptionLogs = PowerInterruptionLog::query()
            ->when($shiftId, fn ($query) => $query->where('shift_id', $shiftId))
            ->whereDate('production_date', $productionDate)
            ->orderBy('from_time')
            ->get();

        $powerInterruptionHours = (string) $powerInterruptionLogs->reduce(
            fn (string $carry, PowerInterruptionLog $log) => bcadd($carry, (string) $log->idle_hours, 4),
            '0.0000',
        );

        $stockCounts = ShiftStockCount::query()
            ->with('item')
            ->when($shiftId, fn ($query) => $query->where('shift_id', $shiftId))
            ->whereDate('production_date', $productionDate)
            ->get();

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
            'no_of_mold_changes' => $moldChangeLogs->count(),
            'power_consumption_units' => $powerConsumptionUnits,
            'unit_per_kg' => $unitPerKg,
            'power_interruption_hours' => $powerInterruptionHours,
            // Day-wide has no single accountable name — that's a per-shift
            // concept (whoever closes that shift), see UX doc §2.
            'remarks' => $shiftId ? $summaries->first()?->remarks : $summaries->pluck('remarks')->filter()->implode(' | '),
            'supervisor' => $shiftId ? $summaries->first()?->supervisor : null,
            'items_manufactured' => $itemsManufactured,
            'downtime_logs' => $downtimeLogs->map(fn (MachineDowntimeLog $log) => [
                'id' => $log->id,
                'work_center' => $log->workCenter->name,
                'nature_of_problem' => $log->nature_of_problem,
                'remedy' => $log->remedy,
                'parts_changed' => $log->parts_changed,
                'from_time' => $log->from_time->toIso8601String(),
                'to_time' => $log->to_time?->toIso8601String(),
                'total_minutes' => $log->total_minutes,
                'status' => $log->status->value,
            ])->values(),
            'mold_change_logs' => $moldChangeLogs->map(fn (MoldChangeLog $log) => [
                'id' => $log->id,
                'work_center' => $log->workCenter->name,
                'changed_from' => $log->changedFromItem?->sku,
                'changed_from_mold' => $log->changedFromMold?->code,
                'changed_to' => $log->changedToItem->sku,
                'changed_to_mold' => $log->changedToMold?->code,
                'from_time' => $log->from_time->toIso8601String(),
                'to_time' => $log->to_time?->toIso8601String(),
                'total_minutes' => $log->total_minutes,
                'status' => $log->status->value,
            ])->values(),
            'power_interruption_logs' => $powerInterruptionLogs->map(fn (PowerInterruptionLog $log) => [
                'id' => $log->id,
                'from_time' => $log->from_time->toIso8601String(),
                'to_time' => $log->to_time->toIso8601String(),
                'idle_hours' => $log->idle_hours,
            ])->values(),
            'stock_counts' => $stockCounts->map(fn (ShiftStockCount $count) => [
                'id' => $count->id,
                'location_label' => $count->location_label,
                'item' => ['id' => $count->item->id, 'sku' => $count->item->sku, 'name' => $count->item->name],
                'quantity_kg' => $count->quantity_kg,
            ])->values(),
        ];
    }
}
