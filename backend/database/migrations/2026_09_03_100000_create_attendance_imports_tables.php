<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Pooja punch-report import (Track 2 of the 03-Sep design): one run per
 * uploaded workbook, one line per employee-day exactly as the report
 * carried it, plus the reviewer's correction beside it. `attendances` is
 * only written from here through AttendanceService::mark — these two
 * tables are the review copy, and the payroll month sheet is produced from
 * the lines so the file matches what was reviewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32); // pooja
            $table->date('period_from');
            $table->date('period_to');
            $table->string('file_name')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('status', 16)->default('review'); // review | applied
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedInteger('day_count')->default(0);
            $table->unsignedInteger('issue_count')->default(0);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_import_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_import_id')->constrained('attendance_imports')->cascadeOnDelete();
            // Null when the report's employee code is not in the master —
            // the `unknown_employee` issue; re-linked when the employee
            // exists. SET NULL, declared in EmployeeService::dependencyChecks.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employee_code', 32);
            $table->string('employee_name');
            $table->date('date');
            $table->string('raw_status', 32);
            // Wall-clock IST as the report printed them; the instant is
            // built at apply time through the factory timezone.
            $table->time('first_in')->nullable();
            $table->time('last_out')->nullable();
            $table->unsignedSmallInteger('ot_minutes')->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_minutes')->default(0);
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->string('issue', 32)->nullable(); // in_no_out | out_no_in | no_punch | unknown_employee
            $table->string('resolution', 16)->nullable(); // present | half_day | absent | on_leave | week_off
            $table->time('resolved_check_in')->nullable();
            $table->time('resolved_check_out')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['attendance_import_id', 'employee_code', 'date'], 'attendance_import_lines_day_unique');
            $table->index(['attendance_import_id', 'issue', 'resolution'], 'attendance_import_lines_review_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_import_lines');
        Schema::dropIfExists('attendance_imports');
    }
};
