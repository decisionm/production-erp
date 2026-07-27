<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Product packing master — the item-level standards behind the
            // shift entry's Nos/Tray and Nos/Box columns. Used by the
            // frontend to prefill Complete Batch (always editable there);
            // nullable because the standards arrive later as a data load and
            // only apply to finished goods packed in trays/boxes.
            $table->unsignedInteger('nos_per_tray')->nullable()->after('nominal_weight_grams');
            $table->unsignedInteger('trays_per_box')->nullable()->after('nos_per_tray');
            // For products packed straight into boxes without trays.
            $table->unsignedInteger('nos_per_box')->nullable()->after('trays_per_box');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['nos_per_tray', 'trays_per_box', 'nos_per_box']);
        });
    }
};
