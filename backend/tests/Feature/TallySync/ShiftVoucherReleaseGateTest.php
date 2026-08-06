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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DEC-20260807-002: a shift voucher is offered to the agent only when BOTH
 * its shift's end_time has passed for its production date (derived from
 * shifts.end_time, never a hardcoded clock) AND nothing has merged into it
 * for tally-sync.release_idle_minutes — or the accountant pressed "Release
 * now". Without the gate, the agent's 90-second poll would stamp
 * delivered_at moments after the first approval, slam the merge window,
 * and turn "one voucher per shift" back into one voucher per approval.
 *
 * Batch-mode vouchers are never held; a delivered voucher keeps
 * reappearing until acked; the delivered_at double-post guard
 * (VoucherPostedOnceTest) is fronted by this gate, never weakened.
 */
class ShiftVoucherReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machine;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.voucher_granularity' => 'shift']);
        // Pinned rather than read from the default so these assertions
        // fail loudly if the default ever moves.
        config(['tally-sync.release_idle_minutes' => 15]);

        // The morning shift ends at 14:00 — every "held vs released"
        // assertion below is relative to THIS row's end_time, exactly as
        // the gate derives it (never from a hardcoded clock).
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);
        $this->approver = User::factory()->create();
    }

    private function approvedEntry(string $produced, ?Shift $shift = null, string $date = '2026-07-23'): ShiftProductionEntry
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => ($shift ?? $this->shift)->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => $date,
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => $produced,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '10.0000',
        ]);

        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, $this->approver->id);

        return $service->accountantApprove($entry->fresh(), User::factory()->create()->id);
    }

    /** The voucher numbers pending() is willing to hand the agent right now. */
    private function offered(): array
    {
        return app(TallySyncService::class)->pending()
            ->map(fn (TallySyncEntry $entry) => $entry->voucherNumber())
            ->all();
    }

    public function test_a_shift_voucher_is_held_while_its_shift_is_still_collecting(): void
    {
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $this->approvedEntry('5000');

        $this->assertSame([], $this->offered(), 'Mid-shift, the voucher must not be offered');

        $voucher = TallySyncEntry::query()->sole();
        $this->assertNull($voucher->fresh()->delivered_at, 'A withheld voucher is never stamped as delivered');
    }

    public function test_a_shift_voucher_releases_only_after_shift_end_plus_quiet_period(): void
    {
        $this->travelTo(Carbon::parse('2026-07-23 13:55:00'));
        $this->approvedEntry('5000');
        $voucher = TallySyncEntry::query()->sole();

        // 14:05: the shift has ended, but the voucher merged 10 minutes
        // ago — still inside the 15-minute quiet period.
        $this->travelTo(Carbon::parse('2026-07-23 14:05:00'));
        $this->assertSame([], $this->offered());

        // 14:11: quiet period over (13:55 + 15m = 14:10). Released.
        $this->travelTo(Carbon::parse('2026-07-23 14:11:00'));
        $this->assertSame([$voucher->voucherNumber()], $this->offered());
        $this->assertNotNull($voucher->fresh()->delivered_at);
    }

    public function test_a_late_merge_resets_the_quiet_period(): void
    {
        $this->travelTo(Carbon::parse('2026-07-23 13:50:00'));
        $this->approvedEntry('5000');

        // A post-shift approval still merges (the voucher is undelivered)
        // and re-arms the quiet period from ITS moment, not the first one.
        $this->travelTo(Carbon::parse('2026-07-23 14:05:00'));
        $this->approvedEntry('3000');

        $voucher = TallySyncEntry::query()->sole();
        $this->assertCount(2, $voucher->payload['entry_ids'], 'Second approval merged, not followed-up');

        // 14:15 would have been past 13:50+15m, but the 14:05 merge moved
        // the clock: quiet until 14:20.
        $this->travelTo(Carbon::parse('2026-07-23 14:15:00'));
        $this->assertSame([], $this->offered());

        $this->travelTo(Carbon::parse('2026-07-23 14:21:00'));
        $this->assertSame([$voucher->voucherNumber()], $this->offered());
    }

    public function test_an_overnight_shift_voucher_releases_the_next_morning(): void
    {
        // 22:00 → 06:00: production date is the date the shift STARTED
        // (Shift::productionDateFor), so the voucher for the 23rd must not
        // leave before 06:00 on the 24TH — the same convention, mirrored.
        $night = Shift::create(['name' => 'C', 'start_time' => '22:00', 'end_time' => '06:00']);

        $this->travelTo(Carbon::parse('2026-07-23 23:00:00'));
        $this->approvedEntry('4000', $night);
        $voucher = TallySyncEntry::query()->sole();

        $this->travelTo(Carbon::parse('2026-07-24 05:59:00'));
        $this->assertSame([], $this->offered(), 'Still collecting until the NEXT day\'s end_time');

        // 06:01 next morning: shift over, and the only merge was 23:00
        // yesterday — the quiet period elapsed long ago.
        $this->travelTo(Carbon::parse('2026-07-24 06:01:00'));
        $this->assertSame([$voucher->voucherNumber()], $this->offered());
    }

    public function test_a_voucher_born_after_its_shift_ended_obeys_only_the_quiet_period(): void
    {
        // Late paperwork: first approval at 18:00 for the morning shift.
        // The shift-end condition is already satisfied; only the idle-hold
        // applies (DEC-20260807-002).
        $this->travelTo(Carbon::parse('2026-07-23 18:00:00'));
        $this->approvedEntry('5000');
        $voucher = TallySyncEntry::query()->sole();

        $this->travelTo(Carbon::parse('2026-07-23 18:10:00'));
        $this->assertSame([], $this->offered());

        $this->travelTo(Carbon::parse('2026-07-23 18:16:00'));
        $this->assertSame([$voucher->voucherNumber()], $this->offered());
    }

    public function test_manual_release_hands_the_voucher_to_the_agent_on_the_next_poll(): void
    {
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $this->approvedEntry('5000');
        $voucher = TallySyncEntry::query()->sole();

        $accountant = User::factory()->create();
        app(TallySyncService::class)->releaseNow($voucher, $accountant->id);

        // Mid-shift, mid-quiet-period — released anyway, and audited.
        $this->assertSame([$voucher->voucherNumber()], $this->offered());
        $voucher->refresh();
        $this->assertNotNull($voucher->released_at);
        $this->assertSame($accountant->id, $voucher->released_by);
        $this->assertNotNull($voucher->delivered_at);
    }

    public function test_manual_release_refuses_a_voucher_that_is_not_held(): void
    {
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $this->approvedEntry('5000');
        $voucher = TallySyncEntry::query()->sole();

        $sync = app(TallySyncService::class);
        $sync->releaseNow($voucher);
        $sync->pending();

        // Delivered: a second Release from a stale page must be told the
        // truth, not silently "succeed".
        $this->expectException(ValidationException::class);
        $sync->releaseNow($voucher->fresh());
    }

    public function test_manual_release_refuses_a_voucher_already_in_tally(): void
    {
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $this->approvedEntry('5000');
        $voucher = TallySyncEntry::query()->sole();

        $sync = app(TallySyncService::class);
        $sync->releaseNow($voucher);
        $sync->pending();
        $sync->markSynced($voucher->fresh());

        $this->expectExceptionMessage('already in Tally');
        $sync->releaseNow($voucher->fresh());
    }

    public function test_a_late_approval_after_release_opens_a_follow_up_that_is_itself_held(): void
    {
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $this->approvedEntry('5000');
        $voucher = TallySyncEntry::query()->sole();

        $sync = app(TallySyncService::class);
        $sync->releaseNow($voucher);
        $sync->pending();

        // The released voucher is in the agent's hands — the next approval
        // must not mutate it (DEC-20260807-003: the standard follow-up).
        $this->travelTo(Carbon::parse('2026-07-23 10:30:00'));
        $this->approvedEntry('3000');

        $this->assertSame(2, TallySyncEntry::count());
        $followUp = TallySyncEntry::query()->orderByDesc('id')->first();
        $this->assertSame("SJ-20260723-S{$this->shift->id}-2", $followUp->payload['voucher_number']);

        // And the follow-up starts life held like any other shift voucher:
        // only the released one is offered (it reappears until acked), the
        // follow-up stays back until 14:00 has passed and it has gone quiet.
        $this->assertSame([$voucher->voucherNumber()], $this->offered());

        $sync->markSynced($voucher->fresh());
        $this->travelTo(Carbon::parse('2026-07-23 14:46:00'));
        $this->assertSame([$followUp->voucherNumber()], $this->offered());
    }

    public function test_batch_mode_vouchers_are_never_held(): void
    {
        config(['tally-sync.voucher_granularity' => 'batch']);

        // Mid-shift, seconds after approval — the per-entry Manufacturing
        // Journal goes out on the very next poll, exactly as before the
        // gate existed.
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $entry = $this->approvedEntry('5000');

        $this->assertSame(["SPE-{$entry->id}"], $this->offered());
    }

    public function test_a_delivered_shift_voucher_keeps_reappearing_until_acked(): void
    {
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $this->approvedEntry('5000');
        $voucher = TallySyncEntry::query()->sole();

        $sync = app(TallySyncService::class);
        $sync->releaseNow($voucher);
        $sync->pending();

        // Unacked after delivery: the gate must not swallow the re-poll
        // retry semantics — the agent keeps seeing it until it acks.
        $this->assertSame([$voucher->voucherNumber()], $this->offered());
    }
}
