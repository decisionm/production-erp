<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mold_change_logs', function (Blueprint $table) {
            // Which physical mold went in, not just which item came out —
            // an item can have more than one mold (a backup set), and mold
            // identity is what a Mold Management page/wear-tracking needs.
            // changed_to_item_id stays (derived from the mold at log time)
            // so item-based reporting keeps working unchanged.
            $table->foreignId('changed_to_mold_id')->nullable()->after('changed_to_item_id')->constrained('molds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mold_change_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('changed_to_mold_id');
        });
    }
};
