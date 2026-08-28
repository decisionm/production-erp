<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * goods_receipt_notes.tally_staging — what the Tally side made of this
 * receipt, recorded where the receiving desk reads it. The exact shape
 * purchase_orders.tally_staging already carries (state / reasons / at /
 * entry_id): disabled (the flag is off; PENDING Q63) · refused (named
 * reasons — an unmapped item, an unmapped vendor ledger, no allowed
 * company) · enqueued (entry_id). Written only by
 * GoodsReceiptService::recordTallyStaging(); NULL on every receipt that
 * predates this column, and the page words that as "recorded before
 * staging existed" rather than inventing a state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->json('tally_staging')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropColumn('tally_staging');
        });
    }
};
