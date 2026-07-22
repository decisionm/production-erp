<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->date('production_date');
            // "Hoppers" / "Day Bin" / "Loose Bag" / "Store" for resin, or a
            // masterbatch colour — a lightweight periodic physical-count
            // log, informational only, not reconciled against
            // StockBalance (master plan §10).
            $table->string('location_label');
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_kg', 15, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'production_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_stock_counts');
    }
};
