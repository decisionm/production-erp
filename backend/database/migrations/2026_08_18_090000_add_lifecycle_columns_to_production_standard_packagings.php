<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOMEWHERE FOR A PACKAGING VARIANT TO BE ARCHIVED TO, and someone to
 * attribute the change to.
 *
 * `production_standard_packagings` is the one Tier-1 master in the
 * product-definition set that carries NEITHER an `is_active` flag NOR
 * `deleted_at`. The Configuration Lifecycle Contract (DEC-20260817-002)
 * gives every applicable master Activate/Deactivate, and
 * ConfigurationLifecycle::archive() refuses outright — "neither an active
 * flag nor soft deletes" — for a table shaped like this. Without a column
 * the only way to withdraw a wrong pack variant would be to destroy the
 * row, which is exactly what the contract exists to prevent: a shift that
 * prefilled 840/box from this row has to stay explainable afterwards.
 *
 * The same gap that migration 2026_08_17_090000 closed for the other nine
 * Tier-1 masters is closed here for the tenth: `created_by` / `updated_by`
 * so RecordsConfigurationAudit has stamps to write. Both nullable with no
 * default and no backfill — every existing row keeps the honest answer
 * "nobody in this app wrote this; it came from the workbook import".
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not touch
 * `psp_standard_variant_unique`. An archived variant KEEPS its slot in that
 * index (DEC-20260817-002 §2 — an archived record retains and reserves its
 * business identity), and the service's twin refusal already reads
 * withTrashed-shaped data. Loosening the index to active rows only would be
 * a different decision and is not made here.
 *
 * Strictly additive and reversible: `down()` drops exactly what `up()`
 * added, and only if it is there.
 */
return new class extends Migration
{
    private const TABLE = 'production_standard_packagings';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'deleted_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        foreach (['created_by', 'updated_by'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($column): void {
                // nullOnDelete, as the audit-stamp migration does: a user row
                // disappearing must never take a packing variant with it.
                $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['created_by', 'updated_by'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($column): void {
                $table->dropConstrainedForeignId($column);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'deleted_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
