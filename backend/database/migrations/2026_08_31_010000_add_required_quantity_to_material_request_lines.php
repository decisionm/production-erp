<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT THE FLOOR ACTUALLY NEEDED, kept beside what it ended up asking for.
 *
 * Owner decision, 31-Aug-2026: a material request is raised for
 *
 *     net = max(0, total required − usable Production/WIP balance)
 *
 * so the store is not asked to hand over material that is already standing on
 * the floor. `quantity` carries the NET, because every downstream figure --
 * `issued_quantity`, `remaining_quantity`, the store's fulfilment arithmetic
 * -- is computed against it and none of them should learn a new meaning.
 *
 * WITHOUT THIS COLUMN THE NETTING WOULD BE UNAUDITABLE. A request reading
 * "70 kg" is indistinguishable from one where production needed 70 and from
 * one where production needed 100 and the ERP found 30 already in production.
 * Those are different facts about the same day, and the second is the one
 * that explains the request to anybody reading it later. Storing what was
 * required makes the amount netted derivable (`required_quantity − quantity`)
 * without a second column for it.
 *
 * NULLABLE, and that is the honest state rather than a default. Every line
 * raised before this migration has no recorded requirement -- its `quantity`
 * was simply what was asked for -- and backfilling `required_quantity =
 * quantity` would assert that the netting ran and found nothing on the floor,
 * which is a claim about history nobody made. NULL means "not netted".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_request_lines', function (Blueprint $table) {
            // Same precision as `quantity` and `issued_quantity` beside it:
            // these three are subtracted from one another, and a decimal that
            // disagrees with its neighbours is a rounding bug waiting for a
            // large enough number.
            $table->decimal('required_quantity', 15, 4)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('material_request_lines', function (Blueprint $table) {
            $table->dropColumn('required_quantity');
        });
    }
};
