<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: the packing lines a completion was validated against, PERSISTED
 * (§4.16 closed).
 *
 * CompleteBatchRequest already validates `packing_lines[]` in full — one
 * line per mode, derived pieces recomputed server-side, the carton total
 * and the piece total forced to add up — and until now completeBatch()
 * discarded them: the record of HOW a batch was packed (2 pouch cartons +
 * 3 loose pouches, 3 tray cartons + 1 loose tray) collapsed into one
 * quantity_produced. This table stores exactly the validated wire line,
 * figure for figure, in the completion's own transaction; an amendment
 * replaces the set (the correction is a completion retyped).
 *
 * Column names are the wire names on purpose — a reader comparing a stored
 * line with the payload the SPA sent must not need a translation table.
 * `position` keeps the order typed. Additive: nothing existing reads or
 * writes it, and down() drops only this table.
 *
 * Every index/foreign-key name is explicit: the table name is long enough
 * that Laravel's generated names ("…_shift_production_entry_id_foreign",
 * 70 chars) would pass sqlite and fail MySQL's 64-char cap mid-deploy —
 * MigrationIdentifierLengthTest guards exactly this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_production_entry_packing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_production_entry_id')
                ->constrained(table: 'shift_production_entries', indexName: 'spepl_entry_foreign')
                ->cascadeOnDelete();
            // The packaging option the line cited, when it cited one — a
            // reference for the trace, never re-read for counts (the counts
            // below are the frozen ones the line was validated on).
            $table->foreignId('production_standard_packaging_id')
                ->nullable()
                ->constrained(table: 'production_standard_packagings', indexName: 'spepl_packaging_foreign')
                ->nullOnDelete();
            $table->unsignedSmallInteger('position');
            // pouch | tray | direct_box
            $table->string('mode', 16);
            $table->unsignedInteger('boxes');
            $table->unsignedInteger('nos_per_box');
            // Inner containers not yet in a carton; null on direct-box lines
            // and on lines that stated none.
            $table->unsignedInteger('loose_inner')->nullable();
            $table->unsignedInteger('nos_per_inner')->nullable();
            // boxes × nos_per_box + loose_inner × nos_per_inner, recomputed
            // and enforced by the request; stored beside the counted figure
            // so a later reader can see the arithmetic AND the override.
            $table->unsignedInteger('derived_pieces');
            $table->unsignedInteger('actual_pieces');
            $table->string('override_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['shift_production_entry_id', 'position'], 'spepl_entry_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_production_entry_packing_lines');
    }
};
