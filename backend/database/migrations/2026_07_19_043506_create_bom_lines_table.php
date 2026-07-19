<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity_per', 15, 4);
            $table->timestamps();

            $table->unique(['bom_id', 'component_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_lines');
    }
};
