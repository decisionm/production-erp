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
     * Night 22:00–06:00) produces is filed under the date the shift STARTED —
     * a batch logged at 02:00 belongs to yesterday's night shift, keeping the
     * whole night's output on one date. The frontend applies the same rule
     * when it sends production_date explicitly (shiftClock.ts) — keep in sync.
     */
    public function productionDateFor(?CarbonInterface $at = null): string
    {
        $at ??= now();

        // TIME columns come back as "HH:MM:SS" strings, which compare
        // correctly as strings.
        $overnight = $this->start_time > $this->end_time;

        if ($overnight && $at->format('H:i:s') < $this->end_time) {
            return $at->copy()->subDay()->toDateString();
        }

        return $at->toDateString();
    }
}
