<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalEntryLine;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /tally-sync/entries/{id} — one voucher with its full history
 * (TALLY-SYNC-CHAIN.md §3 "Endpoints"): the same resource the list returns
 * plus `history`, the entry's tally_sync_events oldest-first with who did
 * each thing. The list never carries `history`: a page of 200 vouchers
 * must not drag 200 timelines with it, and every other response of the
 * resource (retry, dismiss, release, the agent's poll) keeps its shape.
 */
class SyncEntryShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tally-sync.release_idle_minutes' => 0]);
    }

    public function test_show_returns_the_history_in_order_with_actor_labels(): void
    {
        $sync = app(TallySyncService::class);
        $agent = $this->agentUser('factory-pc');
        $accountant = $this->staff('Priya Accounts');

        // The live story of a repaired voucher: enqueued (the real path, so
        // the first row exists) → delivered → failed → retried by a person
        // → delivered again → synced.
        $voucher = $sync->enqueueJournalEntry($this->journal());
        $sync->pending($agent);
        $sync->markFailed($voucher->fresh(), 'Godown does not exist', $agent);
        $sync->retry($voucher->fresh(), $accountant->id, $accountant);
        $sync->pending($agent);
        $sync->markSynced($voucher->fresh(), $agent);

        $this->actingAs($accountant);
        $response = $this->getJson("/api/v1/tally-sync/entries/{$voucher->id}")->assertOk();

        // The entry itself, exactly as the list would show it …
        $response->assertJsonPath('data.id', $voucher->id)
            ->assertJsonPath('data.status', 'synced')
            ->assertJsonPath('data.document_number', 'JE-REF-9')
            ->assertJsonPath('data.category.key', 'journal');

        // … plus the timeline, oldest first, each row saying who.
        $history = $response->json('data.history');
        $this->assertSame(
            ['voucher.enqueued', 'pending.delivered', 'voucher.failed', 'voucher.retried', 'pending.delivered', 'voucher.synced'],
            array_column($history, 'event'),
        );
        $this->assertSame(
            [
                ['type' => 'system', 'label' => null],
                ['type' => 'agent', 'label' => 'factory-pc'],
                ['type' => 'agent', 'label' => 'factory-pc'],
                ['type' => 'user', 'label' => 'Priya Accounts'],
                ['type' => 'agent', 'label' => 'factory-pc'],
                ['type' => 'agent', 'label' => 'factory-pc'],
            ],
            array_map(fn (array $row) => ['type' => $row['actor']['type'], 'label' => $row['actor']['label']], $history),
        );

        // Each row is the event resource's shape, ids ascending.
        foreach ($history as $row) {
            $this->assertSame(['id', 'event', 'direction', 'occurred_at', 'actor', 'details', 'backfilled'], array_keys($row));
        }
        $ids = array_column($history, 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);

        // The first failure's error is still readable after the retry
        // overwrote it on the entry — the whole point of the history.
        $this->assertSame('Godown does not exist', $history[2]['details']['error_message']);
        $this->assertSame('Godown does not exist', $history[3]['details']['previous_error']);
        $this->assertNull($response->json('data.error_message'));
    }

    public function test_the_list_does_not_carry_history(): void
    {
        $sync = app(TallySyncService::class);
        $voucher = $this->voucher();
        $sync->pending($this->agentUser('factory-pc'));
        $this->assertGreaterThan(0, $voucher->events()->count(), 'There IS history to omit');

        $this->actingAs($this->staff());

        $row = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))
            ->firstWhere('id', $voucher->id);
        $this->assertArrayNotHasKey('history', $row);

        // Nor do the action responses: same resource, relation not loaded.
        $failed = $this->voucher(['payload' => ['voucher_number' => 'SPE-2'], 'status' => TallySyncStatus::Failed, 'error_message' => 'x', 'attempts' => 1]);
        $retried = $this->postJson("/api/v1/tally-sync/entries/{$failed->id}/retry")->assertOk()->json('data');
        $this->assertArrayNotHasKey('history', $retried);
    }

    public function test_show_of_an_untouched_entry_has_only_its_enqueue_and_an_unknown_id_is_404(): void
    {
        $this->actingAs($this->staff());

        $entry = app(TallySyncService::class)->enqueueJournalEntry($this->journal());

        $history = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.category.key', 'journal')
            ->json('data.history');
        $this->assertSame(['voucher.enqueued'], array_column($history, 'event'));

        $this->getJson('/api/v1/tally-sync/entries/999999')->assertNotFound();
    }

    public function test_show_needs_the_module_permission_like_the_list(): void
    {
        $voucher = $this->voucher();
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->getJson("/api/v1/tally-sync/entries/{$voucher->id}")->assertForbidden();
        $this->getJson('/api/v1/tally-sync/summary')->assertForbidden();
    }

    // ---- helpers ------------------------------------------------------------

    /** A journal for the REAL enqueue path — the same in-memory shape TransactionClassifierTest uses. */
    private function journal(): JournalEntry
    {
        $line = new JournalEntryLine(['debit' => '100.0000', 'credit' => '0.0000']);
        $line->setRelation('glAccount', new GLAccount(['code' => '4000', 'name' => 'Sales']));

        $journal = new JournalEntry(['entry_date' => '2026-08-02', 'reference' => 'JE-REF-9']);
        $journal->id = 4;
        $journal->exists = true;
        $journal->setRelation('lines', collect([$line]));

        return $journal;
    }

    /** A standalone queued voucher, no production entry behind it (SyncEventHistoryTest's). */
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

    /** The agent as the SERVICE sees it: a user carrying its real, named token. */
    private function agentUser(string $tokenName): User
    {
        $user = User::factory()->create(['name' => 'Tally Sync Agent', 'is_active' => true]);
        $issued = $user->createToken($tokenName, ['tally-sync:poll', 'tally-sync:report']);

        return $user->withAccessToken($issued->accessToken);
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
}
