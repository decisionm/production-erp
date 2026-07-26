<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reverse hop of production sync: the agent's ack/fail on a Manufacturing
 * Journal voucher flows back onto the shift entry's own status (approved →
 * synced/failed, failed → synced on a successful retry), and scraps ride the
 * voucher payload/narration. Uses real rows (unlike OutboundVoucherTest's
 * in-memory pattern) because the write-backs are conditional query updates.
 */
class ProductionSyncWritebackTest extends TestCase
{
    use RefreshDatabase;

    private function approvedEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'FG Store']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'batch_number' => 'M01-MOR-20260723-1',
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        $entry->materialConsumptions()->create([
            'item_id' => $resin->id,
            'warehouse_id' => $warehouse->id,
            'quantity_issued_kg' => '250.0000',
        ]);

        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '4.5000']);

        // Walk the chain — the accountant's approval is the posting gate.
        $service = app(ShiftProductionEntryService::class);
        $approver = User::factory()->create()->id;
        $service->pmApprove($entry, $approver);

        return $service->accountantApprove($entry->fresh(), $approver);
    }

    public function test_scraps_ride_the_voucher_payload_and_narration(): void
    {
        $this->approvedEntry();

        $payload = TallySyncEntry::query()->sole()->payload;

        $this->assertCount(1, $payload['scraps']);
        $this->assertSame('lumps', $payload['scraps'][0]['type']);
        $this->assertEquals('4.5000', $payload['scraps'][0]['quantity_kg']);
        // sqlite hands decimals back as floats ("4.5"), MySQL as strings
        // ("4.5000") — assert the shape, not the driver's formatting.
        $this->assertStringContainsString('Scrap — lumps:', $payload['narration']);
        $this->assertStringContainsString('kg', $payload['narration']);
    }

    public function test_agent_ack_marks_the_entry_synced(): void
    {
        $entry = $this->approvedEntry();

        app(TallySyncService::class)->markSynced(TallySyncEntry::query()->sole());

        $this->assertSame(ShiftProductionEntryStatus::Synced, $entry->fresh()->status);
    }

    public function test_agent_failure_marks_the_entry_failed_and_a_retried_ack_recovers_it(): void
    {
        $entry = $this->approvedEntry();

        $sync = app(TallySyncService::class);
        $syncEntry = TallySyncEntry::query()->sole();

        $sync->markFailed($syncEntry, 'Tally company not loaded');
        $this->assertSame(ShiftProductionEntryStatus::Failed, $entry->fresh()->status);

        $sync->retry($syncEntry);
        // Retrying only re-queues the voucher — the entry stays failed until
        // the agent actually reports success.
        $this->assertSame(ShiftProductionEntryStatus::Failed, $entry->fresh()->status);

        $sync->markSynced($syncEntry->fresh());
        $this->assertSame(ShiftProductionEntryStatus::Synced, $entry->fresh()->status);
    }
}
