<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO WROTE THIS VENDOR / CUSTOMER ROW — the last two masters to get it.
 *
 * The 17-Aug migration (add_audit_stamps_to_configuration_tables) gave these
 * two columns to the ten Tier-1 configuration masters. Vendor and Customer
 * were left out because they were not on the Configuration Lifecycle Contract
 * at all: `is_active` existed on both tables, but the only way to flip it was
 * a plain `update` carrying the whole record — no reason, no audit entry, no
 * dependency guard. Wiring them onto the contract makes
 * `RecordsConfigurationAudit` stamp these columns, so the columns have to
 * exist first.
 *
 * The reasoning is unchanged from that migration and is not re-argued here:
 * `activity_log` records the ACT (who changed what, when, from what to what);
 * these two columns record the STATE (who owns this row now), which a master
 * list reads without joining an ever-growing log.
 *
 * Strictly additive. Both columns are nullable with no default, so every
 * existing vendor and customer keeps the honest answer "nobody in this app
 * wrote this" rather than a backfill inventing a person — the rule this repo
 * has already paid for once (PR #128).
 *
 * `nullOnDelete`: a user row disappearing must never take a vendor or a
 * customer master with it. No cascade anywhere in this migration.
 *
 * Scope is exactly these two master tables. No transaction, ledger or posted
 * document is touched — purchase orders, sales orders, invoices and
 * quotations all keep their own actor columns and are none of this
 * migration's business.
 */
return new class extends Migration
{
    /**
     * Table => the columns THIS migration adds to it.
     *
     * @var array<string, list<string>>
     */
    private const STAMPS = [
        'vendors' => ['created_by', 'updated_by'],
        'customers' => ['created_by', 'updated_by'],
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
