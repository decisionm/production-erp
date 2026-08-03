<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelling a batch that should never have been started.
 *
 * Until now a started batch had exactly two ends: it completed, or it stayed
 * running forever. That is right for real production — a shift that happened
 * cannot be un-happened — but it left no way to undo a batch started by
 * mistake, and a mistaken batch holds its machine hostage: the start guard
 * refuses a second batch while one is `in_progress`, so a demo run on Machine
 * 1 blocks Machine 1 until somebody edits the database by hand.
 *
 * These three columns are the audit record, not a flag. A cancellation that
 * did not say who did it and why would be indistinguishable from data loss —
 * the row would simply stop appearing in the queues with nothing to explain
 * it. The batch itself is never deleted: its number, its machine, its shift
 * and its start time all remain, which is what makes this safe to expose in
 * the UI at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('approved_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
