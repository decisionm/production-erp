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
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The one-off backfill (2026_08_16_100001) reconstructs each existing
 * entry's history from the timestamps its columns still carry — and says
 * so on every row (details.backfilled = true, actor 'system' /
 * 'backfill 2026-08-16'), so a reconstruction is never read as an
 * observation. It skips any entry whose history has already begun, and
 * its rollback removes only what it wrote.
 */
class SyncEventBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_16_100001_backfill_tally_sync_events.php';

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 0]);
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    /**
     * An entry written straight to the table — the pre-history shape: no
     * event has ever been recorded for it. Timestamps come from the frozen
     * clock so each one is a known, distinct instant.
     */
    private function legacyEntry(string $createdAt, array $attributes = []): TallySyncEntry
    {
        $this->travelTo(Carbon::parse($createdAt));

        return TallySyncEntry::create(array_merge([
            'syncable_type' => 'shift_production_entry',
            'syncable_id' => 1,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => 'SPE-1'],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ], $attributes));
    }

    /** @return array<string, string> event => occurred_at (Y-m-d H:i:s), in id order */
    private function reconstruction(TallySyncEntry $entry): array
    {
        return $entry->events()->get()
            ->mapWithKeys(fn (TallySyncEvent $e) => [$e->event => $e->occurred_at->format('Y-m-d H:i:s')])
            ->all();
    }

    public function test_it_reconstructs_one_event_per_timestamp_and_flags_every_row_as_backfilled(): void
    {
        $accountant = User::factory()->create();

        $pending = $this->legacyEntry('2026-07-20 08:00:00');
        $synced = $this->legacyEntry('2026-07-21 08:00:00', [
            'payload' => ['voucher_number' => 'SJ-20260721-S1'],
            'tally_voucher_type' => 'Stock Journal',
            'status' => TallySyncStatus::Synced,
            'delivered_at' => '2026-07-21 14:20:00',
            'synced_at' => '2026-07-21 14:21:00',
            'released_at' => '2026-07-21 14:19:00',
            'released_by' => $accountant->id,
        ]);
        $failed = $this->legacyEntry('2026-07-22 08:00:00', [
            'status' => TallySyncStatus::Failed,
            'error_message' => 'Stock Item does not exist',
            'attempts' => 2,
            'delivered_at' => '2026-07-22 09:00:00',
        ]);
        $this->travelTo(Carbon::parse('2026-07-22 09:05:00'));
        $failed->touch();
        $dismissed = $this->legacyEntry('2026-07-23 08:00:00', [
            'status' => TallySyncStatus::Dismissed,
            'error_message' => "Could not set 'SVCurrentCompany'",
            'attempts' => 6,
        ]);
        $this->travelTo(Carbon::parse('2026-07-23 12:00:00'));
        $dismissed->touch();

        $this->assertSame(0, TallySyncEvent::count(), 'precondition: no history exists for legacy rows');

        $this->travelTo(Carbon::parse('2026-08-16 10:00:00'));
        $this->migration()->up();

        $this->assertSame(['voucher.enqueued' => '2026-07-20 08:00:00'], $this->reconstruction($pending));
        $this->assertSame([
            'voucher.enqueued' => '2026-07-21 08:00:00',
            'voucher.released' => '2026-07-21 14:19:00',
            'pending.delivered' => '2026-07-21 14:20:00',
            'voucher.synced' => '2026-07-21 14:21:00',
        ], $this->reconstruction($synced), 'Oldest first, so ids read in time order like live rows');
        $this->assertSame([
            'voucher.enqueued' => '2026-07-22 08:00:00',
            'pending.delivered' => '2026-07-22 09:00:00',
            'voucher.failed' => '2026-07-22 09:05:00',
        ], $this->reconstruction($failed));
        $this->assertSame([
            'voucher.enqueued' => '2026-07-23 08:00:00',
            'voucher.dismissed' => '2026-07-23 12:00:00',
        ], $this->reconstruction($dismissed));

        // Every row says what it is.
        $this->assertSame(10, TallySyncEvent::count());
        foreach (TallySyncEvent::all() as $event) {
            $this->assertTrue($event->isBackfilled(), "{$event->event} #{$event->id}");
            $this->assertSame('system', $event->actor_type);
            $this->assertNull($event->actor_id);
            $this->assertSame('backfill 2026-08-16', $event->actor_label);
            $this->assertSame('erp_to_tally', $event->direction);
            $this->assertSame('2026-08-16 10:00:00', $event->created_at->format('Y-m-d H:i:s'));
        }

        // The details the columns could still vouch for.
        $this->assertSame('Stock Item does not exist', $failed->events()->where('event', 'voucher.failed')->sole()->details['error_message']);
        $this->assertSame(2, $failed->events()->where('event', 'voucher.failed')->sole()->details['attempt']);
        $this->assertSame("Could not set 'SVCurrentCompany'", $dismissed->events()->where('event', 'voucher.dismissed')->sole()->details['previous_error']);
        $released = $synced->events()->where('event', 'voucher.released')->sole();
        $this->assertSame($accountant->id, $released->details['released_by']);
        $this->assertSame('SJ-20260721-S1', $released->details['voucher_number']);
        $this->assertSame('Stock Journal', $released->details['voucher_type']);
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->legacyEntry('2026-07-21 08:00:00', [
            'status' => TallySyncStatus::Synced,
            'delivered_at' => '2026-07-21 14:20:00',
            'synced_at' => '2026-07-21 14:21:00',
        ]);

        $migration = $this->migration();
        $migration->up();
        $this->assertSame(3, TallySyncEvent::count());

        $migration->up();
        $this->assertSame(3, TallySyncEvent::count(), 'An entry that already has any event is skipped whole');
    }

    public function test_it_leaves_an_entry_whose_history_has_already_begun_alone(): void
    {
        // A voucher enqueued THROUGH the service has a live voucher.enqueued
        // row already; prefixing it with a reconstruction would double the
        // first event of its history.
        config(['tally-sync.voucher_granularity' => 'batch']);
        $entry = $this->approvedShiftEntry();
        $live = app(TallySyncService::class)->enqueueShiftProductionEntry($entry);
        $legacy = $this->legacyEntry('2026-07-20 08:00:00');

        $this->migration()->up();

        $this->assertSame(1, $live->events()->count());
        $this->assertFalse($live->events()->sole()->isBackfilled());
        $this->assertSame(1, $legacy->events()->count());
        $this->assertTrue($legacy->events()->sole()->isBackfilled());
    }

    public function test_rollback_removes_only_what_the_backfill_wrote(): void
    {
        config(['tally-sync.voucher_granularity' => 'batch']);
        $live = app(TallySyncService::class)->enqueueShiftProductionEntry($this->approvedShiftEntry());
        $legacy = $this->legacyEntry('2026-07-20 08:00:00', [
            'status' => TallySyncStatus::Failed,
            'error_message' => 'Godown does not exist',
            'attempts' => 1,
        ]);

        $migration = $this->migration();
        $migration->up();
        $this->assertSame(3, TallySyncEvent::count());

        $migration->down();

        $this->assertSame(0, $legacy->events()->count(), 'The reconstruction is gone');
        $this->assertSame(1, $live->events()->count(), 'The live history is not the backfill\'s to remove');
        $this->assertSame('voucher.enqueued', $live->events()->sole()->event);
    }

    /** An already-approved shift entry with one resin line — see VoucherPostedOnceTest. */
    private function approvedShiftEntry(): ShiftProductionEntry
    {
        $shift = Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::firstOrCreate(['code' => 'M-01'], ['name' => 'Machine 1']);
        $bottle = Item::firstOrCreate(['sku' => 'BTL-500'], ['name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $resin = Item::firstOrCreate(['sku' => 'RES-1'], ['name' => 'PET Resin', 'uom' => 'KG']);
        $warehouse = Warehouse::firstOrCreate(['code' => 'WH-1'], ['name' => 'FG Store']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Approved,
        ]);

        $entry->materialConsumptions()->create([
            'item_id' => $resin->id,
            'warehouse_id' => $warehouse->id,
            'quantity_issued_kg' => '250.0000',
        ]);

        return $entry;
    }
}
