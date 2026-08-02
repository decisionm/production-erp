<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE COMMON RESIN POOL — one running weighted average per EXACT resin item.
 *
 * The owner's correction (2-Aug): the factory has one common resin input
 * point for all machines. A bag is never assigned to a machine or to a
 * batch, so "which bag did this batch burn" is a question with no physical
 * answer and this module stopped pretending to answer it. What replaced it
 * is an ACCOUNTING ALLOCATION: every kg loaded into the common input joins a
 * pool, the pool carries one weighted-average rate, and a batch's
 * consumption is drawn from that pool at that average.
 *
 * PER EXACT ITEM, NEVER ACROSS MATERIALS. There is one row per item_id and
 * item_id is unique, so PET resin and PP resin — and two grades of the same
 * polymer, which are two items — each carry their own average. Blending
 * different materials into one number would produce a rate that prices
 * nothing that exists.
 *
 * THE THREE FIGURES:
 *
 *   quantity_kg      the PRICED pool: kg standing in the common input whose
 *                    rate is known. Grows on load, shrinks on allocation,
 *                    grows again when an amendment gives kg back.
 *   avg_rate_per_kg  the weighted average of exactly those kg. Folded on
 *                    load with the same moving-average arithmetic
 *                    StockMovementService::incrementBalance uses, so the two
 *                    valuations are the same shape even though they are
 *                    deliberately separate mechanisms.
 *   unpriced_kg      kg folded in from a lot with NO recorded rate (opening
 *                    stock, the commonest case on day one). It is counted
 *                    HERE and kept OUT of the average, because averaging in
 *                    a rate nobody knows silently prices it at whatever the
 *                    rest of the pool cost. A running total of unpriced
 *                    material, not a drawable balance: material with no rate
 *                    cannot be drawn AT a rate, so consumption it should
 *                    have covered falls to the labelled stock-average
 *                    fallback instead, which is the honest answer and the
 *                    one that tells finance to go and look.
 *
 * IT DOES NOT TOUCH THE STOCK LEDGER. stock_balances.average_cost and the
 * Accounts-approved Tally valuation are exactly as they were; bag and pool
 * rates never reach Tally, whose voucher lines carry quantities only. Same
 * boundary batch_resin_allocations has always kept — see that migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resin_pool_balances', function (Blueprint $table) {
            $table->id();

            // UNIQUE: one pool per exact material. The uniqueness is the
            // rule "never average across different materials", written where
            // the database can enforce it rather than left to a service.
            $table->foreignId('item_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('quantity_kg', 15, 4)->default(0);
            $table->decimal('avg_rate_per_kg', 15, 4)->default(0);
            $table->decimal('unpriced_kg', 15, 4)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resin_pool_balances');
    }
};
