<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable per-batch configuration snapshot.
 *
 * calculation_version is the load-bearing column: it records WHICH formula
 * set produced this entry's figures. Existing rows stay null and are read
 * as legacy_v1 (the unfloored WB2 formula) forever — approved history must
 * never be recalculated by a later engine change. New batches stamp
 * production_v2_floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->foreignId('production_configuration_id')->nullable()->after('item_id')
                ->constrained('production_configurations')->nullOnDelete();

            // Null on every pre-existing row = legacy_v1. Never backfilled:
            // stamping a version onto history would assert that those
            // figures came from a formula that did not exist when they were
            // approved.
            $table->string('calculation_version', 32)->nullable()->after('production_configuration_id');

            // Everything the engine resolved at Start, frozen. A later
            // master edit cannot move a historical number.
            $table->json('config_snapshot')->nullable()->after('calculation_version');

            // configuration | override | item_master — where the effective
            // value came from, so approval can show default vs effective.
            $table->string('cycle_time_source', 16)->nullable()->after('config_snapshot');
            $table->string('cavities_source', 16)->nullable()->after('cycle_time_source');
            $table->text('override_reason')->nullable()->after('cavities_source');
            $table->foreignId('override_by')->nullable()->after('override_reason')
                ->constrained('users')->nullOnDelete();

            $table->decimal('planned_downtime_minutes', 8, 2)->nullable()->after('override_by');
            $table->decimal('scheduled_hours', 6, 2)->nullable()->after('planned_downtime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_configuration_id');
            $table->dropConstrainedForeignId('override_by');
            $table->dropColumn([
                'calculation_version', 'config_snapshot', 'cycle_time_source',
                'cavities_source', 'override_reason', 'planned_downtime_minutes',
                'scheduled_hours',
            ]);
        });
    }
};
