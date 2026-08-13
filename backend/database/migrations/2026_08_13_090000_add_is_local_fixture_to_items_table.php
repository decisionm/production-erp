<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Move the local-fixture judgment off the SKU and onto a column of its own.
 *
 * WHY. Whether a batch's voucher posts to Tally at all is decided by
 * Item::isLocalFixture(), which until now was `str_starts_with($sku,
 * 'LOCAL-')`. A business-critical gate was riding on a free-text field — and
 * the SKU is about to become owner-managed data, with 644 of 655 items due to
 * be renamed. From that moment one office typo could silently stop a real
 * product posting, or start a fixture posting a name Tally cannot accept.
 * Neither would fail loudly; both would be found weeks later in the queue.
 *
 * A flag is not a better convention than a prefix. It is a different KIND of
 * thing: a prefix is a side effect of naming, and a column is a decision
 * somebody made.
 *
 * The backfill is the prefix rule applied once, deliberately, with the rows it
 * touched named in the log — after which the prefix is only a fallback, and a
 * disagreement between the two is reported rather than resolved silently.
 */
return new class extends Migration
{
    private const PREFIX = 'LOCAL-';

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_local_fixture')->default(false)->after('is_active');
        });

        // The one and only time the prefix decides this. Named in the log so
        // the migration's effect is auditable rather than assumed: a silent
        // backfill of a posting gate is the thing this migration exists to
        // stop happening again.
        $fixtures = DB::table('items')
            ->where('sku', 'like', self::PREFIX.'%')
            ->pluck('sku', 'id');

        if ($fixtures->isEmpty()) {
            Log::info('is_local_fixture backfill: no item carries the '.self::PREFIX.' prefix; nothing flagged.');

            return;
        }

        DB::table('items')->whereIn('id', $fixtures->keys())->update(['is_local_fixture' => true]);

        Log::info(sprintf(
            'is_local_fixture backfill: flagged %d item(s) from the %s prefix — %s',
            $fixtures->count(),
            self::PREFIX,
            $fixtures->values()->implode(', '),
        ));
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('is_local_fixture');
        });
    }
};
