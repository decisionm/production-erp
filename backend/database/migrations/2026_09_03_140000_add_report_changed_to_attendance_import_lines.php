<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A month is uploaded again and again as it goes on, and the punch app
 * never learns about a correction made here — so a re-upload always
 * carries the app's ORIGINAL figures back.
 *
 * When the app's own numbers for a day have changed since a person
 * answered that day, the ANSWER STANDS and this stamp records that the
 * report moved under it. The screen can then offer those days for a second
 * look, instead of the software silently choosing one version over the
 * other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_import_lines', function (Blueprint $table) {
            $table->timestamp('report_changed_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_import_lines', function (Blueprint $table) {
            $table->dropColumn('report_changed_at');
        });
    }
};
