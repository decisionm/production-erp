<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PACKING MATERIAL MAPPINGS — which Tally item a workbook spec string means.
 *
 * The owner asked for carton, tray, film and tape to be calculated the way
 * resin and masterbatch already are. The blocker was never the arithmetic: it
 * was that `production_standards.carton_spec` says "170ML" and Tally says
 * "170 Ml Master Box", and nothing in this database connected the two. This
 * table is that connection.
 *
 * Why a table rather than a Data class next to TapeMetresPerBox:
 *
 *  - TapeMetresPerBox holds a figure the owner STATED, unchanging until they
 *    state another. This holds a JOIN that is still incomplete on the
 *    owner's own account — which cartons seal with Green tape rather than
 *    Transparent is explicitly unanswered, and "500ML IFF" matches two
 *    catalogue rows. Those answers arrive from a person, one at a time,
 *    through the API. A deploy per answer is the thing this factory has
 *    already told us not to build.
 *  - The seed below resolves what it can BY EVIDENCE (a name match against
 *    the real items table, at migration time) and leaves the rest empty.
 *    An empty row is a question on the screen; a guessed row is the wrong
 *    carton on a real dispatch.
 *
 * The dose columns are per-kind and both nullable, because three of the four
 * kinds do not use both:
 *
 *  - carton / tray take neither: one carton packed is one carton consumed,
 *    and a column holding a literal 1 is a column that can drift to 2.
 *  - pouch_film takes `grams_per_piece` — Tally weighs film in Kgs while the
 *    item name states the weight of ONE piece ("…x120G" = 120 g each), and
 *    the film is consumed per CARTON (owner, 31 Jul).
 *  - tape takes `metres_per_box`, the owner's own unit. A tape row's
 *    `spec_value` is the CARTON spec, because tape is dosed by the box it
 *    seals — see PackingMaterialMapping's docblock.
 *
 * NOT a BOM, and nothing here is consumed or posted. It produces a prefill;
 * the supervisor's submitted line is what reaches Tally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing_material_mappings', function (Blueprint $table) {
            $table->id();

            // carton | tray | pouch_film | tape. A string rather than an enum
            // column: adding a kind (the "500 Ml PAD" the catalogue carries
            // and no spec yet demands) must not need an ALTER on a live
            // instance. Validated in the FormRequest against the model's
            // KINDS list, which is the one place the set is stated.
            $table->string('spec_kind', 32);

            // The workbook string, VERBATIM — "170ML", "200ML BRUTE",
            // "LD 28.5 X 38", "HM 30.5*49". Stored as the sheet spells it so
            // a person can read this table against the sheet; the lookup
            // folds case and spacing so "60 ML" and "60ML" still find one
            // row each without either being silently rewritten.
            $table->string('spec_value', 120);

            // The Tally packing item. cascadeOnDelete, NOT nullOnDelete: a
            // mapping whose item is gone is not a mapping, and a row with a
            // null item would read as "this spec deliberately has no
            // material" — which is the opposite of what it would mean.
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();

            // Film only: grams of ONE piece, parsed from the item's own name
            // at seed time and recorded in the note. decimal, never float —
            // it multiplies into a kg quantity.
            $table->decimal('grams_per_piece', 12, 4)->nullable();

            // Tape only: metres per box, from TapeMetresPerBox (owner,
            // 31 Jul). 4dp holds the table's 3dp figures exactly.
            $table->decimal('metres_per_box', 12, 4)->nullable();

            // Provenance, free text. For a seeded row it states HOW the item
            // was matched; for an edited one, who said so. A mapping nobody
            // can attribute is one nobody can defend when the packing
            // variance is questioned.
            $table->text('note')->nullable();

            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('set_at')->nullable();

            $table->timestamps();
            // Withdrawing a mapping returns the floor to "no prefill" without
            // erasing that it once applied — shifts prefilled from it while
            // it was in force stay explainable.
            $table->softDeletes();

            // One mapping per (kind, spec). Explicitly named and short:
            // the generated name would be 53 characters, inside MySQL's 64
            // limit, but every index in this repo is named deliberately since
            // the 65-character failure that stopped a live migration
            // half-applied. See MigrationIdentifierLengthTest.
            //
            // NOTE: soft-deleted rows still occupy the unique slot, which is
            // why the service's upsert() looks withTrashed() and RESTORES
            // rather than inserting a second row.
            $table->unique(['spec_kind', 'spec_value'], 'packing_map_kind_value_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_material_mappings');
    }
};
