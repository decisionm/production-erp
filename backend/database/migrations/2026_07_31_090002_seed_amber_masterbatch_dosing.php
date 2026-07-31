<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ONE dosing figure the factory has actually given: Master Batch Amber
 * at 0.25 grams per bottle (owner, 31-Jul: "for master amber 0.25 is the
 * value per bottle").
 *
 * A data migration, not a seeder, because database/seeders is not part of the
 * live deploy path for an existing instance — this row has to arrive with a
 * `php artisan migrate` on a server whose items are already there. Precedent:
 * 2026_07_28_200005_create_production_override_fifo_permission.php.
 *
 * DELIBERATELY ONLY AMBER. The other two masterbatches ("Master Batch - Pet
 * White", "Masterbatch -Red(Brown)") are left UNSET, and unset means "no
 * prefill" — copying 0.25 across them would be inventing two factory figures
 * from one, which is precisely what the owner forbade. They get their rows
 * when the factory says a number, through the API, with their own note.
 *
 * Matches on a NORMALISED name (case-folded, non-alphanumerics stripped), so
 * "Master Batch Amber", "Masterbatch Amber" and "Master Batch - Amber" all
 * hit and "Master Batch Amber 500" does not. No match = no row and no error:
 * a fresh test database has no items at migration time, and an instance that
 * spells the item differently must be able to enter the figure in the app
 * rather than have a migration guess for it.
 */
return new class extends Migration
{
    /** The factory's own wording, and the date they said it. */
    private const NOTE = 'Factory (owner, 31 Jul 2026): amber masterbatch dosing is 0.25 g per bottle. '
        .'Seeded for Master Batch Amber only — no other masterbatch has been given a figure.';

    public function up(): void
    {
        $itemId = $this->amberMasterbatchId();

        if ($itemId === null) {
            return;
        }

        $exists = DB::table('masterbatch_dosings')
            ->where('masterbatch_item_id', $itemId)
            ->whereNull('product_item_id')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('masterbatch_dosings')->insert([
            'masterbatch_item_id' => $itemId,
            'product_item_id' => null,
            'grams_per_bottle' => '0.2500',
            'note' => self::NOTE,
            // No set_by: nobody set this one in the app. A user id here would
            // claim a person entered a figure that arrived with a deploy.
            'set_by' => null,
            'set_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $itemId = $this->amberMasterbatchId();

        if ($itemId === null) {
            return;
        }

        DB::table('masterbatch_dosings')
            ->where('masterbatch_item_id', $itemId)
            ->whereNull('product_item_id')
            ->where('note', self::NOTE)
            ->delete();
    }

    /**
     * The amber masterbatch item, or null. Normalising in PHP rather than in
     * SQL keeps the match identical on MySQL and SQLite — LOWER()/collation
     * behaviour differs between them, and this must not be a figure that
     * appears on one engine and not the other.
     *
     * Lowest id on a tie: two items normalising the same way is a master-data
     * duplicate, and an arbitrary pick would put the factory's figure on a
     * different item depending on row order.
     */
    private function amberMasterbatchId(): ?int
    {
        $rows = DB::table('items')
            ->select('id', 'name')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($this->normalise((string) $row->name) === 'masterbatchamber') {
                return (int) $row->id;
            }
        }

        return null;
    }

    private function normalise(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }
};
