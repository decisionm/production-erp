<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTERBATCH DOSING — grams of masterbatch per bottle, as master data.
 *
 * Why a table and not a config constant or a BOM line:
 *
 *  - config/production.php would put the figure in a DEPLOY. The factory
 *    gave 0.25 g/bottle for amber on 31-Jul and will give the others (white,
 *    red/brown) later, product by product; each one must be enterable by an
 *    authorized user in the app, the same way the day-bin warehouse and the
 *    machine configurations already are.
 *  - a BOM would make the dosing a RECIPE, and the owner's standing decision
 *    is that the supervisor's weighed kg stays the truth. This table only
 *    ever produces a PREFILL. Nothing here is consumed, posted, or reconciled
 *    against — the entry's own shift_material_consumptions row is.
 *
 * grams per BOTTLE, not a percentage of shot weight. That is the unit the
 * factory quoted ("for master amber 0.25 is the value per bottle"), and
 * storing a derived percentage instead would bake in an assumption about
 * which weight it is a percentage OF — the exact ambiguity
 * config('production.masterbatch_basis') is still flagging as unconfirmed.
 *
 * ABSENT means "no prefill". There is deliberately no default and no zero
 * row: a zero would assert that a colour needs no masterbatch, which is a
 * factory statement nobody has made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masterbatch_dosings', function (Blueprint $table) {
            $table->id();

            // The masterbatch item itself ("Master Batch Amber"). Not a
            // colour string: the dosing belongs to the material that gets
            // weighed and posted to Tally, and a colour cannot be consumed.
            $table->foreignId('masterbatch_item_id')->constrained('items')->cascadeOnDelete();

            // Optional product scope, for "when the factory later says a
            // bottle differs". NULL = applies to every product that uses
            // this masterbatch, which is the only thing the factory has
            // stated so far.
            //
            // cascadeOnDelete, NOT nullOnDelete: nulling this column would
            // silently PROMOTE a one-product dosing into a factory-wide one
            // the moment that product was deleted. Losing the row is honest;
            // silently widening its scope is not.
            $table->foreignId('product_item_id')->nullable()->constrained('items')->cascadeOnDelete();

            // Grams per bottle. decimal, never float — money and quantity
            // rule from CLAUDE.md, and this figure multiplies into a kg
            // quantity that reaches Tally. 4dp holds 0.2500 exactly.
            $table->decimal('grams_per_bottle', 12, 4);

            // Provenance, free text — "factory, 31 Jul". The figure's
            // authority is WHO said it, and a dosing whose origin nobody can
            // name is one nobody can defend when the variance is questioned.
            $table->text('note')->nullable();

            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('set_at')->nullable();

            $table->timestamps();
            // The factory can withdraw a figure (back to "no prefill")
            // without erasing that it once applied — the same soft-delete
            // rule every master with history follows.
            $table->softDeletes();

            // Explicit SHORT name: the generated one
            // (masterbatch_dosings_masterbatch_item_id_product_item_id_unique)
            // is 63 characters — inside MySQL's 64 limit by one character,
            // which is not a margin worth shipping. See
            // MigrationIdentifierLengthTest.
            //
            // NOTE: with product_item_id NULL, neither MySQL nor SQLite
            // treats two rows as duplicates — so this index cannot be the
            // only defence. MasterbatchDosingService::resolve() orders
            // deterministically (product-scoped, then factory-wide, then
            // lowest id) so even a duplicate cannot produce a prefill that
            // changes between two reads.
            $table->unique(['masterbatch_item_id', 'product_item_id'], 'mb_dosings_material_product_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masterbatch_dosings');
    }
};
