<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Molding standards for the expected-output engine
            // (docs/archive/SHIFT-REDESIGN-FORMULAS.md #22/#23): EST BOX needs cycle time
            // and cavity count as item master data. All nullable — they only
            // apply to molded finished goods and arrive as a data load.
            $table->string('colour', 32)->nullable()->after('nos_per_box');
            // Seconds per shot (WB2 CT column) — decimal, e.g. 10.60.
            $table->decimal('standard_cycle_time', 6, 2)->nullable()->after('colour');
            // Pieces per shot (WB2 CAVITY column).
            $table->unsignedInteger('standard_cavities')->nullable()->after('standard_cycle_time');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['colour', 'standard_cycle_time', 'standard_cavities']);
        });
    }
};
