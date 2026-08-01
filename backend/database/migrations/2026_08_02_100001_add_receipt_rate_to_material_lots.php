<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rate provenance on the material lot: what this lot's resin actually cost
 * per kg, and where that number came from.
 *
 * Why the column exists at all: goods_receipt_note_lines.unit_cost is the
 * only purchase rate in the system, and a lot reaches it through
 * goods_receipt_note_line_id — which is NULLABLE. Opening-stock lots have
 * no GRN behind them and therefore no cost source. Rather than let every
 * future reader re-derive the join (and quietly invent a number for the
 * opening lots), the rate is stamped onto the lot once, at the moment it
 * is known, and rate_source records its provenance honestly.
 *
 * NULL rate_source means UNKNOWN. It is not a synonym for zero and must
 * never be rendered as one — an opening lot genuinely has no purchase rate
 * in this system, and saying so is the point of the column.
 *
 * This is an ANALYTIC column. It does not participate in valuation:
 * stock_balances.average_cost and the moving-average ledger the Accounts
 * team approved are untouched, and no Tally voucher carries a rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        // RE-RUNNABLE ON PURPOSE. MySQL DDL is non-transactional: if the
        // backfill below ever died mid-loop, the migration would not be
        // recorded and the next `artisan migrate` would replay this up() —
        // which must then not trip over columns it already added. (The
        // deploy takes a mysqldump before migrating either way; this makes
        // the recovery story "just run it again" instead of "restore".)
        if (! Schema::hasColumn('material_lots', 'receipt_rate_per_kg')) {
            Schema::table('material_lots', function (Blueprint $table) {
                $table->decimal('receipt_rate_per_kg', 15, 4)->nullable()->after('total_received_kg');
                // 'grn' | 'po' | 'manual' | null. Deliberately a plain string,
                // not an enum column: adding a provenance in future must not
                // require an ALTER on a live factory's table.
                $table->string('rate_source', 20)->nullable()->after('receipt_rate_per_kg');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('material_lots', function (Blueprint $table) {
            $table->dropColumn(['receipt_rate_per_kg', 'rate_source']);
        });
    }

    /**
     * Stamp every existing lot with the rate its receipt actually used.
     *
     * Resolution order, most-direct first:
     *   1. the linked GRN line's unit_cost            → 'grn'
     *   2. the GRN's line for the same item's unit_cost → 'grn'
     *      (lots created before goods_receipt_note_line_id existed still
     *      carry grn_id, and the GRN line for their item IS their rate)
     *   3. the purchase-order line's unit_price       → 'po'
     *      (the only surviving number when the GRN line is gone/priceless)
     *   4. nothing                                    → both stay NULL
     *
     * Step 4 is the opening-stock case and it is left alone on purpose.
     * Raw DB queries throughout — a migration must keep working when the
     * Eloquent models above it change shape.
     */
    private function backfill(): void
    {
        DB::table('material_lots')
            ->select('id', 'grn_id', 'goods_receipt_note_line_id', 'item_id')
            ->orderBy('id')
            ->chunk(500, function ($lots): void {
                foreach ($lots as $lot) {
                    $resolved = $this->resolveRate($lot);

                    if ($resolved === null) {
                        continue;
                    }

                    [$rate, $source] = $resolved;

                    DB::table('material_lots')->where('id', $lot->id)->update([
                        'receipt_rate_per_kg' => $rate,
                        'rate_source' => $source,
                    ]);
                }
            });
    }

    /** @return array{0: string, 1: string}|null */
    private function resolveRate(object $lot): ?array
    {
        if ($lot->goods_receipt_note_line_id !== null) {
            $unitCost = DB::table('goods_receipt_note_lines')
                ->where('id', $lot->goods_receipt_note_line_id)
                ->value('unit_cost');

            if ($unitCost !== null) {
                return [$this->scale($unitCost), 'grn'];
            }
        }

        if ($lot->grn_id === null) {
            return null;
        }

        $unitCost = DB::table('goods_receipt_note_lines')
            ->where('goods_receipt_note_id', $lot->grn_id)
            ->where('item_id', $lot->item_id)
            ->orderBy('id')
            ->value('unit_cost');

        if ($unitCost !== null) {
            return [$this->scale($unitCost), 'grn'];
        }

        $purchaseOrderId = DB::table('goods_receipt_notes')
            ->where('id', $lot->grn_id)
            ->value('purchase_order_id');

        if ($purchaseOrderId === null) {
            return null;
        }

        $unitPrice = DB::table('purchase_order_lines')
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('item_id', $lot->item_id)
            ->orderBy('id')
            ->value('unit_price');

        return $unitPrice === null ? null : [$this->scale($unitPrice), 'po'];
    }

    private function scale(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }
};
