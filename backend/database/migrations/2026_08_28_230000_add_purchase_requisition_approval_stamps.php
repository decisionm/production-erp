<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO decided a requisition, and WHEN — the audit finding (28-Aug live
 * walk, finding 8): a requisition read "approved" with nothing saying by
 * whom or when, so the paper trail stopped at the status word. One pair of
 * stamps per outcome, written by the service at the moment of the decision
 * and never after; a requisition approved before this column exists keeps
 * NULLs, which the page words honestly rather than inventing an approver.
 *
 * nullOnDelete, not cascade: deleting a user must never delete or orphan
 * the requisition's history — the stamp outlives the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn('rejected_at');
        });
    }
};
