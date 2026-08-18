<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ConfigSnapshotReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Where the two drivers the suite runs on (ci.yml: in-memory sqlite as the
 * fast leg, MySQL 8 — the live instance — as the parity leg) would answer
 * the same application code differently, and what this codebase relies on.
 *
 * Every pin here is one the sqlite leg cannot see on its own: it holds on
 * both drivers only because config or code makes it hold on MySQL.
 */
class DatabaseDriverParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE CONDITIONAL UPDATE (compare-and-swap) that guards every lifecycle
     * transition in this codebase — `where(state as read)->update(...)`,
     * "0 rows → someone else moved it, throw" (ShiftProductionEntryService
     * completeBatch / amendCompletion / recordQualityCheck /
     * returnToProduction / advance / reject; MoldChangeLogService and
     * MachineDowntimeLogService close) — reads update()'s return as "rows
     * that MATCHED the where". sqlite reports exactly that. MySQL, by
     * default, reports rows whose values actually CHANGED: an UPDATE that
     * matched one row and wrote the same values back answers 0.
     *
     * Most of those sites flip a status column, so the row always changes.
     * returnToProduction() does not: a batch quality never checked has every
     * quality column null already, the return sets them null again, MySQL
     * says 0, and the live instance refused a legitimate return with "this
     * batch changed while the return was being saved" — a MySQL-only bug the
     * sqlite suite was green over (found by the ci.yml MySQL leg, Phase 7;
     * BatchAmendmentAndQcReturnTest::test_quality_can_return_a_batch_it_has_
     * not_checked_and_the_floor_then_corrects_it was the red).
     *
     * config/database.php now opens the MySQL connection with
     * PDO::MYSQL_ATTR_FOUND_ROWS, which makes MySQL answer matched rows too.
     * RED BEFORE on MySQL (0), green on sqlite either way — which is exactly
     * why it is pinned here rather than trusted.
     */
    public function test_a_conditional_update_that_rewrites_the_same_values_still_reports_the_row_it_matched(): void
    {
        $shift = Shift::create(['name' => 'Parity', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);

        $matchedButUnchanged = DB::table('shifts')
            ->where('id', $shift->id)
            ->where('name', 'Parity')
            ->update(['name' => 'Parity']);

        $this->assertSame(
            1,
            $matchedButUnchanged,
            'update() must report the rows the WHERE matched, not the rows whose values changed — every conditional-UPDATE guard in this codebase reads it that way (MySQL needs PDO::MYSQL_ATTR_FOUND_ROWS for this)',
        );

        // And a where that matches nothing still answers 0 — the guard's other half.
        $this->assertSame(0, DB::table('shifts')->where('id', $shift->id)->where('name', 'Elsewhere')->update(['name' => 'Parity']));
    }

    /**
     * THE FROZEN-SNAPSHOT DEPENDENCY CHECK — the one guard in the
     * product-definition masters' declaration with no backstop of any kind
     * behind it, and the one whose SQL differs by driver.
     *
     * A hard delete of a ProductionStandard or a ProductionConfiguration is
     * blocked by counting the completed runs whose
     * `shift_production_entries.config_snapshot` names it. That is a JSON
     * key, not a foreign key: nothing cascades, nothing restricts, and
     * `SchemaCascades` has nothing to say about it. If this count comes back
     * 0 on MySQL where sqlite says 1, the live instance destroys a standard
     * that every past run still names — and the whole suite stays green,
     * because green is what a zero count produces.
     *
     * Laravel's `where('config_snapshot->key', $id)` compiles differently on
     * the two drivers (`json_extract` vs `json_unquote(json_extract(...))`)
     * and compares different types on each. ConfigSnapshotReference forces
     * both sides to text on both drivers instead; this pins that it counts
     * the same on whichever leg is running.
     */
    public function test_the_frozen_snapshot_reference_counts_the_same_on_both_drivers(): void
    {
        $item = Item::create(['sku' => 'PARITY-1', 'name' => 'Parity bottle', 'uom' => 'Nos', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'PARITY-WH', 'name' => 'Parity store', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'PARITY-MC', 'name' => 'Parity machine', 'is_active' => true]);
        $shift = Shift::create(['name' => 'Parity', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);

        ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-04-01',
            'quantity_produced' => '100',
            'config_snapshot' => ['configuration_id' => 4242, 'production_standard_id' => 77],
        ]);

        $this->assertSame(1, ConfigSnapshotReference::count('configuration_id', 4242));
        $this->assertSame(1, ConfigSnapshotReference::count('production_standard_id', 77));

        // …and does not match a different id, or a key the snapshot lacks.
        $this->assertSame(0, ConfigSnapshotReference::count('configuration_id', 4243));
        $this->assertSame(0, ConfigSnapshotReference::count('mold_id', 4242));
    }
}
