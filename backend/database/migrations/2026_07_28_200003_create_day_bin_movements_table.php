<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 traceability — the shift-side ledger per machine per material:
 * load (bag scan into the day bin), return (material back out), count
 * (weighed/estimated observation, used as the segment closing). Movements
 * reference bag AND segment so multi-bag batches and one-bag-across-shifts
 * both fall out of the model naturally (design doc "Allocation rules").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_bin_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_center_id')->constrained('work_centers')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // Nullable: ad-hoc bin activity outside a running segment is
            // legal (e.g. store refills the bin before Start Batch).
            $table->foreignId('shift_production_entry_id')->nullable()
                ->constrained('shift_production_entries')->nullOnDelete();
            $table->string('type', 10);
            $table->foreignId('material_bag_id')->nullable()->constrained('material_bags')->nullOnDelete();
            $table->decimal('quantity_kg', 12, 4);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamps();

            // Explicit short name — the auto-generated one would exceed
            // MySQL's 64-char identifier limit (same trap as spe_* indexes).
            $table->index(['work_center_id', 'item_id', 'recorded_at'], 'dbm_wc_item_recorded_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_bin_movements');
    }
};
