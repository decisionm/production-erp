<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rework_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rework_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity_required', 15, 4);
            $table->decimal('quantity_issued', 15, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rework_order_materials');
    }
};
