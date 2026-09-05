<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE DAYS THE FACTORY IS SHUT.
 *
 * The punch report names its own week offs and never names a public
 * holiday, so 396 days of the August 2026 report land as "no punch" and
 * wait for a person to answer them one at a time — 130 of those Sundays.
 * A holiday nobody worked is neither leave nor an absence, and with a
 * calendar the ERP can say so itself.
 *
 * One row per date, unique, because a date either is a holiday or is not;
 * the year is read off the date rather than stored beside it, so the two
 * can never disagree. Soft deletes because a holiday that was observed and
 * then withdrawn is history a past month's sheet was built on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->index('date', 'holidays_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
