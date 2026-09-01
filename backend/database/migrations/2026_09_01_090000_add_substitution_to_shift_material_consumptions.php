<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * A CONSUMPTION LINE THE RUN DID NOT PLAN FOR, RECORDED AS ONE.
 *
 * When a required packing or consumption material runs short mid-run, the
 * floor reaches for whatever is standing on it. That already happened; what
 * did not happen was the ERP knowing. The line was submitted as an ordinary
 * material_consumptions row and looked, forever after, exactly like a planned
 * one — which is the silent substitution the owner's rule forbids.
 *
 * TWO COLUMNS, NOT THREE. The owner's answer (01-Sep-2026) is that the
 * dropdown may offer ANY active stock item, not a paired alternative for the
 * short one — so there is no reliable "substituted for" item to point at, and
 * a column inviting one would be filled with a guess. What the rule actually
 * demands is that the line be visibly distinct, attributed and reasoned:
 *
 *   is_substitution       this line was added at completion, off-plan.
 *   substitution_reason   why, in the person's own words. Required by
 *                         CompleteBatchRequest whenever the flag is set.
 *
 * WHO is already answered: shift_material_consumptions.created_by has carried
 * the completing user since the table was created.
 *
 * The permission is the gate. production.substitute-material follows the
 * production.override-fifo precedent exactly (2026_07_28_200005): a scoped
 * permission plus an explicit per-line flag, so the substitution is a
 * recorded decision and never an accident. findOrCreate keeps it idempotent
 * and re-runnable on an instance that already has it.
 *
 * NOTHING IS BACKFILLED. Existing rows get is_substitution = false, which is
 * the honest answer: nobody recorded a substitution on them, and inferring
 * one now from item names would be inventing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_material_consumptions', function (Blueprint $table) {
            $table->boolean('is_substitution')->default(false)->after('quantity_issued_kg');
            $table->string('substitution_reason', 255)->nullable()->after('is_substitution');
        });

        Permission::findOrCreate('production.substitute-material', 'web');
    }

    public function down(): void
    {
        Schema::table('shift_material_consumptions', function (Blueprint $table) {
            $table->dropColumn(['is_substitution', 'substitution_reason']);
        });
    }
};
