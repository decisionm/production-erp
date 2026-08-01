<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audited cost versions for a material lot.
 *
 * The owner's rule: a GRN rate is PROVISIONAL. The purchase invoice, the
 * freight bill and the landed-cost workings all arrive after the resin is
 * already in the store — sometimes after it has already been consumed. So
 * the lot's cost is not one number, it is a HISTORY of numbers, and the
 * first entry in that history (kind = 'receipt') is never edited.
 *
 * APPEND-ONLY, structurally:
 *   - there is no update or delete route onto this table;
 *   - a revision is a NEW row whose supersedes_id points at the row it
 *     replaces, so the chain from any version back to the original receipt
 *     rate is walkable;
 *   - there is no updated_at column, because a row here never changes.
 *
 * WHAT A VERSION DOES NOT DO: it does not touch stock_balances, it does not
 * write a stock_movement, it does not re-price a completed batch, and it
 * never reaches Tally. The moving-average valuation Accounts approved stays
 * exactly as it is. A version is a RECORDED FACT that future readers may
 * consult — a closed batch is never silently rewritten by one.
 */
return new class extends Migration
{
    private const BACKFILL_NOTE = 'Original goods-receipt rate, recorded automatically when cost versioning was introduced.';

    public function up(): void
    {
        // RE-RUNNABLE: MySQL DDL is non-transactional, so a backfill that
        // died mid-loop would leave this migration unrecorded and replayed —
        // the create and the insert loop below must both survive that.
        if (Schema::hasTable('material_cost_versions')) {
            $this->backfillReceiptVersions();

            return;
        }

        Schema::create('material_cost_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_lot_id')->constrained('material_lots')->cascadeOnDelete();
            $table->decimal('rate_per_kg', 15, 4);
            // 'receipt' | 'invoice' | 'landed_cost' | 'correction'.
            $table->string('kind', 20);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // created_at only. No updated_at: an append-only row has no
            // "last modified", and a column for one would invite an UPDATE.
            $table->timestamp('created_at')->nullable();
            // Self-FK: the version this one replaces. NULL on the original
            // receipt version (nothing before it) and on the first revision
            // of a lot that never had a receipt rate.
            $table->foreignId('supersedes_id')->nullable()->constrained('material_cost_versions')->nullOnDelete();

            // "Latest version for this lot" is the hot read — id desc within
            // a lot. Explicitly named: MySQL rejects identifiers over 64.
            $table->index(['material_lot_id', 'id'], 'material_cost_versions_lot_seq_index');
        });

        $this->backfillReceiptVersions();
    }

    public function down(): void
    {
        Schema::dropIfExists('material_cost_versions');
    }

    /**
     * Every lot that has a receipt rate gets its 'receipt' version, so the
     * history starts at the original number rather than at whatever the
     * first revision happens to be.
     *
     * created_by stays NULL: nobody entered these in the app, they arrived
     * with a deploy. Stamping a user id would claim a person recorded a
     * figure they never saw. (Same reasoning as the amber-dosing data
     * migration, 2026_07_31_090002.)
     *
     * created_at mirrors the lot's own created_at where there is one, so the
     * receipt version is not dated later than the receipt it describes.
     */
    private function backfillReceiptVersions(): void
    {
        $now = now();

        DB::table('material_lots')
            ->whereNotNull('receipt_rate_per_kg')
            // Skip lots already carrying any version — the replay guard: a
            // half-finished earlier run inserted some receipt versions, and
            // inserting them twice would fork every later supersedes chain.
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('material_cost_versions')
                    ->whereColumn('material_cost_versions.material_lot_id', 'material_lots.id');
            })
            ->select('id', 'receipt_rate_per_kg', 'created_at')
            ->orderBy('id')
            ->chunk(500, function ($lots) use ($now): void {
                $rows = [];

                foreach ($lots as $lot) {
                    $rows[] = [
                        'material_lot_id' => $lot->id,
                        'rate_per_kg' => $lot->receipt_rate_per_kg,
                        'kind' => 'receipt',
                        'note' => self::BACKFILL_NOTE,
                        'created_by' => null,
                        'created_at' => $lot->created_at ?? $now,
                        'supersedes_id' => null,
                    ];
                }

                if ($rows !== []) {
                    DB::table('material_cost_versions')->insert($rows);
                }
            });
    }
};
