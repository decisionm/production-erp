<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Production\Models\Shift;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When a shift voucher may be offered to the agent (DEC-20260807-002).
 *
 * Without this gate, "shift granularity" barely consolidates anything: the
 * agent polls every 90 seconds, pending() stamps delivered_at on the way
 * out, and first delivery closes the voucher's merge window — so each
 * approval after the first lands in a -2/-3 follow-up voucher and a real
 * shift still posts roughly one voucher per approval, renamed. The fix is
 * to not hand a shift voucher out until the shift is done collecting:
 *
 *   released = (shift end_time has passed for the voucher's production
 *               date) AND (>= tally-sync.release_idle_minutes since the
 *               voucher's last merge)
 *              OR the accountant pressed "Release now" (released_at set).
 *
 * Deliberately narrow: batch-mode vouchers are never held, a voucher the
 * agent already holds (delivered_at set) keeps reappearing until acked
 * exactly as before, and the delivered_at double-post guard is fronted,
 * never weakened. A voucher created after its shift already ended obeys
 * only the idle-hold — the shift-end condition is already met.
 */
class ShiftVoucherReleaseGate
{
    /** Whether pending() must keep this voucher out of the agent's hands. */
    public function withholds(TallySyncEntry $entry): bool
    {
        return $this->hold($entry) !== null;
    }

    /**
     * Why this voucher is being held, or null if it is deliverable.
     *
     * 'collecting'   — the shift is still running; nothing leaves before
     *                  its end_time passes for the voucher's date.
     * 'quiet-period' — the shift has ended but something merged into the
     *                  voucher less than the idle-hold ago; every late
     *                  merge pushes releasable_at out again.
     *
     * @return array{
     *     phase: 'collecting'|'quiet-period',
     *     shift_ends_at: ?CarbonInterface,
     *     last_merged_at: CarbonInterface,
     *     releasable_at: CarbonInterface,
     * }|null
     */
    public function hold(TallySyncEntry $entry): ?array
    {
        // Only an undelivered, unreleased, still-pending SHIFT voucher is
        // ever held. Everything else — batch vouchers, anything the agent
        // already collected, anything synced/failed, anything manually
        // released — passes exactly as it did before this gate existed.
        if ($entry->status !== TallySyncStatus::Pending
            || $entry->delivered_at !== null
            || $entry->released_at !== null
            || $entry->syncable_type !== (new Shift)->getMorphClass()) {
            return null;
        }

        $now = now();
        $endsAt = $this->shiftEndsAt($entry);

        // last_merged_at is null only on vouchers that predate the gate;
        // created_at is the honest stand-in (creation IS the first merge).
        $lastMerged = $entry->last_merged_at ?? $entry->created_at;
        $quietUntil = $lastMerged->copy()->addMinutes($this->idleMinutes());

        if ($endsAt !== null && $now->lt($endsAt)) {
            return [
                'phase' => 'collecting',
                'shift_ends_at' => $endsAt,
                'last_merged_at' => $lastMerged,
                // An estimate, not a promise: a merge after shift end would
                // push it out again. Never earlier than the shift's end.
                'releasable_at' => $quietUntil->gt($endsAt) ? $quietUntil : $endsAt,
            ];
        }

        if ($now->lt($quietUntil)) {
            return [
                'phase' => 'quiet-period',
                'shift_ends_at' => $endsAt,
                'last_merged_at' => $lastMerged,
                'releasable_at' => $quietUntil,
            ];
        }

        return null;
    }

    /**
     * When this voucher's shift stops collecting, on the voucher's own
     * production date — from shifts.end_time, NEVER a hardcoded clock time
     * (DEC-20260807-002: 7am/3pm/11pm are today's shift ends, not
     * constants). Same overnight convention as Shift::productionDateFor —
     * an overnight shift's production date is the date it STARTED, so its
     * instance for date D ends at D+1's end_time (a 22:00→06:00 C shift
     * voucher for D releases at D+1 06:00).
     *
     * Null when the shift or the payload date can't be resolved (a shift
     * soft-deleted after the run, historical junk) — the voucher then
     * obeys only the idle-hold rather than being stranded forever.
     */
    private function shiftEndsAt(TallySyncEntry $entry): ?CarbonInterface
    {
        $shift = $entry->syncable;
        if (! $shift instanceof Shift) {
            return null;
        }

        $date = $entry->payload['voucher_date'] ?? null;
        if (! is_string($date) || $date === '') {
            return null;
        }

        // TIME columns come back as "HH:MM:SS" strings ("HH:MM" in some
        // drivers) — Carbon::parse takes both.
        $endsAt = Carbon::parse("{$date} {$shift->end_time}");

        return $shift->start_time > $shift->end_time ? $endsAt->addDay() : $endsAt;
    }

    private function idleMinutes(): int
    {
        return max(0, (int) config('tally-sync.release_idle_minutes'));
    }
}
