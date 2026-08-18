<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the items the factory has ALREADY treated as production inputs.
 *
 * Every source below is DATA — a configuration row the factory maintains, or a
 * transaction the factory actually posted. Not one of them is a name scan or a
 * SKU pattern, which is the whole point: the owner asked for a
 * configuration-driven rule, and a rule derived from what the factory has
 * configured and done is exactly that.
 *
 * The failure mode that matters here is UNDER-selecting. Over-selecting leaves
 * an extra row in a picker, which the owner can switch off on the item itself.
 * Under-selecting means the store cannot request something it needs — that
 * stops the floor. So the evidence is deliberately broad, and the two halves
 * do different jobs:
 *
 *   CONFIGURATION — what the factory has set up as an input:
 *     · bom_lines.component_item_id            a component of a bill of materials
 *     · packing_material_mappings.item_id      the carton/tray/film/tape register
 *     · masterbatch_dosings.masterbatch_item_id the colourant register
 *     · items with a kg-family uom             resin and masterbatch, whose only
 *                                              signal this database carries is
 *                                              the unit — kept ONLY as a
 *                                              backfill seed, never as the rule
 *
 *   HISTORY — what the factory has actually issued or consumed. This is the
 *   half that catches consumables no register covers (caps are `Nos.` and
 *   appear in none of the three registries):
 *     · material_request_lines.item_id
 *     · store_issue_lines.item_id
 *     · shift_material_consumptions.item_id
 *
 * Anything in NONE of these is the residue the factory has to answer for, and
 * it is left false rather than guessed at. Q56 records that, with the count.
 *
 * Deliberately NOT scoped to active items: an archived item that was a genuine
 * input stays marked, so reactivating it does not silently make it
 * unrequestable. Activeness is a separate filter at query time.
 */
return new class extends Migration
{
    /** table => column holding an item id that proves input use. */
    private const EVIDENCE = [
        'bom_lines' => 'component_item_id',
        'packing_material_mappings' => 'item_id',
        'masterbatch_dosings' => 'masterbatch_item_id',
        'material_request_lines' => 'item_id',
        'store_issue_lines' => 'item_id',
        'shift_material_consumptions' => 'item_id',
    ];

    /** Mirrors Item::KG_UOM_VARIANTS — resin's only native signal. */
    private const KG_UOM_VARIANTS = ['kg', 'kg.', 'kgs', 'kgs.'];

    public function up(): void
    {
        $ids = [];

        foreach (self::EVIDENCE as $table => $column) {
            // A table may legitimately not exist yet on an older database.
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $ids = array_merge($ids, DB::table($table)->whereNotNull($column)->distinct()->pluck($column)->all());
        }

        $kgIds = DB::table('items')
            ->whereIn(DB::raw('LOWER(TRIM(uom))'), self::KG_UOM_VARIANTS)
            ->pluck('id')
            ->all();

        $ids = array_values(array_unique(array_map('intval', array_merge($ids, $kgIds))));

        if ($ids === []) {
            return;
        }

        // ALL OR NOTHING. MySQL cannot roll back the ALTER TABLE in the
        // migration before this one, but this one is pure DML and must not be
        // able to leave HALF the master flagged: the request and issue paths
        // both refuse on this column, so a partial backfill is a store that
        // can hand over some materials and not others, with no way to tell
        // which from the outside.
        DB::transaction(function () use ($ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('items')->whereIn('id', $chunk)->update(['is_production_input' => true]);
            }
        });
    }

    /**
     * DELIBERATELY A NO-OP, and this is the safer choice rather than the lazy
     * one.
     *
     * The obvious `down()` — set every row back to false — is a ONE-COMMAND
     * FACTORY STOP. The column stays, both the request and the issue path keep
     * refusing on it, and `php artisan migrate:rollback` (the command an
     * operator reaches for under pressure, usually while something else is
     * already wrong) would leave a factory where nothing can be requested and
     * nothing can be issued. It would also destroy the owner's hand-curation
     * the moment Q56(a) is answered, which no rollback of a BACKFILL should be
     * able to do.
     *
     * Reversing the backfill means reversing what it was FOR, and that is the
     * migration before this one: dropping the column takes the enforcement
     * with it, because both rules reference it. Roll back both, or neither.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock.
    }
};
