<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE TALLY-PO-TO-ARRIVAL FLOW, owner-confirmed (PRODUCTION-ERP-PROGRESS.md):
 * Tally is the read-only Purchase Order and delivery-schedule master; the ERP
 * mirrors those identities and owns the operational GRN, arrival labels,
 * Incoming QC and production-availability workflow. One PO arrives across
 * many GRNs; each arrival allocates against exact item/due-date schedules
 * (oldest due first by default, editable before submission).
 *
 * Everything here is additive and nullable — ERP-native POs keep working
 * exactly as before, and no historical row changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_orders', 'source')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                // 'erp' = created here; 'tally' = a read-only mirror of the
                // real order living in Tally. A mirror is corrected in Tally
                // and re-mirrored, never edited here.
                $table->string('source', 10)->default('erp')->after('status');
                $table->string('tally_order_no', 64)->nullable()->after('source');
            });
        }

        if (! Schema::hasTable('purchase_order_schedules')) {
            Schema::create('purchase_order_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_line_id')->constrained()->cascadeOnDelete();
                $table->date('due_date');
                $table->decimal('quantity', 15, 4);
                $table->decimal('quantity_received', 15, 4)->default(0);
                // The exact Tally allocation identity (ORDERNO/TRACKINGNUMBER
                // evidence shows the company uses item/due-date allocations).
                $table->string('tally_reference', 64)->nullable();
                $table->timestamps();
                $table->index(['purchase_order_line_id', 'due_date'], 'po_schedules_line_due_index');
            });
        }

        if (! Schema::hasColumn('goods_receipt_notes', 'receipt_note_reference')) {
            Schema::table('goods_receipt_notes', function (Blueprint $table) {
                // Recorded at physical arrival — the reference the Receipt
                // Note (and any later Rejections Out) will carry in Tally.
                $table->string('receipt_note_reference', 64)->nullable()->after('reference');
                $table->string('tracking_number', 64)->nullable()->after('receipt_note_reference');
            });
        }

        if (! Schema::hasTable('grn_schedule_allocations')) {
            Schema::create('grn_schedule_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('goods_receipt_note_line_id')
                    ->constrained('goods_receipt_note_lines', indexName: 'grn_alloc_line_fk')
                    ->cascadeOnDelete();
                $table->foreignId('purchase_order_schedule_id')
                    ->constrained('purchase_order_schedules', indexName: 'grn_alloc_schedule_fk')
                    ->restrictOnDelete();
                $table->decimal('quantity', 15, 4);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('incoming_inspections', 'rejections_out_reference')) {
            Schema::table('incoming_inspections', function (Blueprint $table) {
                // The tracking reference a Rejections Out voucher will carry.
                // RECORDED ONLY: no Tally voucher is created until the exact
                // XML is proven and the owner enables it.
                $table->string('rejections_out_reference', 64)->nullable()->after('notes');
                $table->string('bag_disposition_note', 200)->nullable()->after('rejections_out_reference');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_schedule_allocations');
        Schema::dropIfExists('purchase_order_schedules');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['source', 'tally_order_no']);
        });
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropColumn(['receipt_note_reference', 'tracking_number']);
        });
        Schema::table('incoming_inspections', function (Blueprint $table) {
            $table->dropColumn(['rejections_out_reference', 'bag_disposition_note']);
        });
    }
};
