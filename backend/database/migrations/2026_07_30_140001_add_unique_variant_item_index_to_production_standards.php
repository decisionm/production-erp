<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Row identity for a production standard, enforced by the database.
 *
 * A standard row is one variant OF one item: (item_id, source_product_name,
 * cavities, unit_weight_grams, cycle_time). One mould standard covers every
 * colour variant of its bottle, so the same variant legitimately appears once
 * per matched Tally item — and must not appear twice for the same one.
 *
 * Until now nothing but the importer's own updateOrCreate call prevented
 * duplicates, so two concurrent imports, or one interrupted midway, could leave
 * a product carrying two standards with different figures. Nothing downstream
 * can choose between those: ProductionStandardResolver::variantsFor() filters on
 * item_id alone, so the Start Batch picker would offer a supervisor two
 * identical-looking options with different cycle times.
 *
 * Named explicitly. Laravel's generated name for a five-column unique index on
 * this table runs well past MySQL's 64-character identifier limit — the same
 * trap that psp_standard_mode_unique was named around, and one that only
 * surfaces on a real server because SQLite does not enforce it.
 *
 * Nullable item_id is deliberate and unaffected: an unmatched standard is
 * visible work rather than a silent omission, and MySQL treats NULLs as
 * distinct in a unique index, so those rows are never in conflict.
 */
return new class extends Migration
{
    private const INDEX = 'ps_item_variant_unique';

    public function up(): void
    {
        if ($this->hasIndex()) {
            return;
        }

        // A pre-existing duplicate would abort the migration. Collapse any
        // first, keeping the lowest id — the alternative is a deploy that fails
        // on data written before the constraint existed.
        $this->collapseDuplicates();

        Schema::table('production_standards', function (Blueprint $table) {
            $table->unique(
                ['item_id', 'source_product_name', 'cavities', 'unit_weight_grams', 'cycle_time'],
                self::INDEX,
            );
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex()) {
            return;
        }

        Schema::table('production_standards', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }

    private function collapseDuplicates(): void
    {
        $keep = Schema::getConnection()
            ->table('production_standards')
            ->selectRaw('MIN(id) as id')
            ->whereNull('deleted_at')
            ->groupBy('item_id', 'source_product_name', 'cavities', 'unit_weight_grams', 'cycle_time')
            ->pluck('id');

        Schema::getConnection()
            ->table('production_standards')
            ->whereNull('deleted_at')
            ->whereNotIn('id', $keep)
            ->update(['deleted_at' => now()]);
    }

    /** Portable across the two drivers this project runs on. */
    private function hasIndex(): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('production_standards')");

            return collect($rows)->contains(fn ($r) => ($r->name ?? '') === self::INDEX);
        }

        $rows = $connection->select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['production_standards', self::INDEX],
        );

        return $rows !== [];
    }
};
