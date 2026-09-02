<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-20260902-026: a vendor carries ONE OR MORE of five classifications, set
 * by a person. Multi-valued, so a child table and not a column. The Tally
 * ledger group may only propose; nothing here writes a row automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('classification', 40);
            $table->timestamps();
            $table->unique(['vendor_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_classifications');
    }
};
