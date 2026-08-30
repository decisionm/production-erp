<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPPLIER BILLS — the vendor's invoice recorded in the ERP (28-Aug audit
 * finding 10: the procurement chain ended at the GRN; what the factory OWES
 * for an arrival lived nowhere).
 *
 * What this deliberately IS: a RECORD of the paper bill — its number, its
 * date, its figures as printed (taxes and rounding TYPED from the paper,
 * never computed from a rate table: DEC-20260812-003 forbids seeding any
 * tax rate, the effective-dated rate config it mandates is unbuilt, and
 * Q39 is open on how the rate is even chosen). The one arithmetic the ERP
 * enforces is the bill's own: subtotal = Σ line amounts, and total =
 * subtotal + CGST + SGST + IGST + rounding — a mistyped figure is refused
 * with the gap named, because a bill that does not add up is a typo today
 * and a dispute in a quarter.
 *
 * What it deliberately IS NOT: a Tally Purchase Invoice. Posting is
 * withheld — the per-rate purchase ledger mapping is Q39, whether GST is
 * filed from Tally or the ERP is Q41, and whether the factory wants an
 * accounts-payable build at all is Q28. purchase_ledger_name is the
 * accountant's own SELECTION from the pulled ledger masters (a person
 * choosing is not the ERP deriving), stored for the day posting is ruled
 * on.
 *
 * FC-06: every figure here is a purchase rate, so the whole surface rides
 * module:finance (Owner/Accounts).
 *
 * UNIQUE (vendor_id, bill_number): the same vendor's invoice number entered
 * twice is the classic double-payment path; refused at the schema.
 * Bills are never hard-deleted — cancelled with a reason, row kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->string('bill_number', 100);
            $table->date('bill_date');
            $table->string('status', 20)->default('draft');
            // The accountant's selection from the pulled Tally ledgers — a
            // recorded choice, never a derivation (Q39 open).
            $table->string('purchase_ledger_name')->nullable();
            $table->decimal('subtotal', 14, 4);
            $table->decimal('cgst', 14, 4)->default(0);
            $table->decimal('sgst', 14, 4)->default(0);
            $table->decimal('igst', 14, 4)->default(0);
            // Signed — the paper's own rounding line, up or down.
            $table->decimal('rounding', 8, 4)->default(0);
            $table->decimal('total', 14, 4);
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'bill_number']);
        });

        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->cascadeOnDelete();
            $table->foreignId('goods_receipt_note_line_id')->nullable()->constrained('goods_receipt_note_lines')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('rate', 14, 4);
            // As PRINTED on the bill — vendors round per line, so this is
            // typed, and qty × rate variance is displayed, not refused.
            $table->decimal('amount', 14, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bill_lines');
        Schema::dropIfExists('supplier_bills');
    }
};
