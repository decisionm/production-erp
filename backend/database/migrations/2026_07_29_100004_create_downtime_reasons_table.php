<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downtime reason master. The planned/unplanned split is what lets the
 * shift show running efficiency separately from attainment: a machine that
 * lost two hours to a scheduled mould change was not performing badly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downtime_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('category', 64)->nullable();
            $table->string('description');
            // planned | unplanned — the DEFAULT classification. An event may
            // still be recorded against the other type when reality differs
            // (a "planned" maintenance that happened unexpectedly).
            $table->string('planning_type', 16)->default('unplanned');
            // Whether the minutes reduce net runtime for the runtime target.
            $table->boolean('reduces_runtime')->default(true);
            $table->boolean('requires_note')->default(false);
            // Offerable in the Start Batch planned-downtime picker.
            $table->boolean('selectable_at_start')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('confirmation_status', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_reasons');
    }
};
