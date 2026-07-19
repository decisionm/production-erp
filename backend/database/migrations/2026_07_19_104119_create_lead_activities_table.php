<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('note'); // call | email | meeting | note
            $table->text('notes');
            $table->dateTime('activity_date');
            $table->date('next_follow_up_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
