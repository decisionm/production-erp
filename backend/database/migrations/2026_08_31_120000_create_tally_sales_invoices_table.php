<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tally's Sales vouchers, IMPORTED — the inbound half of DEC-20260831-008.
 *
 * The ERP posts no Sales Invoice; Tally creates it, along with the e-invoice
 * and e-way details. This table is where those vouchers land after being read
 * from a Tally XML export, and where the MATCH to an ERP sales order is held.
 *
 * `tally_guid` is unique because it is Tally's own identity for the voucher
 * and is what makes a re-import a no-op rather than a duplicate. The voucher
 * NUMBER is deliberately not unique and deliberately not the key: Tally owns a
 * contiguous NNN/26-27 series and the ERP mints INV-{id}, so the two numbering
 * series never meet (DEC-20260831-008).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tally_sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('tally_guid')->unique();
            $table->string('voucher_number');
            $table->date('voucher_date');
            $table->string('party_ledger_name');
            // The customer's OWN purchase-order string, exactly as Tally holds
            // it. This is the match key, not a display field.
            $table->string('customer_po_reference')->nullable();
            $table->decimal('amount', 15, 4)->nullable();

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();

            // matched | unmatched_no_reference | unmatched_no_customer
            //         | unmatched_no_order | ambiguous
            $table->string('match_state');
            $table->string('match_detail')->nullable();

            $table->timestamp('imported_at');
            $table->timestamps();

            $table->index(['customer_id', 'customer_po_reference']);
            $table->index('match_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_sales_invoices');
    }
};
