<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO THIS ITEM IS TO THE FACTORY, next to who it is to Tally.
 *
 * Three nullable columns, no backfill, no behaviour change on the day they
 * ship. Each exists because a question that is already being asked has
 * nowhere to be answered:
 *
 * `display_name` — THE ERP-FACING LABEL. `items.name` is the Tally WIRE KEY:
 * every voucher line carries <STOCKITEMNAME> and Tally matches on it, which
 * is why UpdateItemRequest refuses to rename a Tally-linked item at all. So
 * the catalogue is stuck rendering Tally's spelling ("500ML IFF Tray") on
 * every screen a person reads. This column is the name a PERSON sees; the
 * wire key stays locked and untouched. Nothing writes it automatically —
 * NULL means "nobody has given this item a friendlier name", and every
 * reader falls back to `name`.
 *
 * `variant_of_item_id` — THE BASE-PRODUCT LINK (DEC-20260821-001). The owner
 * decided on 21-Aug-2026 that a pack variant which carries its own Tally
 * stock item (a pouch pack and a tray pack of the same bottle) is a SEPARATE
 * ERP item master with its own 1:1 Tally mapping — not one master with a
 * packing attribute. That decision leaves the two masters with nothing
 * saying they are the same product. This column says it. A base product
 * carries NULL; a variant points at its base.
 *
 * RESTRICT ON DELETE, deliberately. A base product whose variants still
 * exist is not a row anyone may remove: the variants would be left orphaned
 * with no way to say what they are variants OF. The database refusal is the
 * backstop only — `ItemService::dependencyChecks()` declares this column too,
 * so the refusal a person actually meets is the Configuration Lifecycle
 * Contract's 422-with-counts (DEC-20260817-002), not a QueryException.
 *
 * `variant_label` — WHAT MAKES THIS VARIANT DIFFERENT, in the factory's own
 * words ("840/box pouch"). DEC-20260806-011 wants the box count visible on
 * the identity itself rather than reconstructed from the packing standard,
 * because the two masters differ by exactly that number and a person picking
 * one from a list has nothing else to go on. Free text on purpose: it is a
 * LABEL, and nothing computes from it.
 *
 * NOT GUESSED, ANYWHERE. Q33 leaves the 200ML 490-tray Tally identity
 * unevidenced and it stays NULL; AGENTS.md forbids inventing a factory
 * value, and a fabricated variant link would be exactly that. No data
 * migration accompanies these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('name');

            // Self-referencing, one level: a variant points at a BASE, and a
            // base is an item whose own variant_of_item_id is NULL. The
            // flatten-to-base rule is enforced in the write path
            // (ValidatesVariantLink) rather than by the schema, which cannot
            // express it.
            $table->foreignId('variant_of_item_id')
                ->nullable()
                ->after('display_name')
                ->constrained('items')
                ->restrictOnDelete();

            $table->string('variant_label')->nullable()->after('variant_of_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('variant_of_item_id');
            $table->dropColumn(['display_name', 'variant_label']);
        });
    }
};
