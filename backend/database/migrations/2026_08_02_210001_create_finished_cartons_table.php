<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FINISHED CARTON IDENTITIES — the outbound half of the barcode story. A
 * completed batch's packed cartons each get a permanent scannable identity
 * (batch number + sequence), printable as A4 labels like the input bags,
 * scanned at dispatch so a delivery is built from the physical cartons that
 * actually left, and traceable back to the batch afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finished_cartons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_production_entry_id')->constrained()->restrictOnDelete();
            $table->string('carton_no', 64)->unique();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('pieces', 15, 4);
            // The tail carton holding the loose remainder — labelled so the
            // floor knows it is not a full box.
            $table->boolean('is_partial')->default(false);
            $table->string('status', 16)->default('in_stock'); // in_stock | dispatched
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['item_id', 'status'], 'finished_cartons_item_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_cartons');
    }
};
