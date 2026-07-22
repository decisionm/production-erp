<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mold_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_center_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->date('production_date');
            // Real Item references rather than the master plan's plain
            // strings — the app already has a searchable item picker
            // everywhere else, and this makes "which items get swapped
            // together" a queryable report instead of free text.
            $table->foreignId('changed_from_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('changed_to_item_id')->constrained('items')->restrictOnDelete();
            $table->dateTime('from_time');
            $table->dateTime('to_time')->nullable();
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
        Schema::dropIfExists('mold_change_logs');
    }
};
