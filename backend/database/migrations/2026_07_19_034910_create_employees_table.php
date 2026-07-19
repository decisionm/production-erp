<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('date_of_joining');
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->string('status')->default('active'); // active | inactive | terminated
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            // Nullable link to a login account — for employee self-service,
            // deliberately not built in this pass (a distinct external-facing
            // auth surface, same reasoning as the CRM customer portal).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
