<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The factory master's three right-hand packaging-material columns
 * (M CARTON, N TRAY, O POUCH).
 *
 * These are SPECS, not counts: "750*610" is a pouch film in millimetres and
 * "60ML" is a tray size, so none of them can produce a pieces-per-container
 * figure. They live on the standard rather than on a packaging row for exactly
 * that reason — the pouch film size is recorded on 73 of 103 source rows,
 * including 44 that carry no bottles-per-pouch count at all, so tying it to a
 * pouch packaging option would either invent modes that cannot be estimated or
 * throw the spec away.
 *
 * Free text, deliberately. The sheet spells the same film six ways
 * ("750*610", "750 X 610", "750X 610"); normalising them here would be a
 * guess about which spellings mean the same material, and the value's only job
 * today is to be shown to a supervisor who already knows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_standards', function (Blueprint $table) {
            $table->string('carton_spec', 64)->nullable()->after('cycle_time_raw');
            $table->string('tray_spec', 64)->nullable()->after('carton_spec');
            $table->string('pouch_spec', 64)->nullable()->after('tray_spec');
        });
    }

    public function down(): void
    {
        Schema::table('production_standards', function (Blueprint $table) {
            $table->dropColumn(['carton_spec', 'tray_spec', 'pouch_spec']);
        });
    }
};
