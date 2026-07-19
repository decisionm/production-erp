<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('serial_number');
            $table->string('status')->default('registered'); // registered | in_stock | consumed | sold | scrapped
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'serial_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_numbers');
    }
};
