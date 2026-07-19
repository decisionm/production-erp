<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_center_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('name');
            $table->decimal('standard_time_minutes', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['routing_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_operations');
    }
};
