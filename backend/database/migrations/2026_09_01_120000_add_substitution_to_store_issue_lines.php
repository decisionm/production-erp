<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store issue line that stands in for a DIFFERENT material (DEC-20260901-004).
 *
 * Two columns, and no new table, because a substitution is not a new kind of
 * document — it is an ordinary issue line that says what it replaced. The
 * original line is untouched and keeps the quantity of the original material
 * that was actually used; the substitute is a second line against the SAME
 * material_request_line_id, which that column already allows (it is indexed,
 * never unique) and which StoreIssueService already sums correctly.
 *
 * Both are nullable because the overwhelming majority of lines are not
 * substitutions, and a line is a substitution when — and only when —
 * substitutes_item_id is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_issue_lines', function (Blueprint $table) {
            // The item this line stands in for: the requirement's own item.
            // restrictOnDelete matches item_id beside it — an item named by a
            // substitution is part of that handover's history.
            $table->foreignId('substitutes_item_id')
                ->nullable()
                ->after('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            // WHY the alternate was used. This is the whole of "controlled":
            // without it a substitute line is indistinguishable from an
            // ordinary issue of some other item, which is the gap
            // DEC-20260901-004 closes.
            $table->string('substitution_reason', 500)
                ->nullable()
                ->after('substitutes_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('store_issue_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitutes_item_id');
            $table->dropColumn('substitution_reason');
        });
    }
};
