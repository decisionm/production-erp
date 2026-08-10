<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-packaging Tally identity — DEC-20260810-003.
 *
 * One physical product can be packed more than one way, and this factory's
 * Tally books can carry a DIFFERENT stock item per packing (the raising
 * case: "B.200 Ml Round Pet Bottle Amber 18gms" vs "...- 520 Nos"). So:
 *
 * - `production_standard_packagings.item_id` — the Tally item THIS packing's
 *   production posts as. Null = "no identity of its own": the run falls back
 *   to the product's item, and the screen says so instead of guessing.
 *
 * - `shift_production_entries.finished_item_id` — the identity RESOLVED at
 *   completion, frozen on the entry. A voucher must describe the batch that
 *   ran; resolving live at posting time would let a later edit of the
 *   packaging rewrite what an already-completed batch claims it produced.
 *   Null = completed before this feature, or no per-packaging identity —
 *   both mean "the product's own item", which is exactly the old behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_standard_packagings', function (Blueprint $table) {
            $table->foreignId('item_id')
                ->nullable()
                ->after('production_standard_id')
                ->constrained('items')
                ->nullOnDelete();
            // Provenance of the identity answer — who set it and when, the
            // same pattern the packing-material master carries (set_by /
            // set_at): an identity nobody can attribute is one nobody can
            // defend when a voucher's item name is questioned later.
            $table->foreignId('item_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('item_set_at')->nullable();
        });

        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->foreignId('finished_item_id')
                ->nullable()
                ->after('item_id')
                ->constrained('items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_standard_packagings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
            $table->dropConstrainedForeignId('item_set_by');
            $table->dropColumn('item_set_at');
        });

        Schema::table('shift_production_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finished_item_id');
        });
    }
};
