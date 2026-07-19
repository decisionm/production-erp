<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_structure_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->unique(['salary_structure_id', 'salary_component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_structure_lines');
    }
};
