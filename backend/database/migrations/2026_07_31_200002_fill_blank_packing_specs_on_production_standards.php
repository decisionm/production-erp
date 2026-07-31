<?php

use App\Modules\Production\Data\PackingSpecInferences;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fill the packing-material specs the workbook left blank, on the standards
 * already imported.
 *
 * The list, the evidence for each fill and the reasons the refused ones stay
 * blank all live in {@see PackingSpecInferences}; this migration is a caller.
 * That is deliberate — the same list has to run twice from two places (here,
 * for rows already in the database, and inside the importer, so a re-import of
 * the workbook does not blank them again) and a rule that exists twice
 * eventually disagrees with itself.
 *
 * Fills BLANKS ONLY and is safe to run again: a value a person has typed since
 * outranks the inference, and a second run finds nothing to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The provenance column arrives in the migration immediately before
        // this one. Guarded anyway: this file must be a no-op rather than a
        // fatal on any database where that one has not landed.
        if (! Schema::hasColumn('production_standards', 'spec_provenance')) {
            return;
        }

        PackingSpecInferences::backfill();
    }

    public function down(): void
    {
        // Deliberately empty. Rolling back would blank a spec that someone may
        // have confirmed or corrected in the meantime, and the provenance
        // record already says which values were inferred — removing them is a
        // decision for a person, not for a schema rollback.
    }
};
