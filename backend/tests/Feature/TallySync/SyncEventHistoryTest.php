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
use App\Modules\TallySync\Http\Resources\TallySyncEventResource;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\TallySyncEventRecorder;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * tally_sync_events is the durable history of the sync chain
 * (TALLY-SYNC-CHAIN.md §2–3): every mutation of an entry, and every inbound
 * pull, lands as an append-only row with WHO did it — the agent by its
 * token name, a person by their name, the system otherwise.
 *
 * What this pins, in order of why the table exists:
 *
 *   - the entry's own columns are DESTRUCTIVE (error_message overwritten,
 *     delivered_at nulled by retry) and the events are not — a voucher
 *     failed twice keeps both errors, a retried voucher reads
 *     delivered → failed → retried → delivered;
 *   - the actor is recorded from the request the mutation arrived on: the
 *     agent's token NAME (never the token), the acting user's name;
 *   - a refusal is history too (voucher.failure_refused), and a refused
 *     mutation that throws leaves NO row;
 *   - rows cannot be updated or deleted through the model.
 */
class SyncEventHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // History is downstream of the approval chain and the release gate;
        // both are neutralised the way VoucherPostedOnceTest does it.
        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 0]);
    }

    /**
     * A REAL personal access token, because the actor's label is the token's
     * NAME — Sanctum::actingAs() mocks the token and its name is not a
     * string, which is exactly the shape the recorder must not mistake for
     * an installation.
     *
     * @return array{0: User, 1: string, 2: PersonalAccessToken}
     */
    private function agent(string $tokenName = 'factory-pc', array $abilities = ['tally-sync:poll', 'tally-sync:report', 'tally-sync:masters']): array
    {
        $user = User::factory()->create(['name' => 'Tally Sync Agent', 'is_active' => true]);
        $issued = $user->createToken($tokenName, $abilities);

        return [$user, $issued->plainTextToken, $issued->accessToken];
    }

    /** The agent as the SERVICE sees it: the user carrying its real token. */
    private function agentUser(string $tokenName = 'factory-pc'): User
    {
        [$user, , $token] = $this->agent($tokenName);

        return $user->withAccessToken($token);
    }

    private function staff(string $name = 'Priya Accounts'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach (['tally-sync.view', 'tally-sync.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
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

    /** An already-approved shift entry with one resin line — see VoucherPostedOnceTest. */
    private function approvedShiftEntry(string $produced, string $consumedKg, array $attributes = []): ShiftProductionEntry
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
            ...$attributes,
        ]);

        $entry->materialConsumptions()->create([
            'item_id' => $resin->id,
            'warehouse_id' => $warehouse->id,
            'quantity_issued_kg' => $consumedKg,
        ]);

        return $entry;
    }

    /** @return list<string> the entry's event names in id order */
    private function timeline(TallySyncEntry $entry): array
    {
        return $entry->events()->get()->pluck('event')->all();
    }

    // ---- (a) enqueue -----------------------------------------------------

    public function test_enqueue_records_voucher_enqueued_as_the_first_row_of_the_history(): void
    {
        config(['tally-sync.voucher_granularity' => 'batch']);

        $entry = $this->approvedShiftEntry('5000', '250.0000');
        $voucher = app(TallySyncService::class)->enqueueShiftProductionEntry($entry);

        $event = TallySyncEvent::query()->sole();
        $this->assertSame('voucher.enqueued', $event->event);
        $this->assertSame($voucher->id, $event->tally_sync_entry_id);
        $this->assertSame('erp_to_tally', $event->direction);
        $this->assertSame('Manufacturing Journal', $event->details['voucher_type']);
        $this->assertSame("SPE-{$entry->id}", $event->details['voucher_number']);
        // No person and no agent enqueues: it is the side effect of an
        // approval, and the record says so.
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertNotNull($event->occurred_at);
        $this->assertFalse($event->isBackfilled());
    }

    // ---- (b) delivery ----------------------------------------------------

    public function test_a_poll_records_one_pending_delivered_per_entry_naming_the_agent_token(): void
    {
        [$agent, $plainToken] = $this->agent('factory-pc');
        $first = $this->voucher(['payload' => ['voucher_number' => 'SPE-1']]);
        $second = $this->voucher(['payload' => ['voucher_number' => 'SPE-2']]);

        $this->withToken($plainToken)->getJson('/api/v1/tally-sync/pending')->assertOk()->assertJsonCount(2, 'data');

        $delivered = TallySyncEvent::query()->where('event', 'pending.delivered')->orderBy('id')->get();
        $this->assertCount(2, $delivered, 'One row per entry handed out, not one per poll');
        $this->assertSame([$first->id, $second->id], $delivered->pluck('tally_sync_entry_id')->all());
        foreach ($delivered as $event) {
            $this->assertSame('agent', $event->actor_type);
            $this->assertSame($agent->id, $event->actor_id);
            // The token's NAME — which installation — never the token.
            $this->assertSame('factory-pc', $event->actor_label);
            $this->assertStringNotContainsString($plainToken, json_encode($event->getAttributes()));
        }
        $this->assertSame('SPE-2', $delivered->last()->details['voucher_number']);
        $this->assertSame('Manufacturing Journal', $delivered->last()->details['voucher_type']);

        // A re-poll of the same unacked vouchers is a heartbeat, not a
        // delivery: the agent's own guard refuses to act on a voucher that
        // arrives already stamped, and the history does not grow by it.
        $this->withToken($plainToken)->getJson('/api/v1/tally-sync/pending')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(2, TallySyncEvent::query()->where('event', 'pending.delivered')->count());
    }

    // ---- (c) ack ---------------------------------------------------------

    public function test_an_ack_records_voucher_synced_with_the_agent_as_actor(): void
    {
        [$agent, $plainToken] = $this->agent('factory-pc');
        $voucher = $this->voucher(['delivered_at' => now()]);

        $this->withToken($plainToken)->postJson("/api/v1/tally-sync/entries/{$voucher->id}/ack")->assertOk();

        $event = TallySyncEvent::query()->where('event', 'voucher.synced')->sole();
        $this->assertSame($voucher->id, $event->tally_sync_entry_id);
        $this->assertSame('agent', $event->actor_type);
        $this->assertSame($agent->id, $event->actor_id);
        $this->assertSame('factory-pc', $event->actor_label);
        $this->assertSame('SPE-1', $event->details['voucher_number']);
    }

    // ---- (d) fail, twice -------------------------------------------------

    public function test_each_failure_is_its_own_row_and_the_first_survives_the_second(): void
    {
        [, $plainToken] = $this->agent('factory-pc');
        $voucher = $this->voucher(['delivered_at' => now()]);

        $this->withToken($plainToken)
            ->postJson("/api/v1/tally-sync/entries/{$voucher->id}/fail", ['error_message' => 'Godown does not exist'])
            ->assertOk();
        $this->withToken($plainToken)
            ->postJson("/api/v1/tally-sync/entries/{$voucher->id}/fail", ['error_message' => 'Stock Item does not exist'])
            ->assertOk();

        // The entry keeps only the last error — that is the destructive
        // column the history exists to outlive.
        $this->assertSame('Stock Item does not exist', $voucher->fresh()->error_message);

        $failures = TallySyncEvent::query()->where('event', 'voucher.failed')->orderBy('id')->get();
        $this->assertCount(2, $failures, 'History is appended, never overwritten');
        $this->assertSame(
            [['Godown does not exist', 1], ['Stock Item does not exist', 2]],
            $failures->map(fn (TallySyncEvent $e) => [$e->details['error_message'], $e->details['attempt']])->all(),
        );
        $this->assertSame(['agent', 'agent'], $failures->pluck('actor_type')->all());
        $this->assertSame(['factory-pc', 'factory-pc'], $failures->pluck('actor_label')->all());
    }

    // ---- (e) failure refused ---------------------------------------------

    public function test_a_failure_reported_for_a_voucher_in_tally_records_the_refusal_not_a_failure(): void
    {
        [, $plainToken] = $this->agent('factory-pc');
        $voucher = $this->voucher(['status' => TallySyncStatus::Synced, 'synced_at' => now()]);

        $this->withToken($plainToken)
            ->postJson("/api/v1/tally-sync/entries/{$voucher->id}/fail", ['error_message' => 'timeout of 15000ms exceeded'])
            ->assertOk()
            ->assertJsonPath('data.status', 'synced');

        $this->assertSame(['voucher.failure_refused'], $this->timeline($voucher));
        $refused = $voucher->events()->sole();
        $this->assertSame('timeout of 15000ms exceeded', $refused->details['error_message']);
        $this->assertSame('agent', $refused->actor_type);
        $this->assertSame('factory-pc', $refused->actor_label);
        $this->assertSame(0, TallySyncEvent::query()->where('event', 'voucher.failed')->count());
    }

    // ---- (f) delivered → failed → retried → delivered --------------------

    public function test_a_retried_voucher_reads_delivered_failed_retried_delivered_in_order(): void
    {
        $sync = app(TallySyncService::class);
        $agent = $this->agentUser('factory-pc');
        $accountant = $this->staff('Priya Accounts');
        $voucher = $this->voucher();

        $sync->pending($agent);
        $sync->markFailed($voucher->fresh(), 'Godown does not exist', $agent);
        $sync->retry($voucher->fresh(), $accountant->id, $accountant);
        $sync->pending($agent);

        $this->assertSame(
            ['pending.delivered', 'voucher.failed', 'voucher.retried', 'pending.delivered'],
            $this->timeline($voucher),
        );

        $retried = $voucher->events()->where('event', 'voucher.retried')->sole();
        // A person, by name — the token-less half of the actor rule.
        $this->assertSame('user', $retried->actor_type);
        $this->assertSame($accountant->id, $retried->actor_id);
        $this->assertSame('Priya Accounts', $retried->actor_label);
        $this->assertSame('Godown does not exist', $retried->details['previous_error']);
        // No rebuilder for a morph that does not resolve: the frozen payload
        // was replayed, and the row says so rather than claiming a rebuild.
        $this->assertFalse($retried->details['payload_regenerated']);

        // Both deliveries carry the agent; the entry itself has only one
        // delivered_at to show for them.
        $deliveries = $voucher->events()->where('event', 'pending.delivered')->get();
        $this->assertCount(2, $deliveries);
        $this->assertSame(['factory-pc', 'factory-pc'], $deliveries->pluck('actor_label')->all());
    }

    // ---- (g) retry / dismiss / release through the API carry the user ----

    public function test_retry_dismiss_and_release_through_the_api_record_the_acting_user(): void
    {
        $accountant = $this->staff('Priya Accounts');
        $this->actingAs($accountant);

        $failed = $this->voucher(['status' => TallySyncStatus::Failed, 'error_message' => 'Godown does not exist', 'attempts' => 1, 'delivered_at' => now()]);
        $this->postJson("/api/v1/tally-sync/entries/{$failed->id}/retry")->assertOk();

        $dead = $this->voucher(['payload' => ['voucher_number' => 'SPE-9'], 'status' => TallySyncStatus::Failed, 'error_message' => "Could not set 'SVCurrentCompany'", 'attempts' => 3]);
        $this->postJson("/api/v1/tally-sync/entries/{$dead->id}/dismiss")->assertOk();

        // A held shift voucher: the release gate holds a Shift-morph
        // voucher whose shift has not ended (see ShiftVoucherReleaseGate).
        config(['tally-sync.voucher_granularity' => 'shift', 'tally-sync.release_idle_minutes' => 15, 'tally-sync.factory_timezone' => 'UTC']);
        $this->travelTo('2026-07-23 10:00:00');
        $entry = $this->approvedShiftEntry('5000', '250.0000');
        $held = app(TallySyncService::class)->enqueueShiftProductionEntry($entry);
        $this->postJson("/api/v1/tally-sync/entries/{$held->id}/release")->assertOk();

        foreach ([
            ['voucher.retried', $failed],
            ['voucher.dismissed', $dead],
            ['voucher.released', $held],
        ] as [$name, $voucher]) {
            $event = $voucher->events()->where('event', $name)->sole();
            $this->assertSame('user', $event->actor_type, $name);
            $this->assertSame($accountant->id, $event->actor_id, $name);
            $this->assertSame('Priya Accounts', $event->actor_label, $name);
        }

        $this->assertSame("Could not set 'SVCurrentCompany'", $dead->events()->where('event', 'voucher.dismissed')->sole()->details['previous_error']);
        $this->assertSame($held->voucherNumber(), $held->events()->where('event', 'voucher.released')->sole()->details['voucher_number']);
    }

    // ---- (h) shift mode: enqueued once, merged once ----------------------

    public function test_two_shift_approvals_record_one_enqueued_and_one_merged_naming_who_joined(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);
        $sync = app(TallySyncService::class);

        $first = $this->approvedShiftEntry('5000', '250.0000');
        $sync->enqueueShiftProductionEntry($first);
        $second = $this->approvedShiftEntry('3000', '100.0000');
        $sync->enqueueShiftProductionEntry($second);

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame(['voucher.enqueued', 'voucher.merged'], $this->timeline($voucher));

        [$enqueued, $merged] = $voucher->events()->get()->all();
        $this->assertSame('Stock Journal', $enqueued->details['voucher_type']);
        $this->assertSame($voucher->payload['voucher_number'], $enqueued->details['voucher_number']);
        $this->assertSame([$first->id], $enqueued->details['entry_ids']);
        $this->assertArrayNotHasKey('follow_up_of', $enqueued->details, 'The first voucher of a shift follows nothing');

        // WHICH entries joined — the fact last_merged_at can never say.
        $this->assertSame([$second->id], $merged->details['joined_entry_ids']);
        $this->assertSame($voucher->payload['voucher_number'], $merged->details['voucher_number']);
    }

    public function test_a_follow_up_voucher_is_enqueued_naming_the_voucher_it_follows(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);
        $sync = app(TallySyncService::class);

        $sync->enqueueShiftProductionEntry($this->approvedShiftEntry('5000', '250.0000'));
        $closed = TallySyncEntry::query()->sole();
        $sync->markSynced($closed);

        $late = $this->approvedShiftEntry('700', '30.0000');
        $followUp = $sync->enqueueShiftProductionEntry($late);

        $this->assertNotSame($closed->id, $followUp->id);
        $enqueued = $followUp->events()->where('event', 'voucher.enqueued')->sole();
        $this->assertSame($closed->id, $enqueued->details['follow_up_of']);
        $this->assertSame([$late->id], $enqueued->details['entry_ids']);
        $this->assertStringEndsWith('-2', $enqueued->details['voucher_number']);
    }

    public function test_a_rebuild_that_left_a_fixture_off_the_lines_records_voucher_rebuilt(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);
        $sync = app(TallySyncService::class);

        $fixtureIdentity = Item::create([
            'sku' => 'LOCAL-500ML-TRAY', 'name' => '500ml Tray Pack (LOCAL FIXTURE)', 'uom' => 'NOS',
            'is_local_fixture' => true,
        ]);
        $sync->enqueueShiftProductionEntry($this->approvedShiftEntry('5000', '250.0000'));
        $voucher = TallySyncEntry::query()->sole();

        // A fixture stamped onto the voucher under the old sweep — the live
        // hole LocalFixtureSweepTest closes.
        $stranded = $this->approvedShiftEntry('700', '30.0000', ['finished_item_id' => $fixtureIdentity->id]);
        ShiftProductionEntry::query()->whereKey($stranded->id)->update(['tally_sync_entry_id' => $voucher->id]);

        $voucher->update(['status' => TallySyncStatus::Failed, 'error_message' => 'Stock Item does not exist', 'delivered_at' => now()]);
        $sync->retry($voucher->fresh());

        $rebuilt = $voucher->events()->where('event', 'voucher.rebuilt')->sole();
        $this->assertSame('retry', $rebuilt->details['rebuild']);
        $this->assertSame([$stranded->id], $rebuilt->details['excluded_entry_ids']);
        $this->assertSame(['voucher.enqueued', 'voucher.rebuilt', 'voucher.retried'], $this->timeline($voucher));
    }

    // ---- (i) inbound: masters pull ---------------------------------------

    public function test_a_masters_pull_records_masters_received_inbound_with_no_entry(): void
    {
        [$agent, $plainToken] = $this->agent('factory-pc');

        $this->withToken($plainToken)->postJson('/api/v1/tally-sync/masters', [
            'company' => 'SWAASHPET POLYMERS PVT LTD',
            'godowns' => [['guid' => 'gd-main', 'name' => 'Main Store']],
            'items' => [['guid' => 'i-1', 'name' => 'PET Resin', 'base_unit' => 'Kg', 'parent' => 'Primary']],
        ])->assertOk();

        $received = TallySyncEvent::query()->where('event', 'masters.received')->sole();
        $this->assertSame('tally_to_erp', $received->direction);
        $this->assertNull($received->tally_sync_entry_id);
        $this->assertSame('agent', $received->actor_type);
        $this->assertSame($agent->id, $received->actor_id);
        $this->assertSame('factory-pc', $received->actor_label);
        $this->assertSame('SWAASHPET POLYMERS PVT LTD', $received->details['company']);
        // Counts, not masters: the rows themselves live on their own tables.
        $this->assertSame(1, $received->details['godowns']['created']);
        $this->assertSame(1, $received->details['items']['created']);

        // Trust-on-first-use binding is its own inbound event.
        $bound = TallySyncEvent::query()->where('event', 'company.bound')->sole();
        $this->assertSame('tally_to_erp', $bound->direction);
        $this->assertNull($bound->tally_sync_entry_id);
        $this->assertSame('SWAASHPET POLYMERS PVT LTD', $bound->details['company']);
        $this->assertLessThan($received->id, $bound->id, 'Bound before received, as it happened');
    }

    // ---- (j) append-only -------------------------------------------------

    public function test_events_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $voucher = $this->voucher();
        $event = app(TallySyncEventRecorder::class)->record(TallySyncEventKind::VoucherEnqueued, $voucher, ['voucher_number' => 'SPE-1']);

        try {
            $event->update(['event' => 'voucher.synced']);
            $this->fail('update() must throw');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $event->delete();
            $this->fail('delete() must throw');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $stored = TallySyncEvent::query()->sole();
        $this->assertSame('voucher.enqueued', $stored->event, 'The row still says what it said');
        $this->assertNull($stored->getAttribute('updated_at'), 'There is no updated_at to write');
    }

    // ---- the wire shape --------------------------------------------------

    public function test_the_event_resource_carries_who_when_and_what_but_not_the_entry(): void
    {
        $voucher = $this->voucher();
        $event = app(TallySyncEventRecorder::class)->record(
            TallySyncEventKind::PendingDelivered,
            $voucher,
            ['voucher_type' => 'Manufacturing Journal', 'voucher_number' => 'SPE-1'],
            actor: $this->agentUser('factory-pc'),
        );

        $shape = TallySyncEventResource::make($event)->resolve();

        $this->assertSame(
            ['id', 'event', 'direction', 'occurred_at', 'actor', 'details', 'backfilled'],
            array_keys($shape),
        );
        $this->assertSame('pending.delivered', $shape['event']);
        $this->assertSame('erp_to_tally', $shape['direction']);
        $this->assertSame($event->occurred_at->toIso8601String(), $shape['occurred_at']);
        $this->assertSame(['type' => 'agent', 'id' => $event->actor_id, 'label' => 'factory-pc'], $shape['actor']);
        $this->assertSame(['voucher_type' => 'Manufacturing Journal', 'voucher_number' => 'SPE-1'], $shape['details']);
        $this->assertFalse($shape['backfilled']);
        // The entry resource says what the voucher IS; this never repeats it.
        $this->assertArrayNotHasKey('payload', $shape);
        $this->assertArrayNotHasKey('status', $shape);
    }

    // ---- (k) a refused mutation leaves no row ----------------------------

    public function test_a_refused_retry_records_nothing(): void
    {
        $this->actingAs($this->staff());
        $inTally = $this->voucher(['status' => TallySyncStatus::Synced, 'synced_at' => now()]);
        $pending = $this->voucher(['payload' => ['voucher_number' => 'SPE-2']]);

        $this->postJson("/api/v1/tally-sync/entries/{$inTally->id}/retry")->assertStatus(422);
        $this->postJson("/api/v1/tally-sync/entries/{$pending->id}/dismiss")->assertStatus(422);
        $this->postJson("/api/v1/tally-sync/entries/{$pending->id}/release")->assertStatus(422);

        $this->assertSame(0, TallySyncEvent::query()->count(), 'A refusal that throws is not a mutation, and records none');
    }
}
