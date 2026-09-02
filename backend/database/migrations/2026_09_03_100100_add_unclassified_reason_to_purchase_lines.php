<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DEC-20260902-023: an unclassified item on a purchase document carries the reason an authorised person gave. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisition_lines', fn (Blueprint $t) => $t->string('unclassified_reason', 255)->nullable()->after('notes'));
        // purchase_order_lines has no `notes` column to anchor after.
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->string('unclassified_reason', 255)->nullable());
    }

    public function down(): void
    {
        Schema::table('purchase_requisition_lines', fn (Blueprint $t) => $t->dropColumn('unclassified_reason'));
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropColumn('unclassified_reason'));
    }
};
