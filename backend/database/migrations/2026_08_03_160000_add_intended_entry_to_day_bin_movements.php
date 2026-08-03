<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH BATCH THE OPERATOR SAID THEY WERE LOADING FOR.
 *
 * A DELIBERATELY SEPARATE COLUMN from `shift_production_entry_id`, and the
 * separation is the whole point. That column means "this movement belongs to
 * this batch" — it is read as fact. This one means "the person at the common
 * input picked this batch on the screen", which is a statement of intent made
 * before the material moved.
 *
 * Once resin enters the common input it mixes. No record can afterwards
 * recover which kilogram went into which batch, and the owner's 2-Aug ruling
 * removed the bag-to-batch claim precisely because the old design asserted one.
 * Writing intent into the factual column would quietly rebuild that claim under
 * a new name — a report joining on it would print bag barcodes against batches
 * again, and be believed.
 *
 * So: recorded because it is operationally useful (it says what the floor was
 * doing, and it is the only link between a scan and a run that the process
 * actually supports), and named `intended_` so that no query, screen or export
 * can use it as proof by accident.
 *
 * Nullable forever. Loading before Start Batch is normal — the floor tops the
 * common input up between runs — and a scan with no batch chosen yet is a
 * complete, correct record, not a deficient one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_bin_movements', function (Blueprint $table) {
            $table->foreignId('intended_shift_production_entry_id')
                ->nullable()
                ->after('shift_production_entry_id')
                ->constrained('shift_production_entries')
                // The batch is a REFERENCE, never an owner. A cancelled or
                // deleted entry must not take the load record with it: the
                // kilograms were really poured, and the history of the common
                // input has to survive whatever happens to the run they were
                // aimed at.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('day_bin_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('intended_shift_production_entry_id');
        });
    }
};
