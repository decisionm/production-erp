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
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A voucher can never be posted into Tally twice.
 *
 * The live incident this pins: the agent acknowledged a voucher INSIDE the
 * try that wrapped the Tally post, so an acknowledgement that timed out
 * after a SUCCESSFUL import fell into the catch, was reported as a sync
 * failure, and put a Retry button on a voucher that was already in the
 * accountant's books. One click posted the same production twice.
 *
 * The agent half of the fix lives in tally-sync-agent/src/sync.ts. This
 * suite covers the cloud half — the two places that made the duplicate
 * reachable, plus the delivered_at contract the agent's own guard rests on:
 *
 *   - markFailed() refuses to fail a voucher that is already synced, so the
 *     Retry button can never appear on one;
 *   - the retry endpoint 422s on a synced voucher and points staff at Tally;
 *   - retry() clears delivered_at, which is the ONLY thing that re-authorises
 *     the agent to post — every other delivery arrives already stamped;
 *   - /pending hands out delivered_at as it was before this poll stamped it.
 */
class VoucherPostedOnceTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('tally-sync.manage', 'web');
        $user->givePermissionTo('tally-sync.manage');
        Sanctum::actingAs($user);
    }

    private function actAsAgent(array $abilities = ['tally-sync:poll', 'tally-sync:report']): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), $abilities);
    }

    /** A standalone queued voucher, no production entry behind it. */
    private function voucher(array $attributes = []): TallySyncEntry
    {
        return TallySyncEntry::create(array_merge([
            'syncable_type' => 'shift_production_entry',
            'syncable_id' => 1,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => 'SPE-1'],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ], $attributes));
    }

    /**
     * An already-approved shift entry, written straight to the approved
     * status — the four-eyes chain is exercised elsewhere and none of it
     * matters to voucher merging, which keys on status alone.
     */
    private function approvedShiftEntry(string $produced, string $consumedKg): ShiftProductionEntry
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
            'quantity_produced' => $produced,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Approved,
        ]);

        $entry->materialConsumptions()->create([
            'item_id' => $resin->id,
            'warehouse_id' => $warehouse->id,
            'quantity_issued_kg' => $consumedKg,
        ]);

        return $entry;
    }

    /** An accountant-approved production entry with its Manufacturing Journal queued. */
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

        // Two people: the plant manager and the accountant can't be the same
        // user (production.approvals.allow_same_user defaults to false).
        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, User::factory()->create()->id);

        return $service->accountantApprove($entry->fresh(), User::factory()->create()->id);
    }

    public function test_the_retry_endpoint_refuses_a_voucher_already_in_tally(): void
    {
        $this->actAsStaff();
        $voucher = $this->voucher([
            'payload' => ['voucher_number' => 'SPE-42'],
            'status' => TallySyncStatus::Synced,
            'synced_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/tally-sync/entries/{$voucher->id}/retry");

        $response->assertStatus(422);
        // Plain words, naming the voucher the accountant has to go and look
        // at — not "validation failed".
        $this->assertStringContainsString('already in Tally as SPE-42', $response->json('message'));
        $this->assertStringContainsString('check Tally before anything else', $response->json('message'));

        $voucher->refresh();
        $this->assertSame(TallySyncStatus::Synced, $voucher->status);
        $this->assertNotNull($voucher->synced_at, 'A refused retry must not disturb the synced record');
    }

    public function test_the_service_refuses_to_requeue_a_synced_voucher(): void
    {
        $voucher = $this->voucher(['status' => TallySyncStatus::Synced, 'synced_at' => now()]);

        $this->expectException(ValidationException::class);

        try {
            app(TallySyncService::class)->retry($voucher);
        } finally {
            $this->assertSame(TallySyncStatus::Synced, $voucher->fresh()->status);
        }
    }

    public function test_a_synced_voucher_whose_status_lags_behind_synced_at_is_still_refused(): void
    {
        // Belt and braces: synced_at set is enough on its own. A row half-way
        // through a write, or repaired by hand, must not become re-postable.
        $this->actAsStaff();
        $voucher = $this->voucher(['status' => TallySyncStatus::Failed, 'synced_at' => now(), 'attempts' => 1]);

        $this->postJson("/api/v1/tally-sync/entries/{$voucher->id}/retry")->assertStatus(422);
    }

    public function test_retrying_a_failed_voucher_requeues_it_and_re_arms_delivery(): void
    {
        $this->actAsStaff();
        $voucher = $this->voucher([
            'status' => TallySyncStatus::Failed,
            'error_message' => 'Stock Item does not exist',
            'attempts' => 1,
            'delivered_at' => now()->subMinutes(5),
        ]);

        $this->postJson("/api/v1/tally-sync/entries/{$voucher->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $voucher->refresh();
        $this->assertNull($voucher->error_message);
        // Clearing the stamp is the whole point of Retry: the agent refuses
        // to rebuild a voucher for an entry that arrives already delivered,
        // so a re-queue that left it set would be a button that does nothing.
        $this->assertNull($voucher->delivered_at, 'Retry must re-authorise exactly one delivery');
    }

    public function test_retry_is_the_escape_hatch_for_a_voucher_the_agent_refused_to_repost(): void
    {
        // The stranded case: posted-or-maybe-posted, never acknowledged, so
        // it sits Pending with delivered_at set and the agent will not touch
        // it again. Staff check Tally, find nothing, and re-arm it.
        $this->actAsStaff();
        $voucher = $this->voucher(['status' => TallySyncStatus::Pending, 'delivered_at' => now()->subHour()]);

        $this->postJson("/api/v1/tally-sync/entries/{$voucher->id}/retry")->assertOk();

        $this->assertNull($voucher->fresh()->delivered_at);
    }

    public function test_marking_a_synced_voucher_failed_is_a_no_op(): void
    {
        $sync = app(TallySyncService::class);
        $voucher = $this->voucher();

        $sync->markSynced($voucher);
        $syncedAt = $voucher->fresh()->synced_at;

        $sync->markFailed($voucher->fresh(), 'connect ETIMEDOUT 127.0.0.1:9000');

        $voucher->refresh();
        $this->assertSame(TallySyncStatus::Synced, $voucher->status, 'A synced voucher must never fall back to failed');
        $this->assertNull($voucher->error_message);
        $this->assertSame(0, $voucher->attempts, 'A refused failure must not even count as an attempt');
        $this->assertEquals($syncedAt, $voucher->synced_at);
    }

    public function test_a_refused_failure_never_reaches_the_production_entry(): void
    {
        $entry = $this->approvedEntry();
        $sync = app(TallySyncService::class);
        $voucher = TallySyncEntry::query()->sole();

        $sync->markSynced($voucher);
        $this->assertSame(ShiftProductionEntryStatus::Synced, $entry->fresh()->status);

        // The write-back fans out on a status CHANGE. Refusing the failure
        // means the status never changes, so the floor's approval queue
        // never shows a synced batch flip back to "failed" either.
        $sync->markFailed($voucher->fresh(), 'Tally company not loaded');

        $this->assertSame(ShiftProductionEntryStatus::Synced, $entry->fresh()->status);
    }

    public function test_the_agent_fail_endpoint_refuses_to_fail_a_voucher_already_in_tally(): void
    {
        $this->actAsAgent();
        $voucher = $this->voucher(['status' => TallySyncStatus::Synced, 'synced_at' => now()]);

        // The exact live shape: the post succeeded, the ack timed out, and a
        // stale agent reports the timeout as a failure. The cloud keeps the
        // truth.
        $this->postJson("/api/v1/tally-sync/entries/{$voucher->id}/fail", [
            'error_message' => 'timeout of 15000ms exceeded',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'synced')
            ->assertJsonPath('data.error_message', null);

        $this->assertSame(TallySyncStatus::Synced, $voucher->fresh()->status);
    }

    public function test_a_genuine_tally_rejection_is_still_recorded(): void
    {
        // The guard is on SYNCED, never on "delivered" — every entry the
        // agent can report on has been delivered to it by definition, so a
        // delivered-based guard would silence real rejections completely.
        $this->actAsAgent();
        $voucher = $this->voucher(['delivered_at' => now()]);

        $this->postJson("/api/v1/tally-sync/entries/{$voucher->id}/fail", [
            'error_message' => 'Stock Item does not exist',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'Stock Item does not exist')
            ->assertJsonPath('data.attempts', 1);
    }

    public function test_the_first_poll_hands_out_a_null_delivered_at_and_later_polls_do_not(): void
    {
        $this->actAsAgent();
        $voucher = $this->voucher();

        // First delivery: the agent has never seen this voucher, so it is
        // free to build and post it.
        $this->getJson('/api/v1/tally-sync/pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $voucher->id)
            ->assertJsonPath('data.0.delivered_at', null);

        $stamped = $voucher->fresh()->delivered_at;
        $this->assertNotNull($stamped, 'The poll must still stamp the row on its way out');

        // Re-poll of an unacknowledged voucher: the stamp now rides along,
        // and that is what tells the agent not to rebuild the voucher.
        $second = $this->getJson('/api/v1/tally-sync/pending')->assertOk();
        $this->assertNotNull($second->json('data.0.delivered_at'));
        $this->assertEquals($stamped, $voucher->fresh()->delivered_at, 'Re-delivery must not move the stamp');
    }

    public function test_retrying_a_shift_voucher_reopens_it_to_late_approvals(): void
    {
        // The deliberate consequence of clearing delivered_at: a shift
        // voucher's merge window (see enqueueShiftVoucher) re-opens until the
        // next poll stamps it again. That is correct rather than merely
        // tolerable — a FAILED voucher is one Tally rejected, so none of its
        // quantities are in the books, and the agent is not holding it. The
        // alternative (a follow-up voucher for the late entry) would be
        // strictly worse: two vouchers where one was never posted.
        config(['tally-sync.voucher_granularity' => 'shift']);
        $sync = app(TallySyncService::class);

        $first = $this->approvedShiftEntry('5000', '250.0000');
        $sync->enqueueShiftProductionEntry($first);
        $voucher = TallySyncEntry::query()->sole();

        $sync->pending();
        $sync->markFailed($voucher->fresh(), 'Godown does not exist');
        $sync->retry($voucher->fresh());

        $second = $this->approvedShiftEntry('3000', '100.0000');
        $sync->enqueueShiftProductionEntry($second);

        $this->assertSame(1, TallySyncEntry::count(), 'A re-queued voucher takes the late entry with it');
        $voucher->refresh();
        $this->assertSame([$first->id, $second->id], $voucher->payload['entry_ids']);
        $this->assertSame('350.0000', $voucher->payload['consumed'][0]['quantity']);
    }

    public function test_a_delivered_shift_voucher_is_still_closed_to_late_approvals(): void
    {
        // The other half of the same rule, unchanged by this work: while the
        // agent holds the payload, it must not change underneath it.
        config(['tally-sync.voucher_granularity' => 'shift']);
        $sync = app(TallySyncService::class);

        $first = $this->approvedShiftEntry('5000', '250.0000');
        $sync->enqueueShiftProductionEntry($first);
        $sync->pending();

        $sync->enqueueShiftProductionEntry($this->approvedShiftEntry('3000', '100.0000'));

        $this->assertSame(2, TallySyncEntry::count(), 'A delivered voucher must not absorb a late entry');
    }

    public function test_a_retried_voucher_is_handed_out_as_undelivered_again(): void
    {
        // End to end on the cloud side: fail → retry → the next poll looks
        // exactly like a first delivery, which is the one state the agent
        // will post from.
        $this->actAsAgent();
        $voucher = $this->voucher();
        $sync = app(TallySyncService::class);

        $sync->pending();
        $sync->markFailed($voucher->fresh(), 'Godown does not exist');
        $sync->retry($voucher->fresh());

        $this->getJson('/api/v1/tally-sync/pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $voucher->id)
            ->assertJsonPath('data.0.delivered_at', null);
    }
}
