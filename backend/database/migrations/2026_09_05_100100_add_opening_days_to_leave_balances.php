<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a balance was CARRIED IN rather than granted here.
 *
 * One person opens on 47.5 casual days. That figure is the factory's own
 * history, not something this ERP allocated, and a screen that shows it as
 * an allocation invites somebody to "correct" it. `allocated_days` stays
 * what it always was — the total granted — and this records the part of that
 * total which was carried in, so accrued is the difference and the two
 * numbers cannot drift apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->decimal('opening_days', 5, 2)->default(0)->after('year');
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn('opening_days');
        });
    }
};
