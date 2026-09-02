<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DEC-20260902-025: a requester withdraws their own requisition through a separate action, which is not a rejection. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->foreignId('withdrawn_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawn_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn('withdrawn_at');
        });
    }
};
