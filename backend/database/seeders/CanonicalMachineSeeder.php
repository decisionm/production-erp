<?php

namespace Database\Seeders;

use App\Modules\Production\Models\WorkCenter;
use Illuminate\Database\Seeder;

/**
 * The factory's ten real machines, as canonical work centers.
 *
 * Why this exists: a local database seeded from BottleManufacturingDemoSeeder
 * carries five illustrative stations (Injection Molding Machine 1, Labeling
 * Station, …) and none of the real machines, so every machine picker shows
 * names the factory has never used. Production carries both — the ten real
 * ones plus the same five demo rows.
 *
 * Three rules, all load-bearing:
 *
 *  1. **Matched on `code`, never inserted blindly.** Re-running changes
 *     nothing that is already correct, and existing rows keep their IDs —
 *     shift entries and production configurations reference those IDs, and
 *     a "clean rebuild" of this table would orphan production history.
 *  2. **Legacy stations are DEACTIVATED, never deleted.** They have entries
 *     against them. Deactivating removes them from pickers while leaving
 *     history readable; deleting would break it.
 *  3. **Names and sequence only.** No cavity limits, no cycle-time bounds —
 *     the factory's master workbook leaves every cavity field empty, and a
 *     seeded guess would silently constrain or permit the wrong thing.
 *
 * NOT run by deploy.sh (which runs `migrate --force` only). Applying this to
 * production is a deliberate `db:seed --class=CanonicalMachineSeeder`.
 *
 * Open question for the factory: the master workbook lists the floor code as
 * ASB-1…ASB-10 while the live instance uses MC-01…MC-10. This seeder keeps
 * MC-xx because that is what production already references; if ASB is the
 * code the floor actually paints on the machines, it is a rename of these
 * same rows, not a new set.
 */
class CanonicalMachineSeeder extends Seeder
{
    /** Demo rows from BottleManufacturingDemoSeeder — deactivated, kept. */
    private const LEGACY_STATION_CODES = ['INJ-01', 'BLOW-01', 'EBM-01', 'LABEL-01', 'PACK-01'];

    public function run(): void
    {
        foreach (range(1, 10) as $number) {
            $code = sprintf('MC-%02d', $number);

            $machine = WorkCenter::withTrashed()->firstOrNew(['code' => $code]);

            // Never overwrite a name the factory has already corrected —
            // only fill it when the row is new or still carries the
            // generic code as its name.
            if (! $machine->exists || blank($machine->name) || $machine->name === $code) {
                $machine->name = "Machine {$number}";
            }

            $machine->display_sequence = $number;
            $machine->is_active = true;
            $machine->save();

            if ($machine->trashed()) {
                $machine->restore();
            }
        }

        // Deactivate the demo stations. Their rows, and everything that
        // references them, survive untouched.
        WorkCenter::query()
            ->whereIn('code', self::LEGACY_STATION_CODES)
            ->update(['is_active' => false]);
    }
}
