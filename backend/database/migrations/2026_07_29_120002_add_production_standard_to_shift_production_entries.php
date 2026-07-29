<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which product standard and packaging a run actually used.
 *
 * Recorded even though no machine-product mapping is approved yet: this is
 * the evidence that later BECOMES the approved machine-product assignment.
 * After a week of real shifts the factory can see "Machine 4 ran this
 * standard 23 times" and approve it from fact rather than from memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->foreignId('production_standard_id')->nullable()->after('production_configuration_id')
                ->constrained('production_standards')->nullOnDelete();
            // Explicit FK name: the generated
            // shift_production_entries_production_standard_packaging_id_foreign
            // is 65 characters, one over MySQL's identifier limit.
            $table->foreignId('production_standard_packaging_id')->nullable()->after('production_standard_id')
                ->constrained('production_standard_packagings', indexName: 'spe_standard_packaging_foreign')
                ->nullOnDelete();
            $table->string('packaging_mode', 16)->nullable()->after('production_standard_packaging_id');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_standard_id');
            $table->dropForeign('spe_standard_packaging_foreign');
            $table->dropColumn('production_standard_packaging_id');
            $table->dropColumn('packaging_mode');
        });
    }
};
