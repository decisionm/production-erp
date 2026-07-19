<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // The single day this work order's routing load is planned
            // against — capacity planning is deliberately single-day
            // granularity, not a finite multi-day scheduling engine.
            $table->date('scheduled_date')->nullable()->after('routing_id');
            $table->index(['scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['scheduled_date']);
            $table->dropColumn('scheduled_date');
        });
    }
};
