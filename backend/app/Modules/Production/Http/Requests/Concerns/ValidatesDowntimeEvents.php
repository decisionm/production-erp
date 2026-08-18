<?php

namespace App\Modules\Production\Http\Requests\Concerns;

use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cross-field checks for completion-time downtime lines, shared by
 * CompleteBatchRequest and HandoverRequest so the two paths can never
 * drift apart (the packing_lines rules live only on the normal-completion
 * path and that gap is documented — downtime must not repeat it).
 *
 * The line shape is `minutes`, matching what production_downtime_events
 * stores (the table has no from/to columns); the note carries the timing
 * text ("14:30–15:00 power cut"). Overlapping lines are deliberately
 * allowed — one power cut hits every machine on the floor at once, so two
 * batches legitimately log the same wall-clock window. What is refused is
 * a single line longer than the scheduled shift: no interruption inside
 * an 8-hour shift can last 9 hours.
 */
trait ValidatesDowntimeEvents
{
    /**
     * Absolute cap when the entry carries no scheduled_hours snapshot —
     * the same 24 h ceiling CompleteBatchRequest puts on running_hours.
     */
    private static string $downtimeFallbackCapMinutes = '1440';

    /**
     * Field-level rules for one downtime line, under the given prefix
     * ('downtime_events' or 'completion.downtime_events').
     *
     * @return array<string, array<int, mixed>>
     */
    private function downtimeEventRules(string $prefix): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            // ACTIVE ONLY, and this WIDENS THE REFUSAL SET on live data.
            // A withdrawn downtime reason must not be choosable on a NEW
            // completion or handover — otherwise Archive means nothing on
            // this master, which is the Configuration Lifecycle Contract's
            // whole point. Already-recorded events keep naming their reason
            // (production_downtime_events is untouched and the report still
            // renders it); only a fresh selection is refused. Same
            // Rule::exists(...)->where('is_active', true) shape the scrap
            // reason paths already use.
            "{$prefix}.*.downtime_reason_id" => ['required', 'integer', Rule::exists('downtime_reasons', 'id')->where('is_active', true)],
            "{$prefix}.*.minutes" => ['required', 'numeric', 'gt:0'],
            "{$prefix}.*.note" => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The after-hook checks: per-line cap against the scheduled shift, and
     * the reason's own note requirement (enforced here so the 422 lands on
     * the exact line and field, not as a bare service exception).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validateDowntimeEvents(Validator $validator, array $lines, string $prefix): void
    {
        $entry = $this->route('shift_production_entry');
        $scheduledHours = $entry instanceof ShiftProductionEntry && $entry->scheduled_hours !== null
            ? (string) $entry->scheduled_hours
            : null;
        $capMinutes = $scheduledHours !== null
            ? bcmul($scheduledHours, '60', 2)
            : self::$downtimeFallbackCapMinutes;

        foreach ($lines as $index => $line) {
            $minutes = $line['minutes'] ?? null;
            if (is_numeric($minutes) && bccomp((string) $minutes, $capMinutes, 2) === 1) {
                $limit = $scheduledHours !== null
                    ? "the scheduled shift ({$scheduledHours} h = {$capMinutes} min)"
                    : 'a full day (1440 min)';
                $validator->errors()->add(
                    "{$prefix}.{$index}.minutes",
                    "Downtime of {$minutes} minutes is longer than {$limit}. Log only the portion that fell inside this shift.",
                );
            }

            $reasonId = $line['downtime_reason_id'] ?? null;
            $reason = is_numeric($reasonId) ? DowntimeReason::query()->find($reasonId) : null;
            if ($reason !== null && $reason->requires_note && blank($line['note'] ?? null)) {
                $validator->errors()->add(
                    "{$prefix}.{$index}.note",
                    "\"{$reason->description}\" needs a note — say when it happened (from–to times) or what was done.",
                );
            }
        }
    }
}
