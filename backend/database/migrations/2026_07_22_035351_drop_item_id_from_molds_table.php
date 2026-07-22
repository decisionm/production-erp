<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('molds', function (Blueprint $table) {
            // A single mold is reused across colour variants of the same
            // item (each colour being its own Item record), so a fixed
            // one-to-one mold->item mapping doesn't hold. Which item a
            // mold change produces is picked explicitly on the log itself
            // (mold_change_logs.changed_to_item_id), not derived from the
            // mold.
            $table->dropConstrainedForeignId('item_id');
        });
    }

    public function down(): void
    {
        Schema::table('molds', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
        });
    }
};
