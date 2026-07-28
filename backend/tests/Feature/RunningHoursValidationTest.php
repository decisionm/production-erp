<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Zero/invalid running hours (QA matrix item): a shift is at most 24 h and
 * a 0-hour run cannot produce anything, so Complete Batch refuses 0,
 * negative and >24 outright (422, never silently stored), and when hours
 * are simply not captured the expected-output engine reports null
 * expectations — never a division-fed fake number — while the entered
 * actuals survive.
 */
class RunningHoursValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);

        return $user;
    }

    private function inProgressEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create([
            'sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS',
            'standard_cycle_time' => '10.6', 'standard_cavities' => 5, 'nos_per_box' => 840,
        ]);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'FG Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
            'batch_number' => '20260728-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    public function test_zero_running_hours_is_rejected_not_silently_stored(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5880',
            'running_hours' => 0,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['running_hours']);

        // The failed completion left the batch running.
        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
    }

    public function test_negative_and_beyond_24_hour_running_hours_are_rejected(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5880',
            'running_hours' => -3,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['running_hours']);

        // A shift can never run more than a day.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5880',
            'running_hours' => 25,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['running_hours']);

        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
    }

    public function test_a_full_24_hour_run_is_the_accepted_boundary(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry();

        // 24 h exactly (a full-day continuous run) passes — the cap refuses
        // only what is physically impossible.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5880',
            'running_hours' => 24,
        ])
            ->assertOk()
            ->assertJsonPath('data.running_hours', '24.00');
    }

    public function test_missing_running_hours_yields_null_expectations_with_actuals_intact(): void
    {
        // CT and cavities are on the snapshot but hours were never captured:
        // the expectation side must be null (the missing-CT twin of
        // ExpectedOutputEngineTest's null-guard test), while the counted
        // actuals survive untouched.
        $service = app(ShiftProductionEntryService::class);

        $entry = new ShiftProductionEntry([
            'batch_status' => BatchStatus::Completed,
            'standard_cycle_time' => '10.6',
            'active_cavities' => 5,
            'no_of_box' => 7,
            'quantity_produced' => '5880',
        ]);
        $entry->setRelation('item', new Item(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS', 'nos_per_box' => 840]));
        $entry->setRelation('materialConsumptions', collect());
        $entry->setRelation('scraps', collect());

        $metrics = $service->productionMetrics($entry);

        $this->assertNotNull($metrics);
        $this->assertNull($metrics['expected_pieces']);
        $this->assertNull($metrics['expected_boxes']);
        $this->assertNull($metrics['efficiency_pct']);
        $this->assertSame(7, $metrics['actual_boxes']);
        $this->assertSame('5880', $metrics['actual_pieces']);
    }
}
