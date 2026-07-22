<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('molds', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            // Default/primary item this mold produces — informational, not
            // a hard 1:1 lock (a plant can have a backup mold for the same
            // item). Nullable so a mold can be registered before its item
            // mapping is settled.
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->unsignedInteger('cavity_count')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('molds');
    }
};
