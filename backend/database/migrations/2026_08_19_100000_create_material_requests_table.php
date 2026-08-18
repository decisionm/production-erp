<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE PRODUCTION MATERIAL REQUEST — "the floor asks the store for material".
 *
 * Additive and reversible: two new tables, nothing altered, nothing
 * back-filled. The Day Bin's own table and rows are not touched by this
 * migration or by anything that reads these tables.
 *
 * Header columns only; the material itself is on material_request_lines.
 *
 * work_center_id is NULLABLE ON PURPOSE and is REFUSED for a common-input
 * item (FC-01 / DEC-20260807-006: one crane-fed loading point piped to all
 * ten machines — a resin bag belongs to no machine and no batch). The
 * refusal lives in MaterialRequestService::guardCommonInputNamesNoMachine,
 * because a nullable column cannot express "null unless…".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            // draft | submitted | partially_issued | issued | cancelled
            $table->string('status', 32)->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            // NULL for a common-input (resin/masterbatch) request — see the
            // class docblock above and the service's refusal.
            $table->foreignId('work_center_id')->nullable()->constrained('work_centers')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();

            $table->timestamps();

            // The store queue's own filters: status + date, and the two
            // dimensions the floor narrows by.
            $table->index(['status', 'requested_at']);
            $table->index('shift_id');
            $table->index('work_center_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
