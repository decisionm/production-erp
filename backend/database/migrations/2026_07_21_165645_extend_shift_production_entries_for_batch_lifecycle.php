<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // quantity_produced was NOT NULL under Phase 1's single-step "log
        // what already happened" flow. Phase 2's batch lifecycle creates a
        // row at "Start Batch" (machine + item only) before the quantity is
        // known, filled in at "Complete Batch" — see
        // PRODUCTION-SUPERVISOR-UX-PLAN.md §1.
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->decimal('quantity_produced', 15, 4)->nullable()->default(null)->change();
        });

        Schema::table('shift_production_entries', function (Blueprint $table) {
            // Batch lifecycle: in_progress from "Start Batch", completed at
            // "Complete Batch". Deliberately separate from `status` below —
            // this tracks whether the batch itself finished running, not
            // whether the accountant has approved it for Tally sync.
            $table->string('batch_status', 16)->default('in_progress')->after('quantity_produced');

            $table->string('batch_number', 64)->nullable()->after('batch_status');
            $table->decimal('quantity_produced_kg', 15, 4)->nullable()->after('quantity_produced');
            $table->decimal('quantity_rejection_kg', 15, 4)->nullable()->after('quantity_scrap');

            // Packing counts — Production Report's Nos/Tray, Trays, Nos/Box, Box columns.
            $table->unsignedInteger('nos_per_tray')->nullable();
            $table->unsignedInteger('no_of_trays')->nullable();
            $table->unsignedInteger('nos_per_box')->nullable();
            $table->unsignedInteger('no_of_box')->nullable();

            // Floor sign-offs — audit trail only for the supervisor (implicit
            // in who's logged in); Plant Manager review may become a real
            // gate, see master plan §10 open question #4.
            $table->foreignId('supervisor_signed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('supervisor_signed_at')->nullable();
            $table->foreignId('plant_manager_signed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('plant_manager_signed_at')->nullable();

            // Accountant approval gate before Tally sync — the real access
            // control checkpoint (§4a/§5 of the master plan).
            $table->string('status', 16)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });

        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->index(['work_center_id', 'batch_status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_signed_by');
            $table->dropConstrainedForeignId('plant_manager_signed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropIndex(['work_center_id', 'batch_status']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'batch_status', 'batch_number', 'quantity_produced_kg', 'quantity_rejection_kg',
                'nos_per_tray', 'no_of_trays', 'nos_per_box', 'no_of_box',
                'supervisor_signed_at', 'plant_manager_signed_at',
                'status', 'rejection_reason', 'approved_at',
            ]);
        });

        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->decimal('quantity_produced', 15, 4)->nullable(false)->change();
        });
    }
};
