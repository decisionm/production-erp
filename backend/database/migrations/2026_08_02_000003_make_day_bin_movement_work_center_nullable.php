<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE COMMON RESIN INPUT — a load no longer names a machine.
 *
 * The owner's correction (2-Aug), which supersedes the per-machine model
 * this column was created for: the factory has ONE common resin
 * input/loading point serving every machine. A bag is never assigned to a
 * machine and never scanned to one. So the machine dimension on a LOAD is
 * not "optional metadata" — it is a fact that does not exist, and a NOT NULL
 * column would force every new load to invent one.
 *
 * WHAT THIS MIGRATION DOES NOT DO, and that is the point: it does not touch
 * a single historical row. Every Load, Return and Count already recorded
 * against a machine keeps its work_center_id exactly as written. Those rows
 * are the audit history of how the factory ran under the previous
 * understanding, and rewriting them to null would be falsifying a record to
 * match a later decision. Going forward, the common-input scan writes null;
 * the per-machine bin-bay ledger (day-bin/load, day-bin/return,
 * day-bin/count) is untouched by this wave and keeps writing its machine.
 *
 * The composite index dbm_wc_item_recorded_index stays as it is — a nullable
 * leading column still serves the per-machine reads that use it, and the
 * common-input reads filter on type/item instead.
 *
 * THE DDL THIS EMITS, CHECKED RATHER THAN ASSUMED. The column is both a
 * constrained foreign key (→ work_centers, restrictOnDelete) and the leading
 * column of that index, so an ALTER that silently changed its TYPE would fail
 * against the FK (MySQL errno 3780) halfway through a live migration — and
 * the test database is SQLite, whose change() rebuilds the whole table and
 * would hide exactly that. The compiled MySQL statements were dumped through
 * the schema grammar before this shipped:
 *
 *   up():   alter table `day_bin_movements`
 *             modify `work_center_id` bigint unsigned null
 *   down(): alter table `day_bin_movements`
 *             modify `work_center_id` bigint unsigned not null
 *
 * Type and signedness are preserved in both directions; only nullability
 * moves, which MODIFY does in place without touching the key or the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_bin_movements', function (Blueprint $table) {
            $table->foreignId('work_center_id')->nullable()->change();
        });
    }

    /**
     * Reversible only while no common-input load exists yet — a null cannot
     * be made NOT NULL. That is stated rather than worked around: inventing
     * a machine for a load that never had one, purely so a rollback runs
     * clean, would put a fabricated attribution into the audit trail.
     */
    public function down(): void
    {
        Schema::table('day_bin_movements', function (Blueprint $table) {
            $table->foreignId('work_center_id')->nullable(false)->change();
        });
    }
};
