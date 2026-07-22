<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('power_interruption_logs', function (Blueprint $table) {
            $table->id();
            // Plant-wide, not per-machine — the paper form has no M/C No
            // column for this section (master plan §10 open question #3).
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->date('production_date');
            $table->dateTime('from_time');
            $table->dateTime('to_time');
            // Computed from from_time/to_time, not a manual input.
            $table->decimal('idle_hours', 10, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'production_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('power_interruption_logs');
    }
};
