<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measuring_instruments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->unsignedInteger('calibration_frequency_days');
            $table->date('last_calibrated_date')->nullable();
            $table->date('next_calibration_due');
            $table->string('status')->default('active'); // active | retired
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measuring_instruments');
    }
};
