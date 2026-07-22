<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mold_change_logs', function (Blueprint $table) {
            // Symmetric with changed_to_mold_id — "Changed From" tracks
            // which physical mold came out, not which item was being made.
            $table->foreignId('changed_from_mold_id')->nullable()->after('changed_from_item_id')->constrained('molds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mold_change_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('changed_from_mold_id');
        });
    }
};
