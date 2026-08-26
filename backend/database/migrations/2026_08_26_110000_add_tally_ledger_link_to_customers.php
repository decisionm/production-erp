<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH TALLY LEDGER A CUSTOMER IS — the missing half of the customer import.
 *
 * `sales:import-customers-from-ledgers` already mints one customer per Tally
 * ledger and encodes the ledger id in `customers.code` ("TL-{id}"). That code
 * is a breadcrumb, not an identity: it is a free-text column a person may
 * rename, and it says nothing about WHICH Tally company's ledger it came from
 * or what that ledger is called today. So the identity is written down
 * properly, the same way every other mirrored master carries it —
 * `ledgers.tally_guid` and `items.tally_stock_item_guid` are the precedents.
 * (Vendor's single manual Tally field is NOT the precedent: it is typed by a
 * person on a form; these two are only ever written by the import.)
 *
 * WHY THE NAME AS WELL AS THE GUID. The GUID is the stable identity; the name
 * is what a human sees in Tally and what anybody reconciling by eye reads. A
 * screen that says "posts as {tally_ledger_name}" is checkable by the person
 * looking at Tally; a GUID is not. Both are a mirror of the ledger row, and
 * neither is authoritative over Tally.
 *
 * BOTH NULLABLE, NO BACKFILL, NO UNIQUE INDEX. Nullable because most customers
 * predate this column and "nobody has linked this one" is the honest state —
 * the same rule the audit-stamp migration followed rather than inventing a
 * value (PR #128). No unique index because the mirror is not the authority:
 * two ERP customers pointing at one ledger is a data problem for a person to
 * see and fix, not something a schema error should hide at write time.
 *
 * NOT A FOREIGN KEY to `ledgers` on purpose. The ledger mirror is refreshed by
 * the Tally masters pull and its rows are soft-deletable; a customer's
 * recorded posting identity must not vanish or block because the mirror was
 * re-pulled. The link is a recorded fact, not a live join.
 *
 * NO TALLY VOUCHER RIDES ON THIS. These columns are the identity only — this
 * build emits no Sales Order voucher, and whether the ERP may ever emit one is
 * an open owner question (see PENDING-OWNER-QUESTIONS).
 *
 * FC-06 is untouched: a customer ledger is not a supplier identity and carries
 * no rate of any kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('tally_ledger_guid')->nullable()->after('state_code');
            $table->string('tally_ledger_name')->nullable()->after('tally_ledger_guid');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['tally_ledger_guid', 'tally_ledger_name']);
        });
    }
};
