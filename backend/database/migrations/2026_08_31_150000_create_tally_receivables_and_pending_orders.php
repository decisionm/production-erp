<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT THE FACTORY IS OWED, AND WHAT IT STILL OWES TO SHIP — as the factory's
 * own Tally already holds both.
 *
 * The inbound half of the CRM's client-outstanding page. The agent exports
 * Tally's Bills Receivable and Sales Order Outstanding reports and posts the
 * rows here; this schema stores them and nothing else. No voucher is ever
 * posted from these tables and nothing in the ERP edits a row.
 *
 * WHY THESE LIVE HERE AND NOT IN SALES. The ERP's own `invoices` and
 * `sales_orders` tables hold one row each on this instance: the factory raises
 * its sales in Tally, which is exactly why the Sales-invoice voucher builder
 * was never enabled. A receivable read is therefore a MIRROR OF TALLY, and it
 * belongs beside the other things the agent mirrors (`ledgers`,
 * `tally_purchase_rates`) rather than pretending to be the ERP's own ledger.
 * `AccountsReceivableService` continues to read Sales' invoices and is
 * untouched: it answers a different question — what the ERP itself has billed
 * — and the page labels the two apart rather than blending them.
 *
 * A PULL REPLACES THE SET, it does not merge into it. Both Tally reports are
 * CLOSING POSITIONS as at a date, not a stream of vouchers: a bill that has
 * since been settled is simply absent from the next export. Upserting on a
 * bill identity would leave that settled bill sitting in the table for ever,
 * still being counted as outstanding — the failure mode is a number that only
 * ever grows. So the sync deletes the company's rows and writes what the
 * export actually contained. `tally_purchase_rates` upserts instead, correctly:
 * a Day Book pull is a window over an append-only history, and absence there
 * means "outside the window", not "settled".
 *
 * NO UNIQUE INDEX ON THE BILL REFERENCE. Tally permits an on-account receipt
 * with no bill reference at all, and two parties may reuse one reference. The
 * rows are identified by the pull that wrote them, not by a key the source
 * does not guarantee.
 *
 * NOT A FOREIGN KEY to `customers`. The link is by `party_ledger_guid` against
 * `customers.tally_ledger_guid`, which is nullable and unbacked by design (see
 * the customer-ledger-link migration): a customer nobody has linked yet must
 * still show its outstanding under the Tally ledger's own name rather than
 * vanish from the page. Resolving the link is a read-time join, not a
 * write-time constraint.
 *
 * MONEY IS DECIMAL, NEVER FLOAT. These are the numbers a person chases a
 * client for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tally_receivable_bills', function (Blueprint $table): void {
            $table->id();

            // WHO OWES IT. The name is what a person reconciling against Tally
            // reads on screen; the GUID is the stable identity used to join to
            // a customer. Tally's Bills Receivable does not always carry the
            // ledger GUID, so the name is the required half and the GUID is
            // nullable — a bill is never dropped for want of a GUID.
            $table->string('party_ledger_name');
            $table->string('party_ledger_guid')->nullable();

            // WHICH BILL. Nullable: an on-account amount has no reference, and
            // recording an empty string as one would invent a bill number.
            $table->string('bill_reference')->nullable();
            $table->date('bill_date')->nullable();

            // WHEN IT FELL DUE. Nullable because a party with no credit terms
            // in Tally has no due date, and the page must say "no due date"
            // rather than silently treat the bill date as one. OUTSTANDING
            // DAYS ARE COMPUTED FROM THIS AT READ TIME, never stored: a stored
            // age is wrong the day after it is written.
            $table->date('due_date')->nullable();

            // WHAT IS STILL OUTSTANDING — Tally's closing balance for the
            // bill, not its original value. Signed as Tally states it: a
            // credit note or advance sits negative and must keep its sign, or
            // a client in credit would read as a debtor.
            $table->decimal('closing_amount', 18, 4);

            // The bill's full value where Tally states it, for the detail row.
            $table->decimal('opening_amount', 18, 4)->nullable();

            // AS AT WHICH DATE the position was read, and when the pull ran.
            // Both, because they are different facts: the operator can export
            // a position as at last month at nine this morning.
            $table->date('as_of');
            $table->string('tally_company')->nullable();
            $table->timestamp('tally_synced_at');

            $table->timestamps();

            // The page's only two access paths: everything for a company, and
            // one party's bills.
            $table->index(['tally_company', 'party_ledger_name']);
            $table->index('party_ledger_guid');
        });

        Schema::create('tally_pending_sales_orders', function (Blueprint $table): void {
            $table->id();

            $table->string('party_ledger_name');
            $table->string('party_ledger_guid')->nullable();

            // THE CLIENT'S OWN PURCHASE-ORDER NUMBER where Tally carries it —
            // the same fact `sales_orders.customer_po_reference` records for an
            // order raised in the ERP. This is what makes the page's pending
            // column answerable client by client.
            $table->string('order_reference')->nullable();
            $table->date('order_date')->nullable();
            $table->date('due_date')->nullable();

            $table->string('stock_item_name')->nullable();

            // WHAT IS STILL TO SHIP. Quantity and value are both kept: a
            // factory reads a pending order in units, Accounts reads it in
            // money, and deriving either from the other needs a rate this
            // table has no business assuming.
            $table->decimal('pending_quantity', 18, 4)->nullable();
            $table->string('quantity_unit')->nullable();
            $table->decimal('pending_amount', 18, 4)->nullable();

            $table->date('as_of');
            $table->string('tally_company')->nullable();
            $table->timestamp('tally_synced_at');

            $table->timestamps();

            $table->index(['tally_company', 'party_ledger_name']);
            $table->index('party_ledger_guid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_pending_sales_orders');
        Schema::dropIfExists('tally_receivable_bills');
    }
};
