<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column that finally says "this item may be issued to production".
 *
 * WHY A COLUMN AND NOT A LOOKUP. The audit of 18-Aug-2026 went looking for an
 * existing configuration to key this on and found three PARTIAL registries —
 * `bom_lines.component_item_id`, `packing_material_mappings.item_id` and
 * `masterbatch_dosings.masterbatch_item_id` — whose UNION still misses resin
 * entirely. Resin's only signal today is its unit of measure, and a
 * uom rule is disqualified from both sides: it excludes caps (`Nos.`, and a
 * genuine input) and it sweeps in kg-measured packing film without saying
 * whether either is an input at all. `items.item_group_id` could have carried
 * the answer natively but is write-only — nothing in the application reads it.
 *
 * This repository has made this exact move before and left the reason in the
 * code: Item.php's fixture check "used to be `str_starts_with($sku, 'LOCAL-')`,
 * which put a posting gate on a free-text field. THE COLUMN DECIDES, NOT THE
 * SKU." Eligibility for issue is the same kind of question, and the owner asked
 * for it in the same terms — configuration-driven, never names.
 *
 * DEFAULT FALSE, and the backfill that follows is what makes it true. A default
 * of true would have shipped the defect (every finished good requestable); the
 * backfill is separated into its own migration so it can be read, and reversed,
 * on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_production_input')->default(false)->after('is_active');

            // The requestable-materials picker filters on exactly this pair,
            // and the item master is ~644 rows on live and growing.
            $table->index(['is_production_input', 'is_active'], 'items_production_input_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_production_input_active_idx');
            $table->dropColumn('is_production_input');
        });
    }
};
