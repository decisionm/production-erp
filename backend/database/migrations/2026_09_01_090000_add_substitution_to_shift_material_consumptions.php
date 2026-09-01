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
 * THE SAME TWO COLUMNS PR #71 PUTS ON store_issue_lines, deliberately.
 * DEC-20260901-004 is the owner's rule for a substitution anywhere in the
 * flow: "the substitute line must NAME WHAT IT STANDS IN FOR AND WHY: the
 * item it substitutes for, and a reason, both recorded against the line."
 * That surface is the STORE ISSUE — what the storekeeper handed over. This
 * one is the PRODUCTION COMPLETION — what the machine actually ate. Two
 * different documents, one rule, and therefore one column shape:
 *
 *   substitutes_item_id   the item this line stood in for. A line IS a
 *                         substitution when, and only when, this is set —
 *                         the same predicate StoreIssueLine uses.
 *   substitution_reason   why, in the person's own words. Required by
 *                         CompleteBatchRequest whenever the item is named.
 *
 * A separate boolean was the first shape here and was WRONG: it recorded
 * that a swap happened without recording what was swapped, which is half of
 * what DEC-20260901-004 calls controlled. The owner's "any active stock item"
 * (DEC-20260901-007) sets how WIDE the dropdown is; it does not excuse the
 * line from naming what it replaced.
 *
 * WHO is already answered: shift_material_consumptions.created_by has carried
 * the completing user since the table was created.
 *
 * The permission is the gate: a scoped permission plus an explicit per-line
 * flag, so the substitution is a recorded decision and never an accident —
 * the shape production.override-fifo established (2026_07_28_200005).
 *
 * IT IS NAMED material-substitution.manage, NOT production.substitute-material,
 * and the difference is load-bearing. RoleService intersects every grant with
 * PermissionService::MODULES, so a permission absent from that catalog is
 * stripped from every role the next time anybody saves through the Roles
 * screen — the grant would work until an unrelated role edit silently removed
 * it, and the floor would then be refused with no visible cause.
 * material-substitution is a catalog module for that reason, following
 * carton-trace and configuration-delete. (override-fifo sits outside the
 * catalog and carries that latent problem; it was not copied.)
 *
 * findOrCreate here as well as in PermissionSeeder is deliberate: the
 * migration must leave the permission existing on an instance that has not
 * re-seeded yet, and both calls are idempotent.
 *
 * NOTHING IS BACKFILLED. Existing rows keep a null substitutes_item_id, which
 * is the honest answer: nobody recorded a substitution on them, and inferring
 * one now from item names would be inventing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_material_consumptions', function (Blueprint $table) {
            // restrictOnDelete matches item_id beside it, and matches
            // store_issue_lines.substitutes_item_id: an item named by a
            // substitution is part of that shift's history.
            $table->foreignId('substitutes_item_id')
                ->nullable()
                ->after('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            $table->string('substitution_reason', 500)
                ->nullable()
                ->after('substitutes_item_id');
        });

        Permission::findOrCreate('material-substitution.manage', 'web');
    }

    public function down(): void
    {
        Schema::table('shift_material_consumptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitutes_item_id');
            $table->dropColumn('substitution_reason');
        });
    }
};
