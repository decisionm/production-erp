<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * goods_receipt_note_lines.stock_movement_id (Phase 6, P6-02) — the LEDGER
 * ROW THIS RECEIPT LINE WROTE, said once instead of guessed forever.
 *
 * The inventory ledger has never carried a GRN foreign key: a receipt's
 * movements were found again by the reference GoodsReceiptService stamped
 * on them — the receipt's own `reference`, or the fallback
 * "GRN for PO #{id}" when it had none. Two arrivals on ONE order with no
 * reference therefore share one string, and the purchase-order trace showed
 * BOTH movements under BOTH receipts (30 and 20 each read as [30, 20]).
 *
 * Additive and nullable, so nothing existing changes meaning: rows booked
 * before this column stay NULL and are still resolved the old way (the
 * trace says which road it took — `match: 'by_id' | 'by_reference'`).
 * Nothing is back-filled: the reference walk is the only evidence a
 * historical row has, and inventing an id for it would be a guess.
 * nullOnDelete keeps a receipt line readable even if its movement were
 * ever removed. Reversible: down() drops the column and its constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('goods_receipt_note_lines', 'stock_movement_id')) {
            return;
        }

        Schema::table('goods_receipt_note_lines', function (Blueprint $table) {
            $table->foreignId('stock_movement_id')->nullable()->after('item_id')
                ->constrained('stock_movements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('goods_receipt_note_lines', 'stock_movement_id')) {
            return;
        }

        Schema::table('goods_receipt_note_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_movement_id');
        });
    }
};
