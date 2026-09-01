<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT THE ADDED LINE STOOD IN FOR (DEC-20260901-004).
 *
 * The migration before this one gave an added consumption line its reason and
 * its author — the WHY and the WHO. DEC-20260901-004 asks for a third thing,
 * in terms:
 *
 *   "the substitute line must NAME WHAT IT STANDS IN FOR AND WHY: the item it
 *    substitutes for, and a reason, both recorded against the line."
 *
 * The completion drawer's own refusal message already promised it — "the
 * material it stood in for stays on the batch either way" — with no column
 * behind the promise. This is that column, and it is the same one
 * `store_issue_lines` carries (PR #71), so a substitution answers the same
 * question the same way at both points in the flow: the storekeeper's handover
 * and the machine's actual consumption.
 *
 * NULLABLE, AND DELIBERATELY NOT REQUIRED ON EVERY ADDED LINE. An added line
 * is not always a substitution. A run may book a consumable it genuinely
 * needed and that stood in for nothing; forcing it to name a replaced material
 * would make somebody invent one, and an invented factory value is the failure
 * this repo has already paid for once (PR #128). So: every added line still
 * needs its reason, and a line that IS a substitution also names what it
 * replaced.
 *
 * restrictOnDelete, matching item_id beside it and matching
 * store_issue_lines.substitutes_item_id: an item named by a substitution is
 * part of that shift's history. ItemService declares a DependencyCheck for it
 * so a configuration hard delete refuses with the lifecycle's 422-and-counts
 * rather than a QueryException 500 (DEC-20260817-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_material_consumptions', function (Blueprint $table) {
            $table->foreignId('substitutes_item_id')
                ->nullable()
                ->after('item_id')
                ->constrained('items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_material_consumptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitutes_item_id');
        });
    }
};
