<?php

namespace Database\Seeders;

use App\Modules\Production\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * The factory's three shifts.
 *
 * KEYED ON START TIME, NOT ON NAME, and that distinction caused a live incident.
 *
 * This seeder runs on EVERY deploy (scripts/deploy.sh names it explicitly), and it
 * used to match on name: firstOrCreate(['name' => 'Morning'], ...). On 6 August
 * the shifts were renamed to the factory's own vocabulary — Morning became
 * "Shift A" — and the very next deploy found no shift called "Morning" and
 * helpfully created one. A few deploys later the factory had six shifts, two per
 * start time, and the floor's shift picker offered all six. The owner spotted it
 * before I did: "still A, B C also there, morning afternoon also there".
 *
 * The seeder was idempotent on its own terms and still duplicated data, which is
 * the lesson worth keeping: IDEMPOTENT ON A MUTABLE FIELD IS NOT IDEMPOTENT. A
 * name is something a factory renames. A shift's start time is what the shift IS —
 * two shifts starting at 06:00 are one shift, whatever either is called.
 *
 * So a renamed shift is now recognised and left entirely alone, name included:
 * this seeder writes what is MISSING and nothing else, which is all a deploy-time
 * seeder is entitled to do to a running factory.
 */
class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            ['name' => 'Shift A', 'start_time' => '06:00', 'end_time' => '14:00'],
            ['name' => 'Shift B', 'start_time' => '14:00', 'end_time' => '22:00'],
            ['name' => 'Shift C', 'start_time' => '22:00', 'end_time' => '06:00'],
        ];

        // COMPARED IN PHP, ON HH:MM, and not by any SQL time function.
        //
        // The first attempt at this fix used whereTime('start_time', '=', '06:00')
        // and did not match, because whereTime compares against HH:MM:SS — so the
        // seeder found nothing and created a fourth shift, reproducing the exact
        // duplicate it was written to prevent. Caught by its own test, which is
        // the only reason it is not live.
        //
        // Reading the rows once and truncating to five characters has no such
        // ambiguity: it does not care whether the column is a time, a string or a
        // datetime, nor which database is under it. Three rows is not a query to
        // optimise.
        $existing = Shift::query()
            ->pluck('start_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->all();

        foreach ($shifts as $shift) {
            if (in_array(substr($shift['start_time'], 0, 5), $existing, true)) {
                continue;
            }

            Shift::query()->create($shift);
        }
    }
}
