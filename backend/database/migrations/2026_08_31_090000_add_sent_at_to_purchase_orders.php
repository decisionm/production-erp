<?php

use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN AN ORDER REACHED THE VENDOR — the fact the lifecycle never recorded.
 *
 * purchase_orders carried closed_at and cancelled_at but nothing for the
 * send, and `status` holds only the CURRENT value. So once an order was
 * Cancelled there was no way to tell whether it had been cancelled from
 * Draft (never sent, the vendor never heard of it) or from Sent (the vendor
 * held it and the factory withdrew it). Nothing needed to tell them apart
 * until the owner answered the two requisition-coverage questions:
 *
 *   a DRAFT order reserves nothing, and
 *   a CANCELLED order still counts against its requisition
 *
 * Together those make the distinction load-bearing. A cancelled order
 * consumes the requisition only if it actually reached the vendor —
 * otherwise cancelling an abandoned draft would eat a requisition the draft
 * never held. See RequisitionCoverageService::reserves().
 *
 * BACKFILL, AND WHY IT IS PART OF THIS MIGRATION. Every row already at or
 * past Sent WAS sent — including a Tally mirror, which is born Sent because
 * it reflects an order Tally already holds. Without the backfill, one of
 * those orders cancelled AFTER this migration would carry a null sent_at,
 * read as never-sent, and hand back balance it had genuinely spent. The
 * column and the backfill are therefore one change, never two.
 *
 * The backfilled INSTANT is an approximation — no true send time exists to
 * recover, so `updated_at` (which for an order at/past Sent is at or after
 * its send) stands in. Only whether sent_at is NULL is load-bearing, and
 * that is exact. New rows carry the real instant, stamped by
 * PurchaseOrderService::send() and by create() for a mirror.
 */
return new class extends Migration
{
    /** Everything a send has already happened for — Cancelled is exactly the ambiguous one, and is absent. */
    private const ALREADY_SENT = [
        PurchaseOrderStatus::Sent->value,
        PurchaseOrderStatus::PartiallyReceived->value,
        PurchaseOrderStatus::Closed->value,
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('purchase_orders', 'sent_at')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->timestamp('sent_at')->nullable()->after('created_by');
            });
        }

        DB::table('purchase_orders')
            ->whereIn('status', self::ALREADY_SENT)
            ->whereNull('sent_at')
            ->update(['sent_at' => DB::raw('COALESCE(updated_at, created_at)')]);

        // A row already Cancelled when this ran is left NULL, and so reads as
        // never-sent. That is the honest answer and not a guess: the fact was
        // not recorded, and inventing one would decide a live requisition's
        // balance on a coin toss. Verified harmless on the data as it stands
        // — no cancelled purchase order exists.
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'sent_at')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropColumn('sent_at');
            });
        }
    }
};
