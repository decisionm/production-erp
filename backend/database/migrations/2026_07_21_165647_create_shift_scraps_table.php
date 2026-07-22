<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_scraps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_production_entry_id')->constrained()->cascadeOnDelete();
            // Best-guess semantics pending Phase 1.5 accountant interview
            // (master plan §10 open question #1): rejected_finished_good is
            // defective units that came off the batch's own quantity_scrap;
            // lumps is separate non-recoverable purge/burnt material.
            $table->string('type', 32);
            $table->decimal('quantity_nos', 15, 4)->nullable();
            $table->decimal('quantity_kg', 15, 4)->nullable();
            $table->foreignId('scrap_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_scraps');
    }
};
