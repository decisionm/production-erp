<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The purchase order's lifecycle record and its Tally-staging identity
 * (Phase 6, P6-01 / P6-03). Everything additive and nullable — no existing
 * row changes meaning, every earlier caller keeps working:
 *
 * purchase_orders
 *   closed_reason / closed_by / closed_at         — written ONCE by close()
 *   cancelled_reason / cancelled_by / cancelled_at — written ONCE by cancel()
 *     (the enum's Cancelled case, dead until now, comes to life with it)
 *   tally_staging (JSON)  — what happened when the order was SENT and the
 *     Tally side judged it: {state: disabled|refused|enqueued, reasons:
 *     [{code, detail}], entry_id?, at}. Written only through
 *     PurchaseOrderService::recordTallyStaging (the one writer; the TallySync
 *     listener calls it). NOT a Tally voucher, NOT a status of the queue —
 *     the honest note the PO screen shows while PO posting is owner-gated
 *     (tally-sync.purchase_orders_enabled defaults false; Q35).
 *
 * vendors
 *   tally_ledger_name — the vendor's ledger name in Tally, the party a
 *     Purchase Order voucher would name. Nullable and NEVER populated by
 *     the ERP itself (no Tally read); Accounts sets it on the vendor form.
 *     A vendor without one cannot be staged (reason 'party_unmapped').
 *     The name is supplier identity (FC-06): VendorResource serves it to
 *     the same readers that already see the vendor's own name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'closed_reason')) {
                $table->text('closed_reason')->nullable()->after('notes');
                $table->foreignId('closed_by')->nullable()->after('closed_reason')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamp('closed_at')->nullable()->after('closed_by');
            }
            if (! Schema::hasColumn('purchase_orders', 'cancelled_reason')) {
                $table->text('cancelled_reason')->nullable()->after('closed_at');
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_reason')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }
            if (! Schema::hasColumn('purchase_orders', 'tally_staging')) {
                $table->json('tally_staging')->nullable()->after('tally_order_no');
            }
        });

        if (! Schema::hasColumn('vendors', 'tally_ledger_name')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->string('tally_ledger_name', 255)->nullable()->after('gstin');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendors', 'tally_ledger_name')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('tally_ledger_name');
            });
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'tally_staging')) {
                $table->dropColumn('tally_staging');
            }
            if (Schema::hasColumn('purchase_orders', 'cancelled_reason')) {
                $table->dropConstrainedForeignId('cancelled_by');
                $table->dropColumn(['cancelled_reason', 'cancelled_at']);
            }
            if (Schema::hasColumn('purchase_orders', 'closed_reason')) {
                $table->dropConstrainedForeignId('closed_by');
                $table->dropColumn(['closed_reason', 'closed_at']);
            }
        });
    }
};
