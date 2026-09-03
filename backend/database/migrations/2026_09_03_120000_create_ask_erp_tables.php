<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ask_erp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ask_erp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ask_erp_conversations')->cascadeOnDelete();
            $table->string('role', 16); // user | assistant
            $table->text('question')->nullable();
            $table->text('sql')->nullable();
            $table->text('answer')->nullable();
            $table->json('tables_used')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ask_erp_messages');
        Schema::dropIfExists('ask_erp_conversations');
    }
};
