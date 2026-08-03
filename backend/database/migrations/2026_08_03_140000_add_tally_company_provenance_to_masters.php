<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Tally COMPANY each synced master came from.
 *
 * The incident this closes: on 23 July a masters pull ran against a different
 * Tally company and created six godowns and twenty stock items. They carried
 * valid Tally GUIDs, so every later safeguard treated them as genuine —
 * including the migration that retired the demo warehouses, which deliberately
 * skipped any row with a `tally_guid` on the reasoning that "Tally itself
 * vouches for the row". Tally did. A different company's Tally.
 *
 * Nothing in the schema recorded ownership, so the foreign rows were
 * indistinguishable from real ones, a handoff document then told the factory to
 * prefer them by name, and today's live batches issued real materials out of
 * another company's godowns.
 *
 * The endpoint guard added since (masters is refused from a company other than
 * the bound one) stops a REPEAT. It cannot identify what already arrived, and
 * it cannot help a query that needs to ask "whose is this row?". These columns
 * can, and they are what any future cleanup or audit reads.
 *
 * Nullable and backfilled by nothing: existing rows genuinely have unknown
 * provenance, and writing a company name onto them now would be inventing the
 * very fact this column exists to record. The sync fills it going forward; the
 * six known foreign rows are identified by GUID prefix in the meantime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('tally_company', 255)->nullable()->after('tally_guid');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->string('tally_company', 255)->nullable()->after('tally_stock_item_guid');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('tally_company');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('tally_company');
        });
    }
};
