<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\MachineDowntimeLogService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Factory convention: an overnight shift's output files under the date the
 * shift STARTED. A batch/log created at 02:00 during the Night shift
 * (22:00–06:00) belongs to yesterday, so the whole night's production sits
 * on one date instead of splitting across two.
 */
class NightShiftProductionDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises the night-shift production-date rule, not the production-readiness gate.
        // Its fixtures are deliberately minimal items (no weight, no Tally
        // identity), which the fail-closed gate would refuse at Start Batch.
        // Turning enforcement off here keeps each test on its own subject;
        // the gate itself is covered by ProductReadinessGateTest.
        config()->set('production.readiness.enforced', false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixtures(): array
    {
        return [
            'night' => Shift::create(['name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00']),
            'morning' => Shift::create(['name' => 'Morning', 'start_time' => '06:00:00', 'end_time' => '14:00:00']),
            'machine' => WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']),
            'item' => Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']),
            'warehouse' => Warehouse::create(['code' => 'WH-1', 'name' => 'Store']),
        ];
    }

    public function test_a_night_shift_batch_at_2am_files_under_the_shift_start_date(): void
    {
        $f = $this->fixtures();
        Carbon::setTestNow('2026-07-25 02:00:00');

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $f['night']->id,
            'work_center_id' => $f['machine']->id,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
        ], null);

        $this->assertSame('2026-07-24', $entry->production_date->toDateString());
    }

    public function test_a_night_shift_batch_at_11pm_files_under_today(): void
    {
        $f = $this->fixtures();
        Carbon::setTestNow('2026-07-24 23:00:00');

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $f['night']->id,
            'work_center_id' => $f['machine']->id,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
        ], null);

        $this->assertSame('2026-07-24', $entry->production_date->toDateString());
    }

    public function test_a_night_shift_entry_in_the_morning_grace_window_files_under_the_start_date(): void
    {
        $f = $this->fixtures();
        // 06:30 — Night ended at 06:00; wrapping-up paperwork for the night
        // shift still belongs to the date the shift started.
        Carbon::setTestNow('2026-07-25 06:30:00');

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $f['night']->id,
            'work_center_id' => $f['machine']->id,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
        ], null);

        $this->assertSame('2026-07-24', $entry->production_date->toDateString());
    }

    public function test_a_morning_shift_batch_files_under_today(): void
    {
        $f = $this->fixtures();
        Carbon::setTestNow('2026-07-25 10:00:00');

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $f['morning']->id,
            'work_center_id' => $f['machine']->id,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
        ], null);

        $this->assertSame('2026-07-25', $entry->production_date->toDateString());
    }

    public function test_a_downtime_log_follows_the_same_night_shift_convention(): void
    {
        $f = $this->fixtures();
        Carbon::setTestNow('2026-07-25 03:30:00');

        $log = app(MachineDowntimeLogService::class)->open([
            'shift_id' => $f['night']->id,
            'work_center_id' => $f['machine']->id,
            'nature_of_problem' => 'Heater failure',
        ], null);

        $this->assertSame('2026-07-24', $log->production_date->toDateString());
    }

    public function test_an_explicit_production_date_is_never_overridden(): void
    {
        $f = $this->fixtures();
        Carbon::setTestNow('2026-07-25 02:00:00');

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $f['night']->id,
            'work_center_id' => $f['machine']->id,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'production_date' => '2026-07-20',
        ], null);

        $this->assertSame('2026-07-20', $entry->production_date->toDateString());
    }
}
