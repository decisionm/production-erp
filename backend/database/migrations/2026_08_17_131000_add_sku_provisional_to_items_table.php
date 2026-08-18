<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * items.sku_provisional — "this SKU was seeded, not chosen" (Phase 5, P5-02).
 *
 * WHY. Every NEW stock item the masters pull creates arrives with a SKU
 * copied from its Tally name (ItemService::uniqueSkuFrom): the ERP needs a
 * unique SKU to store the row, and the name is the only thing it has. That
 * value is a placeholder, and until now nothing recorded that it was one —
 * so a name-shaped SKU a person had deliberately confirmed and one nobody had
 * ever looked at read the same. The SKU format programme is the OWNER'S and
 * is held (no format is invented here); what this column adds is provenance,
 * so the review surface can list the rows a person has not yet decided.
 *
 * Additive and reversible: a nullable-free boolean defaulting to false, no
 * backfill. Existing rows are NOT flagged by inference (sku == name would be
 * a rule this migration invents about the past); the create path flags what
 * it creates from now on, and the manual item update clears it when a person
 * types a SKU of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('sku_provisional')->default(false)->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('sku_provisional');
        });
    }
};
