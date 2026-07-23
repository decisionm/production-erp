<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Masters flow Tally -> ERP (TALLY-SYNC-MASTER-PLAN.md §3): Tally is
            // the source of truth for item existence/naming. Match on Tally's
            // stable GUID rather than name, so a rename in Tally doesn't orphan
            // the ERP item. Nullable + unique: ERP-only items keep a null GUID
            // (many nulls are allowed in a MySQL unique index).
            $table->string('tally_stock_item_guid')->nullable()->unique()->after('sku');
            // Tally's monotonic per-master change counter — retained so a future
            // incremental pull can request only what changed since the last seen
            // AlterID instead of re-pulling all items every cycle (§3).
            $table->unsignedBigInteger('tally_alter_id')->nullable()->after('tally_stock_item_guid');
            $table->timestamp('tally_synced_at')->nullable()->after('tally_alter_id');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['tally_stock_item_guid']);
            $table->dropColumn(['tally_stock_item_guid', 'tally_alter_id', 'tally_synced_at']);
        });
    }
};
