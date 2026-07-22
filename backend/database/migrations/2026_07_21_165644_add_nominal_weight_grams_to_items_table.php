<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // The paper Production Report's "WT" column — weight of a single
            // unit in grams. Needed to derive Kg figures from unit counts
            // (quantity_produced_kg = quantity_produced * nominal_weight_grams
            // / 1000) and for the resin-wastage formula. Nullable: only
            // meaningful for finished-goods items (bottles), not raw
            // materials/masterbatch.
            $table->decimal('nominal_weight_grams', 10, 4)->nullable()->after('reorder_level');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('nominal_weight_grams');
        });
    }
};
