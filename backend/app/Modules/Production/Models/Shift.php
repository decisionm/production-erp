<?php

namespace App\Modules\Production\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'start_time', 'end_time', 'is_active'])]
class Shift extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The production date an entry made at $at belongs to, for this shift.
     * Factory convention: everything an overnight shift (start > end, e.g.
     * Night 22:00–06:00) produces is filed under the date the shift STARTED.
     * An overnight instance started yesterday whenever the clock is before
     * its start time — so a Night entry at 02:00, at 06:10 (the handover
     * grace window) or at 10:00 (late paperwork) all file under yesterday,
     * while at 23:00 it files under today. The frontend applies the same
     * rule when it sends production_date explicitly (shiftClock.ts) — keep
     * the two in sync.
     */
    public function productionDateFor(?CarbonInterface $at = null): string
    {
        $at ??= now();

        // TIME columns come back as "HH:MM:SS" strings, which compare
        // correctly as strings.
        $overnight = $this->start_time > $this->end_time;

        if ($overnight && $at->format('H:i:s') < $this->start_time) {
            return $at->copy()->subDay()->toDateString();
        }

        return $at->toDateString();
    }
}
