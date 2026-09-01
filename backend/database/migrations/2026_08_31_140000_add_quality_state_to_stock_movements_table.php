<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stock_movements.quality_state — the condition material came back in, beside
 * `purpose`, which says why it moved but not what state it was in.
 *
 * Written ONLY by the production-return path (enum ReturnedQualityState:
 * good | damaged). Every other movement leaves it null, and null is not a
 * missing answer there — a receipt, a consumption and a dispatch are not
 * being asked this question at all.
 *
 * Additive and nullable, following 2026_08_17_150000 (purpose) exactly: the
 * column arrives empty on every existing row, no backfill runs, and a
 * historical return reads as `good` in PHP because that is what the factory
 * was recording when it had no way to say otherwise. A backfill that stamped
 * 'good' on rows nobody wrote it on would turn an absence into a claim.
 *
 * Plain string with the enum enforced in PHP, like `type` and `purpose` — a
 * DB enum makes every future state a schema change.
 *
 * Indexed with `purpose` rather than alone: the read this exists for is
 * "damaged returns", which is always both columns at once (purpose =
 * return_from_production AND quality_state = damaged). An index on
 * quality_state by itself would cover a query nobody runs — the column is
 * null on the overwhelming majority of the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('quality_state', 32)->nullable()->after('purpose');
            $table->index(['purpose', 'quality_state'], 'stock_movements_purpose_quality_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_purpose_quality_index');
            $table->dropColumn('quality_state');
        });
    }
};
