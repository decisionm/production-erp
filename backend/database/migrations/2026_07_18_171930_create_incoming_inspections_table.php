<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_note_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('inspected_quantity', 15, 4);
            $table->decimal('accepted_quantity', 15, 4);
            $table->decimal('rejected_quantity', 15, 4);
            $table->string('result'); // pass | fail | partial
            $table->date('inspection_date');
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_inspections');
    }
};
