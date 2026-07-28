<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 shift continuity — a shift SEGMENT: when a run crosses the shift
 * boundary the outgoing entry is completed and a child entry opens with
 * parent_entry_id set, inheriting the batch number, product, mold-standard
 * snapshot and machine. batch_number stays the run's identity; segment =
 * entry row (design doc "Data model"). Nullable and unread until the
 * traceability flag turns handover on — existing entries are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->foreignId('parent_entry_id')->nullable()
                ->constrained('shift_production_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_entry_id');
        });
    }
};
