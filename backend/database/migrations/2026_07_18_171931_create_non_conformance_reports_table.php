<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformance_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_inspection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->text('description');
            $table->string('severity')->default('minor'); // minor | major | critical
            $table->string('status')->default('open'); // open | closed
            $table->decimal('quantity_affected', 15, 4)->nullable();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('raised_date');
            $table->text('resolution')->nullable();
            $table->date('closed_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformance_reports');
    }
};
