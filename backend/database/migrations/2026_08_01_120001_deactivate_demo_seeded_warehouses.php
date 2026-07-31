<?php

use App\Modules\Core\Models\AppSetting;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Retire the three rehearsal warehouses so no picker can offer them.
 *
 * The factory is one physical place and their books carry exactly ONE godown,
 * "SWAASHPET POLYMERS PVT LTD". The rows below came from
 * BottleManufacturingDemoSeeder during rehearsal and correspond to nothing on
 * the floor, but they still appear in every admin warehouse list and — more
 * to the point — they are the rows that make "is there exactly one warehouse"
 * false, which is the question FactoryWarehouseResolver asks before it can
 * answer a payload without asking a person.
 *
 * DEACTIVATED, NEVER DELETED, exactly as CanonicalMachineSeeder does for the
 * demo work centres (INJ-01 … PACK-01 — already handled there, which is why
 * this migration is warehouses only and does not touch work_centers). The
 * rehearsal rows carry stock movements and goods receipts; deleting them
 * would orphan that history. Deactivating removes them from selection while
 * leaving every past document readable.
 *
 * THREE GUARDS, all load-bearing:
 *
 *  1. **Codes only, never names.** Only the three literal codes the seeder
 *     wrote. No pattern matching on "store"/"demo"/"FG" — a factory that
 *     later names a real warehouse "Finished Goods" must not be caught.
 *  2. **`tally_guid` must be null.** WarehouseService::syncGodownsFromTally()
 *     is the only writer of that column, so a non-null value means Tally
 *     itself vouches for the row. This is the exact complement of the
 *     resolver's soleTallyLinkedWarehouse() lookup, which means this
 *     migration can never deactivate a warehouse the resolver would pick —
 *     including the case where the real godown happens to share one of these
 *     codes.
 *  3. **Never a warehouse some production setting points at.** If the day
 *     bin (or either new role) still names one of these rows from rehearsal,
 *     deactivating it would leave FactoryDayBinService still loading bags
 *     into it while the settings screen refuses to re-select it. That
 *     divergence is worse than a stale picker entry, so the row is left
 *     alone and the skip is logged for a human to settle.
 *
 * Idempotent in the sense that matters: only rows still active are touched,
 * so a re-run converges on the same end state, writes nothing it has already
 * written, and logs an empty deactivation list. Laravel runs a migration
 * once, so a warehouse someone deliberately reactivates later is not
 * second-guessed by this file — but note that is the migration runner's
 * doing, not a check here: calling up() again WOULD retire it again.
 */
return new class extends Migration
{
    /** Exactly what BottleManufacturingDemoSeeder::seedWarehouses() creates. */
    private const DEMO_WAREHOUSE_CODES = ['RM-STORE', 'WIP', 'FG-STORE'];

    public function up(): void
    {
        $protectedIds = $this->warehouseIdsNamedBySettings();

        $candidates = Warehouse::query()
            ->whereIn('code', self::DEMO_WAREHOUSE_CODES)
            ->whereNull('tally_guid')
            ->where('is_active', true)
            ->get();

        $deactivated = [];

        foreach ($candidates as $warehouse) {
            if (in_array((int) $warehouse->id, $protectedIds, true)) {
                Log::info('deactivate_demo_seeded_warehouses: kept active, named by a production setting', [
                    'warehouse_id' => $warehouse->id,
                    'code' => $warehouse->code,
                ]);

                continue;
            }

            $warehouse->update(['is_active' => false]);
            $deactivated[] = $warehouse->code;
        }

        Log::info('deactivate_demo_seeded_warehouses: done', [
            'deactivated' => $deactivated,
            'deactivated_count' => count($deactivated),
            'considered' => $candidates->pluck('code')->all(),
        ]);
    }

    public function down(): void
    {
        // Deliberately empty, per the amber-dosing precedent: reactivating
        // by migration would override whatever a person has decided since.
        // Reinstating a warehouse is a deliberate act on the admin screen.
    }

    /**
     * Warehouse ids any production warehouse setting currently names. Read
     * straight from app_settings rather than through the services so this
     * migration does not depend on the container being bootable at the point
     * it runs.
     *
     * @return list<int>
     */
    private function warehouseIdsNamedBySettings(): array
    {
        $keys = [
            FactoryDayBinService::SETTING_KEY,
            FactoryWarehouseResolver::SETTING_FINISHED_GOODS,
            FactoryWarehouseResolver::SETTING_RAW_MATERIAL,
        ];

        return AppSetting::query()
            ->whereIn('key', $keys)
            ->pluck('value')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }
};
