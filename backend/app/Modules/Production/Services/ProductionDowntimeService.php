<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Enums\DowntimePlanningType;
use App\Modules\Production\Models\ProductionDowntimeEvent;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Validation\ValidationException;

/**
 * Downtime events, and the split that makes a shift's numbers fair.
 *
 * Planned downtime entered BEFORE Start lowers the target the supervisor is
 * measured against. Unplanned downtime added during or after the run does
 * not lower that target — it explains the gap. Conflating the two either
 * punishes a machine for a scheduled mould change or hides a breakdown.
 */
class ProductionDowntimeService
{
    /**
     * Total planned minutes for a machine on a date: already-recorded
     * planned events, plus any submitted with this Start request.
     *
     * @param  list<array{downtime_reason_id: int, minutes: numeric-string|float|int, note?: ?string}>  $incoming
     */
    public function plannedMinutesFor(int $workCenterId, string $productionDate, array $incoming = []): string
    {
        $existing = ProductionDowntimeEvent::query()
            ->where('work_center_id', $workCenterId)
            ->whereDate('production_date', $productionDate)
            ->where('is_planned', true)
            // Only events not yet tied to a batch — one already attached to
            // a previous batch belongs to that batch's arithmetic.
            ->whereNull('shift_production_entry_id')
            ->sum('minutes');

        $total = (string) $existing;
        foreach ($incoming as $event) {
            $total = bcadd($total, (string) ($event['minutes'] ?? 0), 2);
        }

        return $total;
    }

    /**
     * Persist the planned downtime submitted at Start and bind it to the
     * batch.
     *
     * @param  list<array{downtime_reason_id: int, minutes: numeric-string|float|int, note?: ?string}>  $events
     */
    public function attachPlannedToEntry(ShiftProductionEntry $entry, array $events, ?int $recordedBy): void
    {
        // Adopt any unattached planned events already logged for this
        // machine and date — they were entered ahead of the shift and their
        // whole purpose is to shape this batch's target.
        ProductionDowntimeEvent::query()
            ->where('work_center_id', $entry->work_center_id)
            ->whereDate('production_date', $entry->production_date)
            ->where('is_planned', true)
            ->whereNull('shift_production_entry_id')
            ->update(['shift_production_entry_id' => $entry->id]);

        foreach ($events as $event) {
            $reason = DowntimeReason::query()->find($event['downtime_reason_id'] ?? null);
            if ($reason === null) {
                continue;
            }

            $this->assertNoteWhenRequired($reason, $event['note'] ?? null);

            ProductionDowntimeEvent::create([
                'shift_production_entry_id' => $entry->id,
                'work_center_id' => $entry->work_center_id,
                'downtime_reason_id' => $reason->id,
                'production_date' => $entry->production_date,
                'minutes' => $event['minutes'] ?? 0,
                'is_planned' => true,
                'known_before_start' => true,
                'note' => $event['note'] ?? null,
                'recorded_by' => $recordedBy,
            ]);
        }
    }

    /**
     * Record an event during or after the run. Defaults to the reason's own
     * planning type, but a reason's default never overrides the fact that
     * this was not known before Start.
     *
     * @param  array{downtime_reason_id: int, minutes: numeric-string|float|int, note?: ?string, is_planned?: bool}  $data
     */
    public function record(ShiftProductionEntry $entry, array $data, ?int $recordedBy): ProductionDowntimeEvent
    {
        $reason = DowntimeReason::query()->findOrFail($data['downtime_reason_id']);
        $this->assertNoteWhenRequired($reason, $data['note'] ?? null);

        return ProductionDowntimeEvent::create([
            'shift_production_entry_id' => $entry->id,
            'work_center_id' => $entry->work_center_id,
            'downtime_reason_id' => $reason->id,
            'production_date' => $entry->production_date,
            'minutes' => $data['minutes'],
            'is_planned' => $data['is_planned'] ?? ($reason->planning_type === DowntimePlanningType::Planned),
            // Recorded after the batch existed, so by definition not known
            // before Start — this is what keeps unplanned impact honest.
            'known_before_start' => false,
            'note' => $data['note'] ?? null,
            'recorded_by' => $recordedBy,
        ]);
    }

    /**
     * Planned and unplanned hours for an entry's calculations.
     *
     * Only reasons flagged `reduces_runtime` count: the factory may decide
     * some logged interruptions do not stop the machine.
     *
     * @return array{planned_hours: string, unplanned_hours: string}
     */
    public function hoursFor(ShiftProductionEntry $entry): array
    {
        $events = ProductionDowntimeEvent::query()
            ->with('reason')
            ->where('shift_production_entry_id', $entry->id)
            ->get();

        $planned = '0';
        $unplanned = '0';

        foreach ($events as $event) {
            if ($event->reason !== null && ! $event->reason->reduces_runtime) {
                continue;
            }

            $hours = bcdiv((string) $event->minutes, '60', 6);
            if ($event->is_planned && $event->known_before_start) {
                $planned = bcadd($planned, $hours, 6);
            } else {
                $unplanned = bcadd($unplanned, $hours, 6);
            }
        }

        return ['planned_hours' => $planned, 'unplanned_hours' => $unplanned];
    }

    private function assertNoteWhenRequired(DowntimeReason $reason, ?string $note): void
    {
        if ($reason->requires_note && blank($note)) {
            throw ValidationException::withMessages([
                'note' => "\"{$reason->description}\" requires a note.",
            ]);
        }
    }
}
