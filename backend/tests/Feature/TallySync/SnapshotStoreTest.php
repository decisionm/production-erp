<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\TallySync\Http\Requests\StoreTallySyncSnapshotRequest;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Models\TallySyncSnapshot;
use App\Modules\TallySync\Services\AgentTokenService;
use App\Modules\TallySync\Services\PayloadHash;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * POST /tally-sync/entries/{id}/snapshot — the agent's report of WHAT XML it
 * sent to Tally and WHAT Tally answered, after each post (Phase 4;
 * MASTER-PLAN P4-01..05). Fire-and-forget on the agent's side, so nothing
 * here may change what reaches Tally, an ack, a fail, a status, an attempt
 * count or a payload: a snapshot is an observation kept beside the entry.
 *
 *   201 with the snapshot's public shape · sha256 recomputed over the body
 *   and a mismatch refused 422 with nothing stored · a body-less report is
 *   accepted (the sha still lands) · 403 without tally-sync:report · a
 *   retried upload (same entry + sha + attempt within 60 s) returns the
 *   existing row 200 rather than doubling · payload_matches is judged
 *   against the payload the cloud holds NOW · older snapshots are pruned
 *   on write · the history event carries counts and the hash, never the
 *   message text or the XML.
 */
class SnapshotStoreTest extends TestCase
{
    use RefreshDatabase;

    private const XML = '<ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER><BODY><IMPORTDATA><REQUESTDATA><TALLYMESSAGE><VOUCHER VCHTYPE="Stock Journal"><VOUCHERTYPENAME>Stock Journal</VOUCHERTYPENAME><VOUCHERNUMBER>SJ-20260810-S1</VOUCHERNUMBER></VOUCHER></TALLYMESSAGE></REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';

    private ?string $agentToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tally-sync.release_idle_minutes' => 0]);
    }

    // ---- the happy path -------------------------------------------------------

    public function test_the_agent_stores_a_snapshot_and_reads_back_its_public_shape_with_201(): void
    {
        $entry = $this->stockJournal(['attempts' => 1]);

        $response = $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, [
            'attempt' => 1,
            'tally' => ['success' => true, 'created' => 1, 'errors' => 0, 'message' => null, 'raw' => '<RESPONSE><CREATED>1</CREATED><ERRORS>0</ERRORS></RESPONSE>'],
        ]))->assertCreated();

        $data = $response->json('data');
        $this->assertSame(
            ['id', 'attempt', 'created_at', 'agent_version', 'xml_sha256', 'xml_bytes', 'payload_matches', 'tally', 'xml', 'xml_withheld'],
            array_keys($data),
            'The public shape, in order',
        );
        $this->assertSame(1, $data['attempt']);
        $this->assertSame('0.3.8', $data['agent_version']);
        $this->assertSame(hash('sha256', self::XML), $data['xml_sha256']);
        $this->assertSame(strlen(self::XML), $data['xml_bytes']);
        $this->assertTrue($data['payload_matches']);
        $this->assertSame(
            ['success' => true, 'created' => 1, 'errors' => 0, 'message' => null, 'raw' => '<RESPONSE><CREATED>1</CREATED><ERRORS>0</ERRORS></RESPONSE>'],
            $data['tally'],
        );
        // The agent is inside the FC-06 predicate: it reads back exactly what it sent.
        $this->assertSame(self::XML, $data['xml']);
        $this->assertNull($data['xml_withheld']);
        $this->assertNotNull($data['created_at']);

        // Stored once, verbatim, as a 'post' row on this entry.
        $snapshot = TallySyncSnapshot::query()->sole();
        $this->assertSame($entry->id, $snapshot->tally_sync_entry_id);
        $this->assertSame('post', $snapshot->direction);
        $this->assertSame(self::XML, $snapshot->xml);
        $this->assertSame(hash('sha256', self::XML), $snapshot->xml_sha256);
        $this->assertSame(strlen(self::XML), $snapshot->xml_bytes);
        $this->assertTrue($snapshot->tally_success);
        $this->assertSame(1, $snapshot->tally_created);
        $this->assertSame(0, $snapshot->tally_errors);
        $this->assertSame(PayloadHash::of($entry->fresh()->payload), $snapshot->payload_hash);
        $this->assertTrue($snapshot->payload_matches);
        $this->assertNull($snapshot->updated_at ?? null, 'No updated_at: a snapshot is never edited');

        // NOTHING on the entry moved: a snapshot is an observation, not a report.
        $fresh = $entry->fresh();
        $this->assertSame(TallySyncStatus::Pending, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNull($fresh->synced_at);
        $this->assertNull($fresh->error_message);
    }

    public function test_the_attempt_defaults_to_the_entrys_own_count_when_the_agent_sends_none(): void
    {
        $entry = $this->stockJournal(['attempts' => 3]);

        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['attempt' => null]))
            ->assertCreated()
            ->assertJsonPath('data.attempt', 3);
    }

    // ---- the sha is recomputed --------------------------------------------------

    public function test_a_sha_that_does_not_match_the_body_is_refused_and_nothing_is_stored(): void
    {
        $entry = $this->stockJournal();

        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, [
            'xml_sha256' => str_repeat('a', 64),
        ]))->assertStatus(422)->assertJsonValidationErrors(['xml_sha256']);

        $this->assertSame(0, TallySyncSnapshot::query()->count());
        $this->assertSame(0, TallySyncEvent::query()->where('event', TallySyncEventKind::SnapshotStored->value)->count());
    }

    public function test_the_sha_is_matched_case_insensitively_and_stored_lowercase(): void
    {
        $entry = $this->stockJournal();

        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, [
            'xml_sha256' => strtoupper(hash('sha256', self::XML)),
        ]))->assertCreated()->assertJsonPath('data.xml_sha256', hash('sha256', self::XML));
    }

    public function test_the_request_is_validated(): void
    {
        $entry = $this->stockJournal();

        // No sha at all.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", ['xml' => self::XML])
            ->assertStatus(422)->assertJsonValidationErrors(['xml_sha256']);
        // Not hex / not 64.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", ['xml_sha256' => str_repeat('g', 64)])
            ->assertStatus(422)->assertJsonValidationErrors(['xml_sha256']);
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", ['xml_sha256' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors(['xml_sha256']);
        // A payload_hash that is not a sha256.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['payload_hash' => 'nope']))
            ->assertStatus(422)->assertJsonValidationErrors(['payload_hash']);
        // Tally counts must be counts.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['tally' => ['success' => 'yes', 'created' => -1, 'errors' => 'many']]))
            ->assertStatus(422)->assertJsonValidationErrors(['tally.success', 'tally.created', 'tally.errors']);
        // agent_version is a short label.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['agent_version' => str_repeat('9', 33)]))
            ->assertStatus(422)->assertJsonValidationErrors(['agent_version']);
        // The body is capped at 2 MB even when its sha is right — judged on
        // the request's own rules rather than over HTTP: a 2 MB body round-
        // tripped through the kernel is copied several times over, and the
        // full suite runs in one 128 MB process (the rule is what is pinned;
        // the HTTP path is exercised by every other case here).
        $huge = str_repeat('x', StoreTallySyncSnapshotRequest::XML_MAX_CHARS + 1);
        $validator = Validator::make(['xml' => $huge, 'xml_sha256' => hash('sha256', $huge)], (new StoreTallySyncSnapshotRequest)->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('xml', $validator->errors()->toArray());
        $this->assertSame(2 * 1024 * 1024, StoreTallySyncSnapshotRequest::XML_MAX_CHARS);
        unset($huge, $validator);
        // An unknown entry is 404.
        $this->asAgent()->postJson('/api/v1/tally-sync/entries/999999/snapshot', $this->body($entry))->assertNotFound();

        $this->assertSame(0, TallySyncSnapshot::query()->count());
    }

    // ---- no body ------------------------------------------------------------------

    public function test_a_report_without_a_body_is_accepted_and_keeps_the_sha_and_the_bytes_the_agent_measured(): void
    {
        $entry = $this->stockJournal();

        // The agent built XML over the 2 MB cap: it sends the sha and the size, not the body.
        $data = $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", [
            'xml' => null,
            'xml_sha256' => hash('sha256', 'whatever the agent built'),
            'xml_bytes' => 3_000_000,
            'attempt' => 1,
            'tally' => ['success' => false, 'created' => 0, 'errors' => 1, 'message' => 'Could not import', 'raw' => null],
            'agent_version' => '0.3.8',
        ])->assertCreated()->json('data');

        $this->assertNull($data['xml']);
        $this->assertNull($data['xml_withheld'], 'No body is not a withholding — there is nothing to withhold');
        $this->assertSame(hash('sha256', 'whatever the agent built'), $data['xml_sha256']);
        $this->assertSame(3_000_000, $data['xml_bytes']);
        $this->assertNull($data['payload_matches'], 'No hash echoed, no verdict');
        $this->assertSame('Could not import', $data['tally']['message']);

        // With a body, the server's own byte count wins over anything the agent claims.
        $withBody = $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['xml_bytes' => 5, 'attempt' => 2]))
            ->assertCreated()->json('data');
        $this->assertSame(strlen(self::XML), $withBody['xml_bytes']);
    }

    public function test_a_report_with_no_tally_answer_reads_back_tally_null(): void
    {
        $entry = $this->stockJournal();

        // The inconclusive-timeout path: XML was sent, nothing came back.
        $data = $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['tally' => null]))
            ->assertCreated()->json('data');

        $this->assertNull($data['tally']);
        $this->assertSame(self::XML, $data['xml']);
    }

    // ---- who may -------------------------------------------------------------------

    public function test_a_token_without_the_report_ability_is_refused_and_a_stranger_is_unauthenticated(): void
    {
        $entry = $this->stockJournal();

        // A poll-only agent token: it is the agent, but it may not report.
        $pollOnly = User::factory()->create(['is_active' => true])->createToken('poll-only', ['tally-sync:poll'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($pollOnly)->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry))->assertForbidden();

        // A person's token with no agent ability at all.
        $laptop = User::factory()->create(['is_active' => true])->createToken('laptop', [])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry))->assertForbidden();

        // Nobody — the bearer header withToken() left on this test case is
        // dropped, or the "stranger" would still be the laptop.
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry))->assertUnauthorized();

        $this->assertSame(0, TallySyncSnapshot::query()->count());
    }

    // ---- idempotency ------------------------------------------------------------------

    public function test_a_retried_upload_returns_the_existing_row_and_does_not_double(): void
    {
        $entry = $this->stockJournal(['attempts' => 1]);
        Carbon::setTestNow('2026-08-17 10:00:00');

        $first = $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['attempt' => 1]))
            ->assertCreated()->json('data.id');

        // The same report again 30 s later (the agent's HTTP client retried
        // after a lost response): the SAME row, 200, still one snapshot,
        // still one history event.
        Carbon::setTestNow('2026-08-17 10:00:30');
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['attempt' => 1]))
            ->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame(1, TallySyncSnapshot::query()->count());
        $this->assertSame(1, TallySyncEvent::query()->where('event', TallySyncEventKind::SnapshotStored->value)->count());

        // A different attempt with the same XML is a NEW post — a new row.
        $second = $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['attempt' => 2]))
            ->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);

        // The same attempt and XML, but past the window: the agent really
        // posted the same voucher again — a new row too.
        Carbon::setTestNow('2026-08-17 10:01:31');
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['attempt' => 1]))
            ->assertCreated();
        $this->assertSame(3, TallySyncSnapshot::query()->count());

        // Different XML on the same attempt within the window: a new row (a
        // different voucher body was sent, and that IS the record).
        $other = self::XML.'<!-- v2 -->';
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['attempt' => 1, 'xml' => $other, 'xml_sha256' => hash('sha256', $other)]))
            ->assertCreated();
        $this->assertSame(4, TallySyncSnapshot::query()->count());
    }

    // ---- payload_matches ---------------------------------------------------------------

    public function test_payload_matches_is_judged_against_the_payload_the_cloud_holds_now(): void
    {
        $sync = app(TallySyncService::class);
        $entry = $this->journal();

        // The agent polls: the row it receives carries the hash of the
        // payload as stored — that is what it echoes back.
        $delivered = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $entry->id);
        $this->assertNotNull($delivered);
        $hash = $delivered['payload_hash'];
        $this->assertSame(PayloadHash::of($entry->fresh()->payload), $hash);

        // Snapshot 1: the payload has not changed → matches.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['payload_hash' => $hash, 'attempt' => 0]))
            ->assertCreated()->assertJsonPath('data.payload_matches', true);

        // Tally rejects it, Accounts retries — retry() rewrites the payload
        // (the repair story lands in resolution_log even where no rebuilder
        // regenerates the lines) — so the XML the agent built no longer
        // came from the payload the cloud holds.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/fail", ['error_message' => 'Ledger does not exist : 4000 - Sales'])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->staff(['tally-sync.view', 'tally-sync.manage']));
        $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/retry")->assertOk();
        $this->assertNotSame($hash, PayloadHash::of($entry->fresh()->payload), 'The retry changed the payload');

        // Snapshot 2, echoing the OLD hash: does not match. Snapshot 3 with
        // no hash: no verdict.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['payload_hash' => $hash, 'attempt' => 1]))
            ->assertCreated()->assertJsonPath('data.payload_matches', false);
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['payload_hash' => null, 'attempt' => 2]))
            ->assertCreated()->assertJsonPath('data.payload_matches', null);

        // And the fresh hash matches again.
        $fresh = PayloadHash::of($entry->fresh()->payload);
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, ['payload_hash' => $fresh, 'attempt' => 3]))
            ->assertCreated()->assertJsonPath('data.payload_matches', true);
    }

    // ---- retention -----------------------------------------------------------------------

    public function test_snapshots_older_than_the_retention_are_pruned_on_write_for_any_entry(): void
    {
        config(['tally-sync.snapshot_retention_days' => 90]);
        $old = $this->stockJournal(['payload' => ['voucher_number' => 'SJ-OLD']]);
        $entry = $this->stockJournal(['payload' => ['voucher_number' => 'SJ-NEW']]);

        Carbon::setTestNow('2026-08-17 10:00:00');
        $expired = $this->rawSnapshot($old, now()->subDays(91));
        $expiredToo = $this->rawSnapshot($entry, now()->subDays(90)->subMinute());
        $kept = $this->rawSnapshot($old, now()->subDays(89));
        $keptToo = $this->rawSnapshot($entry, now()->subDays(1));

        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry))->assertCreated();

        $ids = TallySyncSnapshot::query()->pluck('id')->all();
        $this->assertNotContains($expired->id, $ids, 'A 91-day-old snapshot on ANOTHER entry is pruned');
        $this->assertNotContains($expiredToo->id, $ids);
        $this->assertContains($kept->id, $ids);
        $this->assertContains($keptToo->id, $ids);
        $this->assertSame(3, TallySyncSnapshot::query()->count());
    }

    public function test_a_retention_of_zero_or_less_never_prunes(): void
    {
        config(['tally-sync.snapshot_retention_days' => 0]);
        $entry = $this->stockJournal();
        Carbon::setTestNow('2026-08-17 10:00:00');
        $ancient = $this->rawSnapshot($entry, now()->subYears(3));

        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry))->assertCreated();

        $this->assertTrue(TallySyncSnapshot::query()->whereKey($ancient->id)->exists());
        $this->assertSame(2, TallySyncSnapshot::query()->count());
    }

    // ---- the history row -----------------------------------------------------------------

    public function test_the_history_records_the_snapshot_with_counts_and_the_hash_and_never_the_message_or_the_xml(): void
    {
        $entry = $this->stockJournal(['attempts' => 2]);

        $id = $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry, [
            'attempt' => 2,
            'tally' => ['success' => false, 'created' => 0, 'errors' => 1, 'message' => 'Ledger does not exist : Secret Vendor Pvt Ltd', 'raw' => '<RESPONSE>Secret Vendor Pvt Ltd</RESPONSE>'],
        ]))->assertCreated()->json('data.id');

        $event = TallySyncEvent::query()->where('event', TallySyncEventKind::SnapshotStored->value)->sole();
        $this->assertSame($entry->id, $event->tally_sync_entry_id);
        $this->assertSame(TallySyncEvent::DIRECTION_ERP_TO_TALLY, $event->direction);
        $this->assertSame(TallySyncEvent::ACTOR_AGENT, $event->actor_type);
        $this->assertSame('factory-pc', $event->actor_label);
        $this->assertSame(
            [
                'snapshot_id' => $id,
                'attempt' => 2,
                'xml_sha256' => hash('sha256', self::XML),
                'xml_bytes' => strlen(self::XML),
                'tally_success' => false,
                'agent_version' => '0.3.8',
                'payload_matches' => true,
            ],
            $event->details,
        );
        $encoded = json_encode($event->details);
        $this->assertStringNotContainsString('Secret Vendor', $encoded);
        $this->assertStringNotContainsString('<ENVELOPE', $encoded);

        // It reads back on the entry's history and timeline, oldest first,
        // with the agent as its actor — and nothing there quotes Tally's
        // words either.
        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk();
        $history = $shown->json('data.history');
        $this->assertSame('snapshot.stored', end($history)['event']);
        $this->assertSame(['type' => 'agent', 'id' => $event->actor_id, 'label' => 'factory-pc'], end($history)['actor']);
        $this->assertSame(1, count(array_filter($shown->json('data.timeline'), fn ($row) => $row['event'] === 'snapshot.stored')));
    }

    public function test_the_stored_snapshot_is_never_edited(): void
    {
        $entry = $this->stockJournal();
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$entry->id}/snapshot", $this->body($entry))->assertCreated();

        $snapshot = TallySyncSnapshot::query()->sole();
        $this->expectException(\LogicException::class);
        $snapshot->update(['tally_success' => false]);
    }

    // ---- helpers ------------------------------------------------------------------------------

    /**
     * The request the agent sends after a post: the XML it built, its
     * sha256, and Tally's answer — with any key overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(TallySyncEntry $entry, array $overrides = []): array
    {
        return array_merge([
            'xml' => self::XML,
            'xml_sha256' => hash('sha256', self::XML),
            'attempt' => $entry->attempts,
            'tally' => ['success' => true, 'created' => 1, 'errors' => 0, 'message' => null, 'raw' => null],
            'agent_version' => '0.3.8',
            'payload_hash' => PayloadHash::of($entry->fresh()->payload),
        ], $overrides);
    }

    /** A snapshot row written directly, dated as given — a fixture for the retention rule. */
    private function rawSnapshot(TallySyncEntry $entry, Carbon $createdAt): TallySyncSnapshot
    {
        return TallySyncSnapshot::query()->create([
            'tally_sync_entry_id' => $entry->id,
            'attempt' => 1,
            'direction' => 'post',
            'xml_sha256' => hash('sha256', 'old '.$createdAt->toIso8601String()),
            'xml_bytes' => 10,
            'xml' => '<ENVELOPE/>',
            'created_at' => $createdAt,
        ]);
    }

    /** A per-batch production voucher (Stock Journal on the wire) — rate-free and party-free by construction. */
    private function stockJournal(array $attributes = []): TallySyncEntry
    {
        return TallySyncEntry::create(array_merge([
            'syncable_type' => (new ShiftProductionEntry)->getMorphClass(),
            'syncable_id' => 1,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => ['voucher_type' => 'Stock Journal', 'voucher_number' => 'SJ-20260810-S1', 'voucher_date' => '2026-08-10'],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ], $attributes));
    }

    /** A Journal on the queue, pending and undelivered, whose retry() rewrites the payload. */
    private function journal(): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => (new JournalEntry)->getMorphClass(),
            'syncable_id' => 4,
            'tally_voucher_type' => 'Journal',
            'payload' => [
                'voucher_type' => 'Journal',
                'voucher_number' => 'JE-REF-9',
                'voucher_date' => '2026-08-02',
                'lines' => [['ledger' => '4000 - Sales', 'debit' => '100.0000', 'credit' => '0.0000', 'memo' => null]],
            ],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** Subsequent requests come from the factory PC, over its real bearer token (PerTypeLifecycleTestCase). */
    private function asAgent(): static
    {
        if ($this->agentToken === null) {
            $this->agentToken = app(AgentTokenService::class)->issueToken('factory-pc')['plainTextToken'];
        }

        $this->app['auth']->forgetGuards();

        return $this->withToken($this->agentToken);
    }
}
