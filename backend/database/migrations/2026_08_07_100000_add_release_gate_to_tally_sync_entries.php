<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shift-voucher release gate (DEC-20260807-011): a shift voucher is
 * offered to the agent only once its shift has ended AND it has sat quiet
 * for the configured idle-hold — or the accountant released it by hand.
 *
 * last_merged_at — the idle-hold clock: stamped when a shift voucher is
 * created and every time a later approval merges in and the payload is
 * rebuilt. released_at / released_by — the manual override, persisted so
 * who released what stays auditable. All three stay null on batch-mode
 * vouchers, which are never held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tally_sync_entries', function (Blueprint $table) {
            $table->timestamp('last_merged_at')->nullable()->after('delivered_at');
            $table->timestamp('released_at')->nullable()->after('last_merged_at');
            $table->foreignId('released_by')->nullable()->after('released_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tally_sync_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn(['last_merged_at', 'released_at']);
        });
    }
};
