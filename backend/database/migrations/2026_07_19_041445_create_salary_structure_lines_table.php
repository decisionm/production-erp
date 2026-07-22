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

            // Explicit short name: the auto-generated
            // "salary_structure_lines_salary_structure_id_salary_component_id_unique"
            // exceeds MySQL's 64-char identifier limit (passes on SQLite, fails on MySQL).
            $table->unique(['salary_structure_id', 'salary_component_id'], 'salary_structure_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_structure_lines');
    }
};
