<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_characteristics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('name');
            $table->string('unit_of_measure')->nullable();
            $table->decimal('target_value', 15, 4)->nullable();
            $table->decimal('lower_spec_limit', 15, 4)->nullable();
            $table->decimal('upper_spec_limit', 15, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spc_characteristics');
    }
};
