<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO WROTE THIS CONFIGURATION ROW — the missing half of the audit column.
 *
 * `activity_log` (spatie/laravel-activitylog, installed since July and wired
 * to nothing) records the ACT: who changed what, when, from what to what.
 * These two columns record the STATE: who owns this row now. They answer
 * different questions and a screen needs the second one without joining a
 * log — "last edited by Vincent, 3 days ago" on a master list is one column
 * read, not a subquery over an ever-growing table.
 *
 * Strictly additive: every column is nullable with no default, so all 644
 * existing items and every other master keep the honest answer "nobody in
 * this app wrote this — it arrived from Tally or from an import". A
 * backfill would have to invent a person, and inventing a factory value is
 * exactly the rule this repo has already paid for once.
 *
 * `nullOnDelete`, deliberately, and it is the only correct choice here: a
 * user row disappearing must never take a machine, a mould or an employee
 * master with it. There is no cascade anywhere in this migration.
 *
 * Scope is the ten Tier-1 configuration masters and NOTHING else — no
 * transaction, ledger or posted-document table is touched. Two of the ten
 * already carry `created_by` (the production standards and configurations
 * services set it explicitly); those are skipped in BOTH directions, so a
 * rollback cannot destroy a column this migration never added.
 */
return new class extends Migration
{
    /**
     * Table => the columns THIS migration adds to it.
     *
     * @var array<string, list<string>>
     */
    private const STAMPS = [
        'items' => ['created_by', 'updated_by'],
        'warehouses' => ['created_by', 'updated_by'],
        'work_centers' => ['created_by', 'updated_by'],
        'shifts' => ['created_by', 'updated_by'],
        'scrap_reasons' => ['created_by', 'updated_by'],
        'molds' => ['created_by', 'updated_by'],
        'downtime_reasons' => ['created_by', 'updated_by'],
        'employees' => ['created_by', 'updated_by'],
        // These two already have created_by — only the editor stamp is new.
        'production_standards' => ['updated_by'],
        'production_configurations' => ['updated_by'],
    ];

    public function up(): void
    {
        foreach (self::STAMPS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::STAMPS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropConstrainedForeignId($column);
                });
            }
        }
    }
};
