<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First delivery to the agent closes a shift voucher's merge window — a
 * payload the agent may already hold must never change under it. Stamped
 * by TallySyncService::pending(); re-polls of an unacked voucher still
 * return it (retry semantics unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tally_sync_entries', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('tally_sync_entries', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
