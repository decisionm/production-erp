<?php

use App\Modules\Production\Services\PackingItemMatcher;
use App\Modules\Production\Services\PackingMaterialMappingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the packing-material mappings this instance's own catalogue can prove.
 *
 * A data migration, not a seeder, because database/seeders is not part of the
 * live deploy path for an existing instance — these rows have to arrive with
 * a `php artisan migrate` on a server whose items and standards are already
 * there. Precedent: 2026_07_31_090002_seed_amber_masterbatch_dosing.php.
 *
 * SEEDS BY EVIDENCE, NOT BY ASSUMPTION. Not one of this factory's eighteen
 * packing item names exists in any migration, seeder or test in this repo —
 * they live only in the live Tally catalogue. So nothing is hardcoded: the
 * spec strings come from `production_standards`, the items come from the
 * `items` table as it stands at migration time, and a spec whose item is
 * absent or ambiguous gets NO row and is written to the log instead.
 *
 * A miss is the deliverable, not a failure. "500ML" names three trays in this
 * catalogue; "300ML ROUND" names no carton at all; five of the workbook's
 * pouch-film strings are millimetre dimensions no film item carries. Each of
 * those is a question for the factory, answerable through
 * POST /api/v1/production/packing-material-mappings, and a plausible guess
 * written here would hide it.
 *
 * Idempotent and non-destructive: a row already present — including one a
 * person has since edited or withdrawn — is left exactly as it is.
 *
 * The matching, the reasons and the tape pairings live in
 * {@see PackingItemMatcher} and
 * {@see PackingMaterialMappingService::seedFromCatalogue()}; this migration
 * is a caller, so the same rule can be run — and tested — twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so this file is a no-op rather than a fatal on any database
        // where the table migration above has not landed.
        if (! Schema::hasTable('packing_material_mappings') || ! Schema::hasTable('production_standards')) {
            return;
        }

        $result = app(PackingMaterialMappingService::class)->seedFromCatalogue();

        Log::info(sprintf(
            'packing_material_mappings seed: %d mapped, %d left for the factory to answer, %d already present.',
            count($result['seeded']),
            count($result['missed']),
            count($result['kept']),
        ));

        foreach ($result['seeded'] as $row) {
            Log::info(sprintf(
                'packing_material_mappings seed: %s "%s" -> "%s" (%s).',
                $row['kind'],
                $row['spec'],
                $row['item'],
                $row['reason'],
            ));
        }

        // The misses are the point of the log. Each line is a question a
        // person can act on, named specifically enough to act on it.
        foreach ($result['missed'] as $row) {
            Log::warning(sprintf(
                'packing_material_mappings seed: %s "%s" NOT mapped — %s. Answer it with '
                .'POST /api/v1/production/packing-material-mappings.',
                $row['kind'],
                $row['spec'],
                $row['reason'],
            ));
        }
    }

    public function down(): void
    {
        // Deliberately empty. The rows this seeded are indistinguishable, on
        // a live instance, from the ones a person has corrected since — and
        // deleting a mapping the factory confirmed would silently blank the
        // floor's prefills. Dropping the table (the migration above) is the
        // honest way to undo this; removing individual answers is a decision
        // for a person.
    }
};
