<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The 490/box tray packing of 200ML RA — DEC-20260810-003, item 4.
 *
 * The 07-Aug paper packs 200ML RA at 98/tray × 5 trays = 490/box, and no
 * such packaging configuration existed. Created here as a proper variant on
 * every 200ML RA standard that does not already carry it, with the Tally
 * identity deliberately UNSET: which Tally item a 490 box posts as is Q33
 * (the candidate item's own name says "520 Nos"), and the owner answers it
 * in the packaging-identity edit UI — nobody hardcodes it, and until then
 * the variant falls back to the product's identity, labelled as such.
 *
 * Replay-safe by construction: a database with no 200ML RA standard (fresh
 * test databases, other instances) is a clean no-op — eligibility is checked
 * before anything is read or written, never asserted.
 */
return new class extends Migration
{
    /** Names this migration in the log, so a line is traceable to this file. */
    private const LABEL = '200ml_ra_490_tray_packaging';

    /** The key this migration matches on — defined ONCE, logged verbatim. */
    private const PRODUCT_NAME = '200ML RA';

    public function up(): void
    {
        $standards = DB::table('production_standards')
            ->whereNull('deleted_at')
            ->whereRaw('UPPER(TRIM(source_product_name)) = ?', [self::PRODUCT_NAME])
            ->get(['id']);

        // ABSENCE IS A NO-OP — but never a SILENT one.
        //
        // This migration ran on live on 11-Aug-2026 and did nothing, because
        // no standard is named '200ML RA': the live 200ML family is BRUTE /
        // DOME / KOREAN / ROUND. It reported that as success, and only a
        // hand-check of the database showed the 490 variant had not been
        // created. A data migration keyed on a NAME must say the name it
        // looked for and how many rows it matched, or "green" means nothing.
        if ($standards->isEmpty()) {
            Log::warning(sprintf(
                '%s: NO-OP — no production_standards row matched %s, so the '
                .'98/tray x 5 = 490/box variant was NOT created. If this factory '
                .'packs 490/box under another product name, add that packing on '
                .'the standard in Production Configuration ("Add a packing option") '
                .'— by a person, on the record. Do NOT re-key this migration to a '
                .'guessed name.',
                self::LABEL,
                self::PRODUCT_NAME,
            ));

            return;
        }

        Log::info(sprintf(
            '%s: %d standard(s) matched %s.',
            self::LABEL,
            $standards->count(),
            self::PRODUCT_NAME,
        ));

        $created = 0;
        $skipped = 0;

        foreach ($standards as $standard) {
            $exists = DB::table('production_standard_packagings')
                ->where('production_standard_id', $standard->id)
                ->where('mode', 'tray')
                ->where('nos_per_tray', 98)
                ->where('trays_per_box', 5)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            DB::table('production_standard_packagings')->insert([
                'production_standard_id' => $standard->id,
                'mode' => 'tray',
                'nos_per_tray' => 98,
                'trays_per_box' => 5,
                'nos_per_box' => 490,
                'is_default' => false,
                // The identity is Q33's to answer — unset, never guessed.
                'item_id' => null,
                'item_set_by' => null,
                'item_set_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        // What it actually did, in numbers — "ran successfully" is not a
        // result. A replay reads 0 created / N already present, which is the
        // idempotent case, not a failure.
        Log::info(sprintf(
            '%s: %d variant(s) created, %d already present.',
            self::LABEL,
            $created,
            $skipped,
        ));
    }

    public function down(): void
    {
        // Only the exact rows this migration could have inserted, and only
        // while untouched: a row whose identity someone has since set is a
        // configured fact and stays.
        DB::table('production_standard_packagings')
            ->where('mode', 'tray')
            ->where('nos_per_tray', 98)
            ->where('trays_per_box', 5)
            ->where('nos_per_box', 490)
            ->whereNull('item_id')
            ->whereIn('production_standard_id', DB::table('production_standards')
                ->whereRaw("UPPER(TRIM(source_product_name)) = '200ML RA'")
                ->pluck('id'))
            ->delete();
    }
};
