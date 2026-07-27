<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            // The paper Production Report's "Helper" column — free text (the
            // helper on the machine isn't necessarily an Employee master),
            // captured at Complete Batch alongside the operator.
            $table->string('helper_name', 120)->nullable()->after('no_of_box');
        });
    }

    public function down(): void
    {
        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropColumn('helper_name');
        });
    }
};
