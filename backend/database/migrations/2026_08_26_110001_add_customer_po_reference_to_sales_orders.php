<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE CUSTOMER'S OWN PURCHASE-ORDER NUMBER on a sales order.
 *
 * The ERP numbers its orders "SO-{id}". Nobody outside this building uses that
 * number: the customer quotes THEIR purchase order number, the delivery note
 * and the Tally invoice carry it, and it is the string a person actually
 * matches an order against an invoice with. Recording it is the difference
 * between an order book that can be reconciled with the customer's paperwork
 * and one that cannot.
 *
 * A PLAIN, NULLABLE, FREE-TEXT FIELD, DELIBERATELY:
 *  · nullable — plenty of orders arrive by phone with no PO at all, and an
 *    invented reference would be a fabricated factory value (AGENTS.md);
 *  · not unique — it is the CUSTOMER's number, so two customers may legitimately
 *    use the same one, and a customer may raise several orders under one PO;
 *  · no validation of shape — every customer numbers their POs differently and
 *    the ERP does not get to decide what a valid one looks like.
 *
 * WHAT IT IS NOT, IN THIS BUILD. It is not wired to Tally. Whether the ERP may
 * emit a Tally 'Sales Order' voucher at all is an open owner question (see
 * PENDING-OWNER-QUESTIONS), and until that is answered no voucher code ships —
 * so this column is recorded and displayed, and read by nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('customer_po_reference')->nullable()->after('expected_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn('customer_po_reference');
        });
    }
};
