<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE BAG-COST ANALYTIC LAYER — which bag-load layers a batch's resin
 * consumption drew from, and at what preserved rate.
 *
 * IT SITS BESIDE THE STOCK LEDGER, NEVER INSIDE IT. StockMovementService
 * and stock_balances.average_cost are untouched by anything that writes
 * here: completeBatch still books stock exactly as it did before this
 * table existed, and the Accounts-approved Tally valuation (moving average
 * on the stock ledger) is not affected in any way. Bag rates never reach
 * Tally — its voucher lines carry quantities only. This table answers a
 * different question ("what did THIS batch's resin actually cost, at the
 * rate the bag was bought at") and answering it must never perturb the
 * answer the accountant already approved.
 *
 * APPEND-ONLY, BY CONSTRUCTION. A correction NEVER deletes rows. It stamps
 * reversed_at on the run that was wrong and writes a fresh run beside it —
 * so the allocation history reads as "we thought X, then we corrected to
 * Y", which is exactly what an auditor asks for. allocation_run numbers
 * those attempts per entry (1, 2, 3…); at most ONE run per entry may be
 * un-reversed at any moment, and BagCostAllocationService enforces it.
 *
 * WHY EVERY IDENTITY COLUMN IS NULLABLE:
 *   - day_bin_movement_id null = a beyond-layers fallback row. The batch
 *     consumed more than the machine's scanned loads can account for, and
 *     that surplus is priced at the stock average instead of at a bag.
 *   - material_bag_id / material_lot_id null = the Load row carried no bag
 *     (a bin-bay load recorded without a barcode), or the bag's lot is
 *     gone. The layer is still real and its quantity still counts.
 *   - rate_per_kg / amount null = NO PRICE IS KNOWN. Opening-stock lots
 *     have no cost source at all (material_lots.goods_receipt_note_line_id
 *     is nullable), and a lot with no rate is recorded as rate_source
 *     'unknown' with a null rate rather than being handed an invented
 *     number. A guessed price is worse than an absent one: the absent one
 *     tells the accountant to go and look.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_resin_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shift_production_entry_id')
                ->constrained()
                ->cascadeOnDelete();

            // 1, 2, 3… per entry. Not a row count — a run may write several
            // rows (one per layer drawn), so the next run is
            // max(allocation_run) + 1.
            $table->unsignedInteger('allocation_run');

            // The Load layer this slice was drawn from. Null = beyond-layers
            // fallback (see the docblock above).
            $table->foreignId('day_bin_movement_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('material_bag_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('material_lot_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // The item the slice is of — carried explicitly so a cost read
            // never has to join back through the Load row to know what was
            // consumed (and so a fallback row, which has no Load row at all,
            // still names its material).
            $table->foreignId('item_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('quantity_kg', 12, 4);

            // THE PRESERVED RATE. Copied at allocation time, never re-read
            // later: a GRN cost is PROVISIONAL until purchase-invoice and
            // landed-cost adjustments land, and a closed batch must never be
            // silently rewritten when they do. A revised rate produces a new
            // audited version, it does not edit this row.
            $table->decimal('rate_per_kg', 15, 4)->nullable();
            $table->decimal('amount', 15, 4)->nullable();

            // 'bag_receipt' | 'bag_version' | 'average_fallback' | 'unknown'
            $table->string('rate_source', 30);

            $table->dateTime('reversed_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // created_at only — a row is written once and never updated
            // except to be stamped reversed_at, which is its own column.
            $table->timestamp('created_at')->nullable();

            // The two hot reads, both named explicitly rather than left to
            // Laravel's generator: "this entry's live rows" (summary and the
            // one-live-run guard) and "everything ever drawn against this
            // layer" (the FIFO remaining calculation, which spans ALL
            // entries).
            $table->index(['shift_production_entry_id', 'reversed_at'], 'bra_entry_live_idx');
            $table->index(['day_bin_movement_id', 'reversed_at'], 'bra_layer_live_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_resin_allocations');
    }
};
