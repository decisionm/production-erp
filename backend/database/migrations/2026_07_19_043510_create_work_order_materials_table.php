<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity_required', 15, 4);
            $table->decimal('quantity_issued', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['work_order_id', 'component_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_materials');
    }
};
