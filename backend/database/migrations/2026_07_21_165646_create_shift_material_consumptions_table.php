<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_material_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_production_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // Deliberately its own warehouse, distinct from the entry's FG
            // receiving warehouse — resin/masterbatch is issued from RM
            // stores, not received into the FG store the finished bottles
            // land in.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_issued_kg', 15, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_material_consumptions');
    }
};
