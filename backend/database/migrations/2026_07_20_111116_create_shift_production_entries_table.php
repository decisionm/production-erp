<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_production_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_center_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('production_date');
            $table->decimal('quantity_produced', 15, 4);
            $table->decimal('quantity_scrap', 15, 4)->default(0);
            $table->foreignId('scrap_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Explicit short name: the auto-generated
            // "shift_production_entries_production_date_shift_id_work_center_id_index"
            // exceeds MySQL's 64-char identifier limit (passes on SQLite, fails on MySQL).
            $table->index(['production_date', 'shift_id', 'work_center_id'], 'spe_date_shift_wc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_production_entries');
    }
};
