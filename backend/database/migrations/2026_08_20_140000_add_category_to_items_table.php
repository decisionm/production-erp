<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT KIND OF THING AN ITEM IS — the column three document rules need.
 *
 * The factory's rules, stated by the owner on 20-Aug-2026: a purchase order
 * is raised for raw and packing material only; a sales order for finished
 * goods only; a store material request for production input only. None of
 * those could be enforced, because nothing in this database said which items
 * are which. `item_group_id` mirrors Tally's stock groups and is read
 * nowhere; `is_production_input` answers a narrower question and stays;
 * `MeasurementType` classifies the unit, not the material.
 *
 * NULLABLE, WITH NO BACKFILL, AND THAT IS THE POINT.
 *
 * Counted on live before writing this: 624 active items, of which evidence
 * can classify about 166 — 79 finished goods (they carry a production
 * standard), 19 packing materials (they appear in a packing-material
 * mapping), 1 raw material (a masterbatch dosing). The remaining 458 cannot
 * be derived from anything the database holds.
 *
 * So this migration classifies NOTHING. A separate dry-run command proposes
 * the derivable ones for a person to confirm, and the 458 stay NULL until
 * somebody who knows says otherwise. `AGENTS.md`: a missing figure is
 * reported missing, never interpolated — the rule earned when a derived bag
 * weight reached live and had to be withdrawn (PR #128). A migration that
 * defaulted every unknown row to `raw_material` would be that same error,
 * published into a column the document rules then act on.
 *
 * NULL therefore means "nobody has said yet" and never "none of the above" —
 * `ItemCategory::Other` is the second of those. Enforcement reads the
 * difference: a KNOWN wrong category is refused, an unclassified item is
 * allowed and flagged, so turning the rules on cannot block real work on the
 * day it ships.
 *
 * Indexed because every gated document validates against it per line, and
 * the classification screen filters on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->string('category', 32)->nullable()->after('item_group_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
