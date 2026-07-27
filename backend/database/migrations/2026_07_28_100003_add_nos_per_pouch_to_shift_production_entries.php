<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pouch pack size the run actually used — snapshotted at Complete
 * Batch like nos_per_box, so approval history never rewrites itself
 * when the item master's pouch standard changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->unsignedInteger('nos_per_pouch')->nullable()->after('no_of_pouches');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropColumn('nos_per_pouch');
        });
    }
};
