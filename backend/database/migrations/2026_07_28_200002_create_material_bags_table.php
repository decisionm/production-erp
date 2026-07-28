<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 traceability — one row per physical bag. The UNIQUE barcode is
 * the design's core guard: a duplicate scan hits the constraint and errors
 * loudly instead of silently double-counting (design doc "Data model").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_bags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_lot_id')->constrained('material_lots')->restrictOnDelete();
            // Supplier's barcode when scannable, else app-generated
            // LOT{lot}-B{seq} (printable) — either way unique by column.
            $table->string('barcode', 64)->unique();
            $table->decimal('original_kg', 12, 4);
            $table->decimal('remaining_kg', 12, 4);
            $table->string('status', 16)->default('in_store');
            $table->foreignId('current_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('day_bin_work_center_id')->nullable()->constrained('work_centers')->nullOnDelete();
            $table->timestamps();

            $table->index(['status'], 'material_bags_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_bags');
    }
};
