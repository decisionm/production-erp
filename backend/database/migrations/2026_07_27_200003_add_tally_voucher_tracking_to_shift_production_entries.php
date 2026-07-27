<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            // Per-shift voucher membership (tally-sync.voucher_granularity =
            // 'shift'): set when this entry's quantities are aggregated into
            // a shift-level Stock Journal TallySyncEntry. A scalar FK — not a
            // pivot — because an entry may appear in exactly ONE voucher,
            // ever; the column being single-valued enforces that. Null under
            // the default 'batch' granularity (which keeps using the
            // tally_sync_entries morph instead).
            $table->foreignId('tally_sync_entry_id')
                ->nullable()
                ->constrained('tally_sync_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tally_sync_entry_id');
        });
    }
};
