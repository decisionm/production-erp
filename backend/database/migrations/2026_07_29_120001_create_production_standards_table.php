<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRODUCT-level production standards, from the factory's own master
 * (ERPPRO29072026.xlsx).
 *
 * Distinct from production_configurations, which is machine + product +
 * mould + colour. The factory's master carries no machine column, so these
 * are the standards a product runs to WHEREVER it runs — available to every
 * active machine in watch mode until machine-specific mappings are
 * confirmed.
 *
 * The variant key is product + cavities + weight + cycle time. Deliberately
 * NOT product + packaging mode: the same standard packed in a pouch and in a
 * tray is one standard with two packaging options, not two standards. Two
 * rows that differ in cavities, weight or cycle time ARE different standards
 * and stay separately selectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded creates: a previous run of this migration created both
        // tables and then failed on the index, leaving the migration
        // unrecorded. Re-running must complete the job rather than trip
        // over its own half-finished work.
        if (! Schema::hasTable('production_standards')) {
            Schema::create('production_standards', function (Blueprint $table) {
                $table->id();

                // The Tally item, once the factory name is matched. Nullable so
                // an unmatched row is still imported and visible as work to do,
                // rather than silently dropped.
                $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();

                // The factory's own product wording, kept verbatim — it is how
                // the floor and the workbook refer to it, and it is the key to
                // resolving the Tally name later.
                $table->string('source_product_name');

                $table->unsignedSmallInteger('cavities')->nullable();
                $table->decimal('unit_weight_grams', 12, 4)->nullable();
                $table->decimal('cycle_time', 8, 2)->nullable();

                // The raw cell when it could not be read as one number, e.g.
                // "21.5 / 17.8". Split into separate unresolved variants rather
                // than averaged — an average of two real cycle times is a rate
                // no machine actually runs at.
                $table->string('cycle_time_raw', 64)->nullable();

                // draft | approved | unresolved
                // unresolved = imported but carrying an ambiguity (multi-valued
                // cycle time, blank required field) that a person must settle.
                $table->string('status', 16)->default('draft');
                $table->text('unresolved_reason')->nullable();

                $table->string('source', 64)->nullable();
                $table->string('source_reference', 64)->nullable();
                $table->string('confirmation_status', 32)->nullable();
                $table->text('notes')->nullable();

                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['item_id', 'status']);
                $table->index('source_product_name');
            });
        }

        if (! Schema::hasTable('production_standard_packagings')) {
            Schema::create('production_standard_packagings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('production_standard_id')->constrained()->cascadeOnDelete();

                // pouch | tray | direct_box — how the pieces reach a box.
                $table->string('mode', 16);

                $table->unsignedInteger('nos_per_pouch')->nullable();
                $table->unsignedInteger('pouches_per_box')->nullable();
                $table->unsignedInteger('nos_per_tray')->nullable();
                $table->unsignedInteger('trays_per_box')->nullable();
                // Always populated: boxes are the completion input, so pieces
                // per box is the one figure every mode must be able to answer.
                $table->unsignedInteger('nos_per_box')->nullable();

                // When a standard has exactly one option it is the default and
                // the supervisor is never asked to choose.
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        // Named explicitly. Laravel's generated name,
        // production_standard_packagings_production_standard_id_mode_unique,
        // is 65 characters and MySQL caps identifiers at 64 — sqlite does
        // not enforce that limit, so this only surfaces on a real server.
        // Added separately from the create so both a fresh install and the
        // partially-migrated production database take the same path.
        if (! $this->hasIndex('production_standard_packagings', 'psp_standard_mode_unique')) {
            Schema::table('production_standard_packagings', function (Blueprint $table) {
                $table->unique(['production_standard_id', 'mode'], 'psp_standard_mode_unique');
            });
        }
    }

    /** Portable enough for the two drivers this project runs on. */
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

    public function down(): void
    {
        Schema::dropIfExists('production_standard_packagings');
        Schema::dropIfExists('production_standards');
    }
};
