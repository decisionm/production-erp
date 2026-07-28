<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 traceability — one row per supplier lot on a GRN (design doc
 * "Data model"). Additive and inert: nothing reads these tables until
 * config('production.traceability_enabled') turns the feature on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_lots', function (Blueprint $table) {
            $table->id();
            // Nullable: the rollout backfills open stock as one "opening
            // lot" per material with no GRN behind it (design doc §Rollout 2).
            $table->foreignId('grn_id')->nullable()->constrained('goods_receipt_notes')->nullOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('supplier_lot_no', 100)->nullable();
            $table->date('received_date');
            $table->unsignedInteger('bag_count');
            // Nominal per-bag weight (Vincent Q1 — bag sizes are data, not
            // code); per-bag actuals live on material_bags.original_kg.
            $table->decimal('bag_weight_kg', 12, 4)->nullable();
            $table->decimal('total_received_kg', 15, 4);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // FIFO pick list: oldest received_date first, per item.
            $table->index(['item_id', 'received_date'], 'material_lots_item_received_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_lots');
    }
};
