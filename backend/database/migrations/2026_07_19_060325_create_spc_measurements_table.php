<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spc_characteristic_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 15, 4);
            $table->timestamp('measured_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['spc_characteristic_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spc_measurements');
    }
};
