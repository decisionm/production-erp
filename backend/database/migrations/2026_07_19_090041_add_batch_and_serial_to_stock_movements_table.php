<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Pure traceability tags, not a valuation dimension — stock
            // balances stay aggregated at item+warehouse exactly as before
            // (see StockMovementService). Which lot/unit a movement
            // concerned is answered by querying movements, not by a
            // running per-batch balance.
            $table->foreignId('batch_id')->nullable()->after('warehouse_id')->constrained()->nullOnDelete();
            $table->foreignId('serial_number_id')->nullable()->after('batch_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropConstrainedForeignId('serial_number_id');
        });
    }
};
