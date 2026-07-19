<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_centers', function (Blueprint $table) {
            // Effective available hours per day (shifts/efficiency already
            // netted in) — null means capacity isn't configured yet, and
            // utilization simply can't be computed for that work center.
            $table->decimal('capacity_hours_per_day', 8, 2)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('work_centers', function (Blueprint $table) {
            $table->dropColumn('capacity_hours_per_day');
        });
    }
};
