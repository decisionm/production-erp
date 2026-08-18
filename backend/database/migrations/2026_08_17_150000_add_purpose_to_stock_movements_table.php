<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stock_movements.purpose — WHY a movement happened, beside `type`, which
 * only says which way the quantity went (Phase 5, P5-05; enum
 * StockMovementPurpose: opening | receipt | consumption | output | dispatch
 * | adjustment | reconcile | unknown).
 *
 * Additive and nullable: the column arrives empty on every existing row and
 * on nothing else. StockMovementService stamps every NEW movement from the
 * moment this runs ('unknown' unless the caller says otherwise); the
 * companion backfill (2026_08_17_150001) fills the historical rows from the
 * reference shapes the code itself generates, and only those. Kept as a
 * plain string with the enum enforced in PHP, exactly like `type` — a DB
 * enum would turn every future purpose into a schema change.
 *
 * Indexed on its own: the reads this exists for group and filter the ledger
 * by purpose ("every consumption in August"), across items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('purpose', 32)->nullable()->after('type');
            $table->index('purpose', 'stock_movements_purpose_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_purpose_index');
            $table->dropColumn('purpose');
        });
    }
};
