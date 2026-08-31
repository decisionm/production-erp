<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTERNAL QUALITY APPROVAL, RECORDED — DEC-20260831-006.
 *
 * The owner's dispatch sequence is: stock fully held → QUALITY APPROVES →
 * Sales dispatches → Sales invoices. Before this, nothing in the ERP recorded
 * a quality approval against a sales order line at all: the only quality gate
 * anywhere near dispatch refused a carton whose batch was already REJECTED
 * (DEC-20260807-013), while a batch merely not yet through QC passed freely.
 *
 * The approval is recorded, not asserted — WHO approved, WHEN, and FOR WHAT
 * QUANTITY. The quantity is the load-bearing column: it is stamped with what
 * the line actually held at the moment Quality looked at it, and dispatch is
 * capped by it. Without it, a hold released and re-taken after approval would
 * inherit a sign-off nobody gave for the new stock.
 *
 * PURE SCHEMA, NO DATA. Every column is nullable, so existing lines read as
 * "not yet approved", which is exactly what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->timestamp('quality_approved_at')->nullable()->after('quantity_delivered');
            $table->foreignId('quality_approved_by')->nullable()->after('quality_approved_at')
                ->constrained('users')->nullOnDelete();
            // What Quality signed for. Dispatch may not exceed it.
            $table->decimal('quality_approved_quantity', 15, 4)->nullable()->after('quality_approved_by');
            $table->text('quality_approval_note')->nullable()->after('quality_approved_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quality_approved_by');
            $table->dropColumn(['quality_approved_at', 'quality_approved_quantity', 'quality_approval_note']);
        });
    }
};
