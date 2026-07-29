<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A downtime occurrence. Planned events can exist BEFORE a batch starts
 * (entry_id null, machine + date only) and are attached to the batch when
 * it starts — that is what makes planned downtime change the estimate
 * rather than only explain the result afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_downtime_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_production_entry_id')->nullable()
                ->constrained('shift_production_entries')->cascadeOnDelete();
            $table->foreignId('work_center_id')->constrained('work_centers')->cascadeOnDelete();
            $table->foreignId('downtime_reason_id')->constrained('downtime_reasons')->cascadeOnDelete();
            $table->date('production_date');
            $table->decimal('minutes', 8, 2);
            $table->boolean('is_planned')->default(false);
            // True when the event was entered before Start — the honest
            // record of what was foreseeable, kept separate from is_planned
            // so a "planned" reason logged mid-shift is not miscounted.
            $table->boolean('known_before_start')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['work_center_id', 'production_date']);
            $table->index('shift_production_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_downtime_events');
    }
};
