<?php

namespace Tests\Feature;

use App\Modules\Production\Models\Shift;
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
}
