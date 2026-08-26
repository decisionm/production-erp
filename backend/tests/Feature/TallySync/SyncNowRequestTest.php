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
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Modules\TallySync\Services\AgentTokenService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "Sync Now" on the Tally Sync page — DEC-20260825-002.
 *
 * The owner's words, and what each half is pinned by below:
 *
 *   "request that currently queued outbound vouchers post on the next agent
 *   poll"                       → the eligible set IS the release gate's held
 *                                 set, and a released voucher is offered by
 *                                 the very next pending() poll
 *   "it never pulls masters/stock from Tally"
 *                               → nothing inbound is written, nothing is
 *                                 delivered, acked or posted from the browser
 *   "Only Owner/Accounts permission may press it"
 *                               → 403 for every other login, server-side
 *   "If agent is offline the request waits safely"
 *                               → released_at is stamped, delivered_at is not,
 *                                 and the answer says so instead of claiming a post
 *   "Duplicate clicks must not duplicate vouchers"
 *                               → the second press frees nothing, creates no
 *                                 entry, and does not re-stamp released_at
 *
 * DEC-20260807-011's automatic gate is not loosened anywhere here: what this
 * button does to a held voucher is exactly what the accountant's per-voucher
 * "Release now" already did, applied to the whole held set at once.
 */
class SyncNowRequestTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/tally-sync/sync-now';

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
        config(['tally-sync.release_idle_minutes' => 15]);
        // Clock and wall clock in one frame: this file is about the button,
        // not about the IST/UTC split (ShiftVoucherReleaseGateTest pins that).
        config(['tally-sync.factory_timezone' => 'UTC']);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);
        $this->approver = User::factory()->create();
    }

    // ---- fixtures -----------------------------------------------------------

    /** An approved shift entry, which enqueues (or merges into) its shift voucher. */
    private function approvedEntry(string $produced, string $date = '2026-07-23'): ShiftProductionEntry
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
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

    /**
     * A raw queue row of any status — for the rows Sync Now must NOT touch.
     * Deliberately built directly rather than through an enqueue path: what
     * is being pinned is the SET the button acts on, and these are the
     * shapes the gate's first guard excludes.
     */
    private function rawEntry(string $type, TallySyncStatus $status, array $extra = []): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => (new ShiftProductionEntry)->getMorphClass(),
            'syncable_id' => 9000 + TallySyncEntry::query()->count(),
            'tally_voucher_type' => $type,
            'status' => $status,
            'payload' => ['voucher_number' => "RAW-{$type}", 'voucher_date' => '2026-07-23'],
        ] + $extra);
    }

    /** A login holding exactly the named permissions (created on demand, as the suite does elsewhere). */
    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** An Owner/Accounts login: tally-sync.manage AND the FC-06 finance pair. */
    private function accountant(): User
    {
        return $this->userWith('tally-sync.manage', 'finance.manage');
    }

    /** The voucher numbers the agent's next poll would be offered. */
    private function offered(): array
    {
        return app(TallySyncService::class)->pending()
            ->map(fn (TallySyncEntry $entry) => $entry->voucherNumber())
            ->all();
    }

    /** Mid-shift, so the gate is holding the voucher it enqueues. */
    private function heldVoucher(): TallySyncEntry
    {
        $this->travelTo(Carbon::parse('2026-07-23 10:00:00'));
        $this->approvedEntry('5000');

        $voucher = TallySyncEntry::query()->latest('id')->first();
        $this->assertSame([], $this->offered(), 'Fixture precondition: the gate must be holding this voucher.');

        return $voucher;
    }

    // ---- authorization ------------------------------------------------------

    public function test_an_owner_accounts_login_may_press_it(): void
    {
        $this->heldVoucher();
        Sanctum::actingAs($this->accountant());

        $this->postJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.outcome', 'released')
            ->assertJsonPath('data.released', 1);
    }

    public function test_finance_view_alone_is_enough_owner_accounts_standing(): void
    {
        // FC-06's Owner/Accounts test is finance.view OR finance.manage —
        // the same pair AgentIdentity::mayReadPurchaseDetails uses. A reader
        // who holds only the view half is still Owner/Accounts.
        $this->heldVoucher();

        Sanctum::actingAs($this->userWith('tally-sync.manage', 'finance.view'));

        $this->postJson(self::ENDPOINT)->assertOk();
    }

    public function test_a_tally_sync_manager_who_is_not_owner_accounts_is_refused(): void
    {
        // The role this is really about: whoever runs the sync queue day to
        // day may still retry, dismiss and release ONE voucher. Sending the
        // whole queue to the live books is the owner's or Accounts' press.
        $voucher = $this->heldVoucher();

        Sanctum::actingAs($this->userWith('tally-sync.manage'));

        $this->postJson(self::ENDPOINT)->assertForbidden();

        $this->assertNull($voucher->fresh()->released_at, 'A refused press must not release anything.');
    }

    public function test_every_other_role_is_refused(): void
    {
        $this->heldVoucher();

        $cases = [
            'no permissions at all' => [],
            'read-only on the queue' => ['tally-sync.view'],
            // Owner/Accounts standing WITHOUT the queue's own manage
            // permission: the group middleware refuses the POST first.
            'finance but no tally-sync' => ['finance.manage'],
            'finance + queue read only' => ['finance.manage', 'tally-sync.view'],
            'production supervisor' => ['production.manage'],
            'store' => ['inventory.manage'],
            'sales' => ['sales.manage'],
            'quality' => ['quality.manage'],
        ];

        foreach ($cases as $label => $permissions) {
            Sanctum::actingAs($this->userWith(...$permissions));

            $this->postJson(self::ENDPOINT)->assertForbidden("{$label} must not be able to press Sync Now");
        }

        $this->assertSame(0, TallySyncEntry::query()->whereNotNull('released_at')->count());
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->heldVoucher();

        $this->postJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_the_sync_agents_own_token_cannot_press_it(): void
    {
        // The agent COLLECTS; it never asks. Its token carries abilities,
        // not permissions, so the module gate refuses it — pinned so a
        // future ability-based shortcut cannot open this door by accident.
        $this->heldVoucher();

        $issued = app(AgentTokenService::class)->issueToken('factory-pc');

        $this->withHeader('Authorization', 'Bearer '.$issued['plainTextToken'])
            ->postJson(self::ENDPOINT)
            ->assertForbidden();
    }

    // ---- what it does, and to exactly which rows -----------------------------

    public function test_it_releases_the_gates_held_set_and_nothing_else(): void
    {
        $held = $this->heldVoucher();

        // Everything the gate's first guard excludes, one row each.
        $failed = $this->rawEntry('Sales', TallySyncStatus::Failed, ['error_message' => 'Ledger missing']);
        $dismissed = $this->rawEntry('Journal', TallySyncStatus::Dismissed);
        $synced = $this->rawEntry('Delivery Note', TallySyncStatus::Synced, ['synced_at' => now()]);
        $delivered = $this->rawEntry('Receipt Note', TallySyncStatus::Pending, ['delivered_at' => now()]);
        // A batch-mode voucher: pending and undelivered, but never held.
        $batch = $this->rawEntry('Manufacturing Journal', TallySyncStatus::Pending);

        Sanctum::actingAs($this->accountant());
        $response = $this->postJson(self::ENDPOINT)->assertOk();

        $this->assertNotNull($held->fresh()->released_at, 'The held voucher is the one row this frees.');
        $this->assertSame([$held->id], $response->json('data.released_entry_ids'));

        foreach ([$failed, $dismissed, $synced, $delivered, $batch] as $untouched) {
            $this->assertNull(
                $untouched->fresh()->released_at,
                "{$untouched->tally_voucher_type} ({$untouched->status->value}) must not be released by Sync Now",
            );
        }

        // Statuses are exactly as they were: nothing was retried, revived,
        // written off or marked synced on the way past.
        $this->assertSame(TallySyncStatus::Failed, $failed->fresh()->status);
        $this->assertSame(TallySyncStatus::Dismissed, $dismissed->fresh()->status);
        $this->assertSame(TallySyncStatus::Synced, $synced->fresh()->status);
        $this->assertSame(TallySyncStatus::Pending, $held->fresh()->status);
    }

    public function test_a_failed_voucher_is_never_silently_retried(): void
    {
        $this->heldVoucher();
        $failed = $this->rawEntry('Sales', TallySyncStatus::Failed, [
            'error_message' => 'Tally: ledger "Acme" does not exist',
            'attempts' => 2,
        ]);

        Sanctum::actingAs($this->accountant());
        $this->postJson(self::ENDPOINT)->assertOk();

        $fresh = $failed->fresh();
        $this->assertSame(TallySyncStatus::Failed, $fresh->status);
        $this->assertSame('Tally: ledger "Acme" does not exist', $fresh->error_message);
        $this->assertSame(2, $fresh->attempts);
        // The held shift voucher IS freed by the press (that is the point);
        // what must never appear in the agent's hands is the failed row.
        $this->assertNotContains(
            $failed->voucherNumber(),
            $this->offered(),
            'A failed voucher must not be handed to the agent by Sync Now.',
        );
    }

    public function test_the_counts_separate_freed_waiting_and_with_the_agent(): void
    {
        $this->heldVoucher();
        $this->rawEntry('Sales', TallySyncStatus::Pending);                             // deliverable already
        $this->rawEntry('Journal', TallySyncStatus::Pending, ['delivered_at' => now()]); // with the agent
        $this->rawEntry('Delivery Note', TallySyncStatus::Synced, ['synced_at' => now()]);

        Sanctum::actingAs($this->accountant());

        $this->postJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.outcome', 'released')
            ->assertJsonPath('data.released', 1)
            ->assertJsonPath('data.already_queued', 1)
            ->assertJsonPath('data.with_agent', 1)
            // Pending rows only — the synced one is in the books and is not
            // "queued" by any reading.
            ->assertJsonPath('data.queued_total', 3);
    }

    public function test_an_empty_queue_answers_nothing_queued_rather_than_failing(): void
    {
        Sanctum::actingAs($this->accountant());

        $this->postJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.outcome', 'nothing_queued')
            ->assertJsonPath('data.released', 0)
            ->assertJsonPath('data.queued_total', 0);
    }

    public function test_a_queue_with_nothing_held_answers_already_queued(): void
    {
        $this->rawEntry('Sales', TallySyncStatus::Pending);
        Sanctum::actingAs($this->accountant());

        $this->postJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already_queued')
            ->assertJsonPath('data.released', 0);
    }

    // ---- it reaches Tally in no way at all ----------------------------------

    public function test_it_neither_delivers_posts_nor_pulls_anything_from_tally(): void
    {
        $held = $this->heldVoucher();
        $entriesBefore = TallySyncEntry::query()->count();

        Sanctum::actingAs($this->accountant());
        $this->postJson(self::ENDPOINT)->assertOk();

        $fresh = $held->fresh();
        $this->assertNull($fresh->delivered_at, 'Only the agent may collect a voucher — never the browser.');
        $this->assertNull($fresh->synced_at, 'Only the agent may report what Tally took.');
        $this->assertSame(0, $fresh->attempts, 'A request is not an attempt.');

        // No queue row was created, so no voucher can have been created.
        $this->assertSame($entriesBefore, TallySyncEntry::query()->count());

        // Nothing INBOUND happened: a masters pull, a company binding and a
        // stock-summary preview are the three Tally→ERP flows, and Sync Now
        // is not a route to any of them.
        $inbound = TallySyncEvent::query()
            ->whereIn('event', [
                TallySyncEventKind::MastersReceived->value,
                TallySyncEventKind::CompanyBound->value,
                TallySyncEventKind::CompaniesReceived->value,
                TallySyncEventKind::StockSummaryPreviewed->value,
            ])
            ->count();
        $this->assertSame(0, $inbound, 'Sync Now must never pull masters or stock from Tally.');

        $this->assertSame(
            0,
            TallySyncEvent::query()->whereIn('event', [
                TallySyncEventKind::PendingDelivered->value,
                TallySyncEventKind::VoucherSynced->value,
                TallySyncEventKind::VoucherFailed->value,
            ])->count(),
            'Nothing was delivered, accepted or rejected — no poll has happened yet.',
        );
    }

    // ---- offline ------------------------------------------------------------

    public function test_with_no_agent_running_the_request_simply_waits(): void
    {
        $held = $this->heldVoucher();

        Sanctum::actingAs($this->accountant());
        $response = $this->postJson(self::ENDPOINT)->assertOk();

        // No token has ever authenticated, so there is no "last checked".
        // Null is "never", and the page must not read it as "posted".
        $this->assertNull($response->json('data.agent.last_checked_at'));

        $fresh = $held->fresh();
        $this->assertNotNull($fresh->released_at, 'The release is durable — it survives the agent being off.');
        $this->assertNull($fresh->delivered_at);
        $this->assertSame(TallySyncStatus::Pending, $fresh->status);

        // And when the factory PC does come back, the very next poll takes it.
        $this->assertSame([$held->voucherNumber()], $this->offered());
    }

    public function test_a_released_voucher_is_offered_to_the_next_poll_exactly_once(): void
    {
        $held = $this->heldVoucher();

        Sanctum::actingAs($this->accountant());
        $this->postJson(self::ENDPOINT)->assertOk();

        $this->assertSame([$held->voucherNumber()], $this->offered(), 'The next poll is offered the freed voucher.');
        $this->assertNotNull($held->fresh()->delivered_at, 'That poll stamped it delivered.');

        // Re-polls keep handing an unacked voucher back (the agent's own
        // idempotency line is delivered_at, not absence) — but it is the
        // SAME row, never a second voucher.
        $this->assertSame([$held->voucherNumber()], $this->offered());
        $this->assertSame(1, TallySyncEntry::query()->count());
    }

    // ---- double clicks ------------------------------------------------------

    public function test_a_second_press_frees_nothing_and_duplicates_nothing(): void
    {
        $held = $this->heldVoucher();
        Sanctum::actingAs($this->accountant());

        $this->postJson(self::ENDPOINT)->assertOk()->assertJsonPath('data.released', 1);
        $releasedAt = $held->fresh()->released_at;
        $entriesAfterFirst = TallySyncEntry::query()->count();

        // The impatient second click, a second later.
        $this->travel(1)->second();
        $this->postJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already_queued')
            ->assertJsonPath('data.released', 0)
            ->assertJsonPath('data.released_entry_ids', []);

        $this->assertEquals($releasedAt, $held->fresh()->released_at, 'released_at is stamped once, not re-stamped.');
        $this->assertSame($entriesAfterFirst, TallySyncEntry::query()->count(), 'No second queue row — so no second voucher.');
        $this->assertSame(
            1,
            TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherReleased->value)->count(),
            'One release happened, so the voucher timeline says so once.',
        );
    }

    public function test_pressing_it_five_times_still_posts_one_voucher(): void
    {
        $held = $this->heldVoucher();
        Sanctum::actingAs($this->accountant());

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::ENDPOINT)->assertOk();
        }

        $this->assertSame(1, TallySyncEntry::query()->count());
        $this->assertSame([$held->voucherNumber()], $this->offered());
        // The agent's own guard: a second poll re-offers the same row, and
        // markSynced is what ends it. The button never multiplied the work.
        $this->assertSame(1, TallySyncEntry::query()->count());
    }

    // ---- the audit ----------------------------------------------------------

    public function test_every_press_is_audited_even_the_ones_that_free_nothing(): void
    {
        $user = $this->accountant();
        Sanctum::actingAs($user);

        // Nothing queued at all — the press that changes least is exactly
        // the one an unaudited implementation would lose.
        $this->postJson(self::ENDPOINT)->assertOk();

        $event = TallySyncEvent::query()->where('event', TallySyncEventKind::SyncRequested->value)->sole();

        $this->assertNull($event->tally_sync_entry_id, 'A queue-wide request belongs to no single voucher.');
        $this->assertSame(TallySyncEvent::ACTOR_USER, $event->actor_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame($user->name, $event->actor_label);
        $this->assertSame('nothing_queued', $event->details['outcome']);
        $this->assertSame(0, $event->details['released']);
        $this->assertSame(TallySyncEvent::DIRECTION_ERP_TO_TALLY, $event->direction);
    }

    public function test_two_presses_leave_two_audit_rows_even_though_the_effect_coalesced(): void
    {
        $this->heldVoucher();
        Sanctum::actingAs($this->accountant());

        $this->postJson(self::ENDPOINT)->assertOk();
        $this->travel(1)->second();
        $this->postJson(self::ENDPOINT)->assertOk();

        $events = TallySyncEvent::query()
            ->where('event', TallySyncEventKind::SyncRequested->value)
            ->orderBy('id')
            ->get();

        // Two people pressing (or one person twice) really was two asks.
        // The EFFECT coalesces; the record of who asked must not.
        $this->assertCount(2, $events);
        $this->assertSame(1, $events[0]->details['released']);
        $this->assertSame(0, $events[1]->details['released']);
    }

    public function test_a_freed_voucher_carries_the_release_on_its_own_timeline(): void
    {
        $held = $this->heldVoucher();
        $user = $this->accountant();
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT)->assertOk();

        // The same event kind releaseNow() writes, so the drawer's timeline
        // reads identically however the accountant freed it.
        $event = TallySyncEvent::query()
            ->where('event', TallySyncEventKind::VoucherReleased->value)
            ->sole();

        $this->assertSame($held->id, $event->tally_sync_entry_id);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertTrue($event->details['released_by_request']);
        $this->assertSame($user->id, $held->fresh()->released_by);
    }

    // ---- concurrency --------------------------------------------------------

    public function test_the_queue_read_is_locked_and_shares_one_transaction_with_the_release(): void
    {
        // PHPUnit runs one SQLite connection, so nothing here executes two
        // genuinely simultaneous presses. What is asserted is the mechanism
        // the protection depends on (StartBatchConcurrencyTest's precedent):
        // the pending rows are READ inside a transaction and the UPDATE that
        // frees them is inside the SAME one, so no second press — and no
        // agent poll, which takes the same lock in pending() — can slip
        // between the read and the write.
        $this->heldVoucher();

        DB::enableQueryLog();
        app(TallySyncService::class)->requestSyncNow(null, null);
        $log = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower(str_replace('`', '"', $q)));
        DB::disableQueryLog();

        $read = $log->search(fn (string $q) => str_starts_with($q, 'select') && str_contains($q, 'from "tally_sync_entries"'));
        $update = $log->search(fn (string $q) => str_starts_with($q, 'update "tally_sync_entries"'));

        $this->assertNotFalse($read, 'The queue is never read — there is nothing to serialise presses on.');
        $this->assertNotFalse($update, 'No release was written.');
        $this->assertLessThan($update, $read, 'The locked read must precede the release, or two presses can both see the row held.');

        $between = $log->slice($read, $update - $read);
        $this->assertFalse(
            $between->contains(fn (string $q) => str_starts_with($q, 'commit')),
            'Nothing may commit between reading the held set and releasing it.',
        );
    }

    public function test_the_queue_read_really_emits_for_update_on_mysql(): void
    {
        // The live instance is MySQL, where lockForUpdate() is a real row
        // lock; SQLite silently drops it, so every assertion above would
        // still pass with the lock deleted from the service. This is the
        // half that would not.
        $locked = TallySyncEntry::query()->where('status', TallySyncStatus::Pending)->lockForUpdate()->toBase();
        $this->assertTrue($locked->lock, 'lockForUpdate() did not register a lock on the builder.');

        $connection = DB::connection();
        $mysql = new QueryBuilder($connection, new MySqlGrammar($connection), $connection->getPostProcessor());
        $sql = $mysql->from('tally_sync_entries')->where('status', 'pending')->lockForUpdate()->toSql();

        $this->assertStringContainsString('for update', strtolower($sql));
    }

    // ---- agent freshness ----------------------------------------------------

    public function test_the_agents_last_check_in_is_read_from_a_real_token_use(): void
    {
        // Sanctum::actingAs installs a TransientToken and never touches
        // last_used_at, so this has to authenticate the way the factory PC
        // does: a real personal access token on the Authorization header.
        $this->assertNull(AgentIdentity::lastCheckedAt(), 'Nothing has ever checked in yet.');

        $issued = app(AgentTokenService::class)->issueToken('factory-pc');
        $this->travelTo(Carbon::parse('2026-07-23 09:00:00'));

        $this->withHeader('Authorization', 'Bearer '.$issued['plainTextToken'])
            ->getJson('/api/v1/tally-sync/pending')
            ->assertOk();

        $checked = AgentIdentity::lastCheckedAt();
        $this->assertNotNull($checked, 'A poll is a check-in, even one that delivers nothing.');
        $this->assertSame('2026-07-23 09:00:00', $checked->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_summary_reports_the_check_in_without_naming_the_token(): void
    {
        $issued = app(AgentTokenService::class)->issueToken('factory-pc');
        $this->travelTo(Carbon::parse('2026-07-23 09:00:00'));
        $this->withHeader('Authorization', 'Bearer '.$issued['plainTextToken'])
            ->getJson('/api/v1/tally-sync/pending')->assertOk();

        Sanctum::actingAs($this->accountant());
        $response = $this->getJson('/api/v1/tally-sync/summary')->assertOk();

        $this->assertNotNull($response->json('data.agent.last_checked_at'));

        // A timestamp, and nothing that identifies the installation or what
        // it is allowed to do.
        $agent = $response->json('data.agent');
        $this->assertSame(['last_action_at', 'last_action_event', 'last_action_label', 'last_checked_at'], array_keys($agent));
        $body = $response->getContent();
        $this->assertStringNotContainsString('tally-sync:poll', $body, 'Token abilities must never reach the page.');
        $this->assertStringNotContainsString($issued['plainTextToken'], $body);
    }

    public function test_any_token_that_can_poll_counts_as_the_agent_checking_in(): void
    {
        // The freshness light is judged on ABILITIES, the same test /pending
        // itself applies — not on which user owns the token. A token issued
        // outside AgentTokenService that can poll IS an agent polling, and
        // reading it as "never checked in" would put a falsely dark light
        // over a factory PC that is demonstrably alive.
        $installer = User::factory()->create(['name' => 'Second factory PC']);
        $token = $installer->createToken('second-pc', ['tally-sync:poll', 'tally-sync:report'])->plainTextToken;

        $this->travelTo(Carbon::parse('2026-07-23 09:00:00'));
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/tally-sync/pending')
            ->assertOk();

        $this->assertSame('2026-07-23 09:00:00', AgentIdentity::lastCheckedAt()?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_token_without_agent_abilities_is_not_a_check_in(): void
    {
        // A person driving the API from a laptop is not the factory PC
        // (the same distinction TallySyncEventRecorder::describeActor
        // draws), and must not light the agent as alive.
        $staff = $this->userWith('tally-sync.manage', 'finance.manage');
        $laptop = $staff->createToken('laptop', [])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$laptop)
            ->getJson('/api/v1/tally-sync/summary')
            ->assertOk();

        $this->assertNull(AgentIdentity::lastCheckedAt(), 'A laptop is not the agent checking in.');
    }

    public function test_a_wildcard_token_does_not_light_the_agent_as_alive(): void
    {
        // THE ONE THAT ACTUALLY BIT. createToken($name) with no abilities
        // argument is Sanctum's DEFAULT and yields ['*'] — exactly what an
        // external API client gets (CLAUDE.md #3) — and can() answers TRUE
        // for every ability on such a token. One call from a laptop used to
        // light the agent green while the factory PC was switched off, and
        // the page then promised a post "on its next check" that was not
        // coming. Liveness is judged on the abilities LITERALLY provisioned,
        // which is deliberately stricter than the authorization test.
        $staff = $this->userWith('tally-sync.manage', 'finance.manage');
        $wildcard = $staff->createToken('an external client')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$wildcard)
            ->getJson('/api/v1/tally-sync/summary')
            ->assertOk();

        $this->assertNull(
            AgentIdentity::lastCheckedAt(),
            'A wildcard token is not the factory PC, and a falsely bright liveness light promises a post nobody will make.',
        );
    }

    public function test_a_wildcard_token_is_still_allowed_to_poll(): void
    {
        // The other half, and why the liveness predicate is separate rather
        // than a tightening of the authorization one: a ['*'] token really
        // CAN poll, and always could. Narrowing AgentIdentity::isAgent to
        // fix the light would have narrowed FC-06's payload gate with it —
        // the refusal set this refactor must preserve is the OLD one.
        $staff = User::factory()->create();
        $wildcard = $staff->createToken('an external client')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$wildcard)
            ->getJson('/api/v1/tally-sync/pending')
            ->assertOk();
    }

    public function test_an_idle_poll_moves_the_check_in_while_last_action_stands_still(): void
    {
        // The exact reason last_checked_at exists: a poll that finds nothing
        // records no event, so "Agent last action" can be hours stale on an
        // agent that is alive and polling.
        $issued = app(AgentTokenService::class)->issueToken('factory-pc');
        $this->travelTo(Carbon::parse('2026-07-23 09:00:00'));
        $this->withHeader('Authorization', 'Bearer '.$issued['plainTextToken'])
            ->getJson('/api/v1/tally-sync/pending')->assertOk();

        Sanctum::actingAs($this->accountant());
        $summary = $this->getJson('/api/v1/tally-sync/summary')->assertOk();

        $this->assertNull($summary->json('data.agent.last_action_at'), 'An idle poll delivers nothing, so it records nothing.');
        $this->assertNotNull($summary->json('data.agent.last_checked_at'), 'But the agent was demonstrably here.');
    }

    // ---- the voucher-type filter's options ----------------------------------

    public function test_the_summary_offers_the_raw_voucher_types_the_queue_holds(): void
    {
        // The RAW label, because that is the column the filter matches. A
        // batch production voucher is 'Manufacturing Journal' here and posts
        // as a Stock Journal on the wire — offering the wire type would be a
        // filter that always returns nothing.
        $this->rawEntry('Manufacturing Journal', TallySyncStatus::Pending);
        $this->rawEntry('Sales', TallySyncStatus::Synced, ['synced_at' => now()]);
        $this->rawEntry('Sales', TallySyncStatus::Failed);

        Sanctum::actingAs($this->accountant());
        $response = $this->getJson('/api/v1/tally-sync/summary')->assertOk();

        $this->assertSame(['Manufacturing Journal', 'Sales'], $response->json('data.voucher_types'));
    }

    public function test_the_voucher_type_options_do_not_shrink_with_the_filter(): void
    {
        // A dropdown narrowed to what is already selected cannot be used to
        // change the selection — so this one list stays unfiltered while
        // every count beside it follows the page.
        $this->rawEntry('Manufacturing Journal', TallySyncStatus::Pending);
        $this->rawEntry('Sales', TallySyncStatus::Pending);

        Sanctum::actingAs($this->accountant());
        $response = $this->getJson('/api/v1/tally-sync/summary?voucher_type[]=Sales')->assertOk();

        $this->assertSame(['Manufacturing Journal', 'Sales'], $response->json('data.voucher_types'));
        $this->assertSame(1, $response->json('data.all_time.total'), 'The counts, unlike the options, do follow the filter.');
    }
}
