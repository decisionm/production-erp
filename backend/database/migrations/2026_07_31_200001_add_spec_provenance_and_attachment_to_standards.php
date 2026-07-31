<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the standards page needs to say out loud, and one thing a
 * re-import must not be able to forget.
 *
 * `spec_provenance` — WHERE a packing-material spec came from, per column.
 * The workbook leaves carton/tray/pouch blank on some rows and the factory
 * fills those blanks by knowing the family ("375ML KIDNEY packs in the same
 * carton as the 500ML KIDNEY"). Writing that inference into the value itself
 * — "HM 30.5*49 (inferred)" — would corrupt the string the packing-materials
 * build is about to consume, so the inference is recorded ALONGSIDE the value
 * instead:
 *
 *   {"carton_spec": {"inferred": true, "value": "HM 30.5*49",
 *                    "from_source_reference": "58", "from_product": "500ML KIDNEY",
 *                    "reason": "...", "inferred_by": "...", "inferred_on": "2026-07-31"}}
 *
 * Keyed by column so the page can mark exactly the cell that was guessed and
 * leave the two the sheet actually states alone. A column absent from the map
 * came from the workbook verbatim.
 *
 * `item_attached_by` / `item_attached_at` — the person who attached a Tally
 * item to a standard from the app, and when. Deliberately NOT folded into
 * `confirmation_status`: that column is string(32), already carries the
 * importer's own text, and — decisively — the importer REWRITES it on every
 * re-import. A marker that a re-import erases is a marker that stops
 * protecting the row it was written to protect, and the import's
 * reverse-adoption guard needs a predicate that survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_standards', function (Blueprint $table) {
            if (! Schema::hasColumn('production_standards', 'spec_provenance')) {
                $table->json('spec_provenance')->nullable()->after('pouch_spec');
            }
        });

        Schema::table('production_standards', function (Blueprint $table) {
            if (! Schema::hasColumn('production_standards', 'item_attached_by')) {
                $table->foreignId('item_attached_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('production_standards', 'item_attached_at')) {
                $table->timestamp('item_attached_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_standards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_attached_by');
            $table->dropColumn(['spec_provenance', 'item_attached_at']);
        });
    }
};
