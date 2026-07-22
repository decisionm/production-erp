<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_downtime_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_center_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->date('production_date');
            $table->string('nature_of_problem');
            $table->text('remedy')->nullable();
            $table->string('parts_changed')->nullable();
            // Full datetime, not a bare time-of-day — the Night shift
            // (22:00-06:00) crosses midnight, and a plain time-only diff
            // would go negative for a breakdown that starts before and
            // ends after midnight.
            $table->dateTime('from_time');
            $table->dateTime('to_time')->nullable();
            // Computed at close() from from_time/to_time, never a manual
            // input — same read-only-when-computable rule as everywhere
            // else in this flow.
            $table->decimal('total_minutes', 10, 2)->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['work_center_id', 'status']);
            $table->index(['shift_id', 'production_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_downtime_logs');
    }
};
