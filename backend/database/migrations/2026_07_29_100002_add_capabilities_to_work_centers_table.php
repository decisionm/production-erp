<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Machine capabilities. Additive columns on work_centers rather than a
 * side table: a capability IS an attribute of the machine, and every
 * existing row keeps working with all of them null (= "no limit known",
 * which is the honest state for all ten machines today — the master
 * workbook leaves every cavity field empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_centers', function (Blueprint $table) {
            $table->string('capacity_class', 32)->nullable()->after('name');
            $table->unsignedSmallInteger('min_cavities')->nullable()->after('capacity_class');
            $table->unsignedSmallInteger('max_cavities')->nullable()->after('min_cavities');
            // Explicit permitted set for machines whose cavity options are
            // NOT a continuous range (the factory describes Machine 10 as
            // "around 6/7/8" — a set, not a range). Null = fall back to
            // min/max; both null = unknown, and unknown never blocks.
            $table->json('permitted_cavities')->nullable()->after('max_cavities');
            $table->decimal('cycle_time_min', 8, 2)->nullable()->after('permitted_cavities');
            $table->decimal('cycle_time_max', 8, 2)->nullable()->after('cycle_time_min');
            $table->decimal('default_shift_hours', 5, 2)->nullable()->after('cycle_time_max');
            $table->string('confirmation_status', 32)->nullable()->after('default_shift_hours');
        });
    }

    public function down(): void
    {
        Schema::table('work_centers', function (Blueprint $table) {
            $table->dropColumn([
                'capacity_class', 'min_cavities', 'max_cavities', 'permitted_cavities',
                'cycle_time_min', 'cycle_time_max', 'default_shift_hours', 'confirmation_status',
            ]);
        });
    }
};
