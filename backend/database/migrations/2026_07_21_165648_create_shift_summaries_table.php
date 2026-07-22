<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->date('production_date');
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->decimal('target_production_kg', 15, 4)->nullable();
            $table->decimal('power_consumption_units', 15, 4)->nullable();
            $table->text('remarks')->nullable();
            // Whoever taps "Close Shift" — the accountable name for this
            // shift's KPI roll-up, independent of who logged which machine.
            // No fixed lead supervisor on this floor (confirmed: staff work
            // any of the 10 machines ad hoc) — see UX doc §2.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shift_id', 'production_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_summaries');
    }
};
