<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Retiring the rehearsal warehouses — and, just as importantly, retiring
 * NOTHING ELSE.
 *
 * RM-STORE / WIP / FG-STORE came from BottleManufacturingDemoSeeder during
 * rehearsal and correspond to nothing on the factory floor. They are also the
 * rows that make "is there exactly one warehouse" false, which is the
 * question FactoryWarehouseResolver has to answer before it can resolve a
 * payload without asking a supervisor.
 *
 * The danger in a migration like this is obvious and these tests are pointed
 * straight at it: this runs against a LIVE database. Deactivating a warehouse
 * the factory actually uses would stop production. So the migration matches
 * on the seeded CODES only — never on names like "store" or "finished
 * goods" — and then refuses to touch a row that Tally vouches for or that a
 * production setting still points at.
 */
class DemoWarehouseDeactivationTest extends TestCase
{
    use RefreshDatabase;

    /** Run the data migration the way `php artisan migrate` would. */
    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_08_01_120001_deactivate_demo_seeded_warehouses.php');
        $migration->up();
    }

    public function test_it_deactivates_the_three_seeded_demo_warehouses_and_nothing_else(): void
    {
        $rmStore = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => true]);
        $fgStore = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods Store', 'is_active' => true]);

        // A real warehouse whose NAME looks exactly like demo residue. Name
        // matching would have caught it; code matching must not.
        $realLookalike = Warehouse::create(['code' => 'SWAASHPET', 'name' => 'Finished Goods Store', 'is_active' => true, 'tally_guid' => 'gd-real']);

        $this->runMigration();

        $this->assertFalse($rmStore->fresh()->is_active);
        $this->assertFalse($wip->fresh()->is_active);
        $this->assertFalse($fgStore->fresh()->is_active);

        $this->assertTrue($realLookalike->fresh()->is_active);
    }

    /**
     * The guard that matters most on a live database: a warehouse Tally
     * itself vouches for is never demo residue, whatever its code says.
     * This is the exact complement of the resolver's own lookup, so the
     * migration can never deactivate the row the resolver would pick.
     */
    public function test_a_tally_linked_warehouse_sharing_a_demo_code_is_left_alone(): void
    {
        $realButDemoCoded = Warehouse::create([
            'code' => 'FG-STORE', 'name' => 'Finished Goods Store',
            'is_active' => true, 'tally_guid' => 'gd-actually-real',
        ]);

        $this->runMigration();

        $this->assertTrue($realButDemoCoded->fresh()->is_active);
    }

    /**
     * If rehearsal left the day bin pointing at RM-STORE, deactivating it
     * would leave FactoryDayBinService still loading bags into a warehouse
     * the settings screen would refuse to re-select. That divergence is
     * worse than a stale picker entry, so the row is kept and the skip is
     * logged for a human to settle.
     */
    public function test_a_warehouse_a_production_setting_points_at_is_kept_and_logged(): void
    {
        $rmStore = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $fgStore = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods Store', 'is_active' => true]);

        app(FactoryDayBinService::class)->setWarehouseId($rmStore->id);

        Log::spy();

        $this->runMigration();

        $this->assertTrue($rmStore->fresh()->is_active, 'the configured day bin must survive');
        $this->assertFalse($fgStore->fresh()->is_active, 'unreferenced residue is still retired');

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'named by a production setting')
                && ($context['warehouse_id'] ?? null) === $rmStore->id
        )->once();
    }

    /**
     * Re-running converges rather than compounding: no error, no second
     * write, same end state. (Laravel runs a migration once, so this is
     * belt-and-braces for a hand-run or a squashed-migration rebuild.)
     */
    public function test_running_it_twice_is_safe_and_changes_nothing_the_second_time(): void
    {
        $rmStore = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $live = Warehouse::create(['code' => 'SWAASHPET', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true, 'tally_guid' => 'gd-live']);

        $this->runMigration();
        $this->assertFalse($rmStore->fresh()->is_active);
        $touchedAt = $rmStore->fresh()->updated_at;

        $this->runMigration();

        $this->assertFalse($rmStore->fresh()->is_active);
        $this->assertTrue($live->fresh()->is_active);
        // Nothing was rewritten — the second pass found no active candidate.
        $this->assertEquals($touchedAt, $rmStore->fresh()->updated_at);
    }

    /**
     * The work-centre half of "demo residue" is NOT this migration's job —
     * CanonicalMachineSeeder already deactivates the five demo stations by
     * their seeded codes. Asserted here so the split stays deliberate and
     * nobody adds a second, competing mechanism for it later.
     */
    public function test_demo_work_centres_are_handled_by_the_canonical_machine_seeder(): void
    {
        $packing = WorkCenter::create(['code' => 'PACK-01', 'name' => 'Packing Station', 'is_active' => true]);

        $this->seed(CanonicalMachineSeeder::class);

        $this->assertFalse($packing->fresh()->is_active);
        $this->assertTrue(WorkCenter::where('code', 'MC-01')->firstOrFail()->is_active);
    }
}
