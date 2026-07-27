<?php

namespace Tests\Feature;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tolerance bands are ruled server-side from config/production.php and the
 * optional blocking gate refuses accountant approval — nothing hard-coded.
 */
class ApprovalToleranceTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function entry(array $attributes = []): ShiftProductionEntry
    {
        $n = ++self::$seq;
        $shift = Shift::create(['name' => "Morning {$n}", 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => "MC-0{$n}", 'name' => "Machine {$n}"]);
        $warehouse = Warehouse::create(['code' => "WH-FG-{$n}", 'name' => 'FG Store']);
        $rm = Warehouse::create(['code' => "WH-RM-{$n}", 'name' => 'RM Store']);
        $item = Item::create(['sku' => "BTL-{$n}", 'name' => 'Bottle', 'uom' => 'pcs', 'nominal_weight_grams' => '20.0000']);
        $resin = Item::create(['sku' => "RES-{$n}", 'name' => 'PET Resin', 'uom' => 'kg']);

        $entry = ShiftProductionEntry::create($attributes + [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_produced_kg' => '20.0000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::PmApproved,
        ]);
        // Issued 25 kg vs good 20 kg, no rejection/lumps -> unaccounted 5 kg.
        $entry->materialConsumptions()->create([
            'item_id' => $resin->id, 'warehouse_id' => $rm->id, 'quantity_issued_kg' => '25',
        ]);

        return $entry;
    }

    public function test_bands_follow_configured_tolerances(): void
    {
        config(['production.tolerances.unaccounted_kg' => 10.0]);
        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($this->entry());
        $this->assertSame('ok', $metrics['unaccounted_band'], '5 kg within a 10 kg tolerance');

        config(['production.tolerances.unaccounted_kg' => 0.5]);
        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($this->entry());
        $this->assertSame('investigate', $metrics['unaccounted_band']);
        $this->assertFalse($metrics['blocks_approval'], 'Blocking gate defaults to off');
    }

    public function test_blocking_gate_refuses_accountant_approval_when_configured(): void
    {
        config(['production.tolerances.unaccounted_blocking_kg' => 3.0]);
        $entry = $this->entry();
        $accountant = User::factory()->create();

        $this->expectException(InvalidStatusTransitionException::class);
        app(ShiftProductionEntryService::class)->accountantApprove($entry, $accountant->id);
    }

    public function test_gate_off_by_default_lets_the_same_entry_approve(): void
    {
        $entry = $this->entry();
        $accountant = User::factory()->create();

        $approved = app(ShiftProductionEntryService::class)->accountantApprove($entry, $accountant->id);
        $this->assertSame(ShiftProductionEntryStatus::Approved, $approved->status);
    }
}
