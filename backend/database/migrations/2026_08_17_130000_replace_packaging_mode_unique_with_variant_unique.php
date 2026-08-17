<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5, P5-01 (D1): the variant key of a packaging is the mode PLUS its
 * counts, not the mode alone.
 *
 * `psp_standard_mode_unique` (production_standard_id, mode) made two
 * same-mode packings of one standard unrepresentable — the raising case is
 * one product tray-packed at 490/box and at 520/box (DEC-20260810-003, Q33
 * is the owner's; nothing here decides which Tally item either posts as).
 * The 490 tray data patch (2026_08_10_191000) would itself have tripped
 * this index on any standard that already carried a tray row.
 *
 * Replaced by `psp_standard_variant_unique` over the standard, the mode and
 * the five counts. Additive-safe: no existing row can violate a unique
 * that is a strict superset of the one it replaces. NULL semantics apply
 * (a NULL count never collides), so the exact-twin refusal a person sees
 * lives in ProductionStandardPackagingService — this index is the backstop
 * for fully-stated rows and, above all, the removal of the old refusal.
 *
 * Both names are explicit and short: MySQL caps identifiers at 64 chars
 * and the generated name for seven columns would be far past it. Guarded
 * with the same hasIndex() pattern as 2026_07_29_120001, so a half-applied
 * run on live completes rather than trips over its own work.
 */
return new class extends Migration
{
    private const OLD_INDEX = 'psp_standard_mode_unique';

    private const NEW_INDEX = 'psp_standard_variant_unique';

    private const VARIANT_COLUMNS = [
        'production_standard_id', 'mode',
        'nos_per_box', 'nos_per_tray', 'trays_per_box', 'nos_per_pouch', 'pouches_per_box',
    ];

    public function up(): void
    {
        // The new index FIRST, so at no instant is the table without a
        // uniqueness rule at all.
        if (! $this->hasIndex('production_standard_packagings', self::NEW_INDEX)) {
            Schema::table('production_standard_packagings', function (Blueprint $table) {
                $table->unique(self::VARIANT_COLUMNS, self::NEW_INDEX);
            });
        }

        if ($this->hasIndex('production_standard_packagings', self::OLD_INDEX)) {
            Schema::table('production_standard_packagings', function (Blueprint $table) {
                $table->dropUnique(self::OLD_INDEX);
            });
        }
    }

    public function down(): void
    {
        // Reversible only while the data allows it: a database that already
        // holds two same-mode packings on one standard cannot take the old
        // (standard, mode) unique back, and the DDL says so loudly rather
        // than deleting a row to make room.
        if (! $this->hasIndex('production_standard_packagings', self::OLD_INDEX)) {
            Schema::table('production_standard_packagings', function (Blueprint $table) {
                $table->unique(['production_standard_id', 'mode'], self::OLD_INDEX);
            });
        }

        if ($this->hasIndex('production_standard_packagings', self::NEW_INDEX)) {
            Schema::table('production_standard_packagings', function (Blueprint $table) {
                $table->dropUnique(self::NEW_INDEX);
            });
        }
    }

    /** Portable enough for the two drivers this project runs on (2026_07_29_120001's helper, verbatim). */
    private function hasIndex(string $table, string $index): bool
    {
        try {
            return Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->introspectTable($table)
                ->hasIndex($index);
        } catch (Throwable) {
            // No Doctrine (Laravel 11+ drops it): fall back to the driver.
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'sqlite') {
                $rows = Schema::getConnection()->select("PRAGMA index_list('{$table}')");

                return collect($rows)->contains(fn ($r) => ($r->name ?? '') === $index);
            }

            $rows = Schema::getConnection()->select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $index],
            );

            return $rows !== [];
        }
    }
};
