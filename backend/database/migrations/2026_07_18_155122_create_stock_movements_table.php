<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('type'); // receipt | issue | transfer_in | transfer_out — see StockMovementType
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('reference')->nullable();
            $table->uuid('transfer_group')->nullable();
            $table->dateTime('movement_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id', 'movement_date']);
            $table->index('transfer_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
