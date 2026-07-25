<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            // The 4-stage approval chain (factory answer 9): Supervisor submits →
            // Plant Manager verifies → Accountant reconciles → MD final approval →
            // Tally. 'accountant_approved' (19 chars) outgrows the original
            // varchar(16).
            $table->string('status', 32)->default('pending')->change();

            // The accountant's sign-off. PM already has plant_manager_signed_by/at;
            // approved_by/approved_at become the MD's final approval.
            $table->foreignId('accountant_signed_by')->nullable()->after('plant_manager_signed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('accountant_signed_at')->nullable()->after('accountant_signed_by');
        });

        // plant_manager_signed_by was created as an audit-trail-only pointer to
        // EMPLOYEES (never wired, always NULL). Now the PM approval is a real
        // app action by a logged-in USER — repoint the FK.
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plant_manager_signed_by');
        });
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->foreignId('plant_manager_signed_by')->nullable()->after('supervisor_signed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accountant_signed_by');
            $table->dropColumn('accountant_signed_at');
            $table->string('status', 16)->default('pending')->change();
        });
    }
};
