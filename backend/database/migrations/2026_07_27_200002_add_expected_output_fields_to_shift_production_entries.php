<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            // Expected-output engine inputs (docs/archive/SHIFT-REDESIGN-FORMULAS.md
            // #22-24, #30). standard_* are SNAPSHOTS copied from the item at
            // Start Batch — the standard the shift actually ran against, kept
            // even if the item master changes later. Never writable through
            // any request after start.
            $table->decimal('standard_cycle_time', 6, 2)->nullable()->after('helper_name');
            // What the machine actually ran at — editable at start/completion.
            $table->decimal('actual_cycle_time', 6, 2)->nullable()->after('standard_cycle_time');
            $table->unsignedInteger('standard_cavities')->nullable()->after('actual_cycle_time');
            // Cavities actually open this run (blocked cavities happen) —
            // defaults to standard_cavities at Start Batch, editable.
            $table->unsignedInteger('active_cavities')->nullable()->after('standard_cavities');
            // WB2 col V — 8 = full shift, partials 2/4/6; entered at Complete.
            $table->decimal('running_hours', 4, 2)->nullable()->after('active_cavities');
            // WB2 col Q — QC's weighed rejection, entered at/after completion.
            $table->decimal('qc_rejection_kg', 12, 4)->nullable()->after('running_hours');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropColumn([
                'standard_cycle_time', 'actual_cycle_time',
                'standard_cavities', 'active_cavities',
                'running_hours', 'qc_rejection_kg',
            ]);
        });
    }
};
