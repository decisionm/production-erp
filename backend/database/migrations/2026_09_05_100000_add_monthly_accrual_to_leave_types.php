<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leave type accrues by the MONTH, not only by the year.
 *
 * The factory grants 1 casual and 1 sick leave a month. `default_annual_days`
 * could not say that: it is one number for a whole year, and a year's worth
 * granted on day one is not the same entitlement as a twelfth of it granted
 * each month. Zero — the default — means this type does not accrue monthly,
 * which is how Earned Leave keeps behaving exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->decimal('monthly_accrual_days', 5, 2)->default(0)->after('default_annual_days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('monthly_accrual_days');
        });
    }
};
