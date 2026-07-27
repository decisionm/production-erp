<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            // Wave A packaging: pouch count for pouch-packed products, and
            // loose pieces left over after filling whole containers —
            // previously a frontend-only derivation helper, now persisted so
            // the approval screen and Tally reconciliation see the same
            // packing picture the floor entered. Both nullable: entries
            // without them behave exactly as before.
            $table->unsignedInteger('no_of_pouches')->nullable()->after('no_of_box');
            $table->unsignedInteger('loose_pieces')->nullable()->after('no_of_pouches');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropColumn(['no_of_pouches', 'loose_pieces']);
        });
    }
};
