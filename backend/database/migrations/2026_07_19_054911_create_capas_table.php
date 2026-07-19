<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('non_conformance_report_id')->nullable()->constrained('non_conformance_reports')->nullOnDelete();
            $table->string('title');
            $table->text('problem_statement');
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('preventive_action')->nullable();
            $table->foreignId('owner')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status')->default('open'); // open | in_progress | closed
            $table->boolean('verified_effective')->nullable();
            $table->date('closed_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capas');
    }
};
