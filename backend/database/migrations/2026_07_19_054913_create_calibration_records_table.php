<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('measuring_instrument_id')->constrained()->cascadeOnDelete();
            $table->date('calibrated_date');
            $table->string('certificate_number')->nullable();
            $table->string('result'); // pass | fail | adjusted
            $table->string('performed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_records');
    }
};
