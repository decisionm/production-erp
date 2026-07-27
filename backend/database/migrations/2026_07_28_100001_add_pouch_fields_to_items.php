<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Pouch packing standards (Wave A packaging) — some products are
            // packed in pouches instead of trays before going into the
            // universal outer box. Nullable and strictly additive like the
            // tray/box standards above them: an item with no pouch standard
            // keeps today's tray+box behaviour byte-for-byte.
            $table->unsignedInteger('nos_per_pouch')->nullable()->after('nos_per_box');
            $table->unsignedInteger('pouches_per_box')->nullable()->after('nos_per_pouch');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['nos_per_pouch', 'pouches_per_box']);
        });
    }
};
