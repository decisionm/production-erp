<?php

namespace Tests\Feature\TallySync\PerType;

use App\Models\User;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * THE ONE LIFECYCLE EVERY ERP-BUILT TALLY TYPE MUST WALK — over the real
 * endpoints, with the real actors (MASTER-PLAN P3-05).
 *
 * One concrete class per type (Receipt Note, Delivery Note, Sales invoice,
 * Journal, production batch voucher, production shift voucher) says how ITS
 * source is created through its own module's endpoint — the domain action
 * that enqueues — and how a second attempt at the same source is refused.
 * Everything downstream of the enqueue is the same contract for all six,
 * and lives here once so no type can quietly get a different one:
 *
 *   enqueue → listed with the right category → agent poll delivers (a real
 *   bearer token with AgentTokenService's abilities) → agent fail → status
 *   failed (+ fix advice where the resource has one) → retry (403 for a
 *   viewer, 200 for tally-sync.manage) → agent delivers again → ack →
 *   synced → retry 422 → fail refused (voucher.failure_refused) → dismiss
 *   422; a separate entry: fail → dismiss 200 → retry 422; a user without
 *   tally-sync.view: 403 on list/show/summary; the same source twice: ONE
 *   row. The events history is read back over GET /entries/{id} after each
 *   step, oldest first, with who did it.
 *
 * Actors switch inside one test, so the sanctum guard is forgotten before
 * each token request (it caches the resolved user for the app's lifetime —
 * see SyncSummaryTest) and re-seated for each staff request.
 */
abstract class PerTypeLifecycleTestCase extends TestCase
{
    use RefreshDatabase, SeedsSalesTallyMasterData;

    /** The installation's token, issued exactly as the Agent Tokens page issues it. */
    private ?string $agentToken = null;

    /** One person per (name, permissions) for the whole test — the actor labels on the history are asserted by name. */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The lifecycle is downstream of the approval chain and the shift
        // release gate; both are neutralised the way VoucherPostedOnceTest
        // does it (the production types' fixtures sit on a long-past date).
        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 0]);
    }

    // ---- what each type says about itself ---------------------------------

    /** Create the source through the module's own endpoint(s); return the ONE entry it enqueued. */
    abstract protected function enqueueViaDomain(): TallySyncEntry;

    /**
     * Try to enqueue the SAME source again the way a stale page or a
     * replayed request would, asserting the refusal's own shape; the base
     * then asserts exactly one row survived.
     */
    abstract protected function attemptDuplicateEnqueue(TallySyncEntry $entry): void;

    /** TallyTransactionCategory key the list must show. */
    abstract protected function expectedCategoryKey(): string;

    /** The raw tally_voucher_type label the agent dispatches on. */
    abstract protected function expectedVoucherType(): string;

    /** The document number staff search for (payload voucher_number). */
    abstract protected function expectedDocumentNumber(TallySyncEntry $entry): string;

    /** The error text Tally would return for this type — what the agent reports on fail. */
    abstract protected function tallyRejection(): string;

    /** The `fix.path` the resource derives from that error, or null when it recognises none. */
    abstract protected function expectedFixPath(): ?string;

    // ---- actors -----------------------------------------------------------

    protected function viewer(string $name = 'Vasanth Viewer'): User
    {
        return $this->staff($name, ['tally-sync.view']);
    }

    protected function manager(string $name = 'Priya Accounts'): User
    {
        return $this->staff($name, ['tally-sync.view', 'tally-sync.manage']);
    }

    /** A logged-in person holding none of the tally-sync permissions. */
    protected function nobody(): User
    {
        return User::factory()->create(['name' => 'Someone Else', 'is_active' => true]);
    }

    /** @param  list<string>  $permissions */
    protected function staff(string $name, array $permissions): User
    {
        $key = $name.'|'.implode(',', $permissions);

        return $this->people[$key] ??= tap(User::factory()->create(['name' => $name, 'is_active' => true]), function (User $user) use ($permissions) {
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
                $user->givePermissionTo($permission);
            }
        });
    }

    /** Subsequent requests come from this person, over the SPA's session auth. */
    protected function asUser(User $user): static
    {
        $this->withoutToken();
        Sanctum::actingAs($user);

        return $this;
    }

    /** Subsequent requests come from the factory PC, over its real bearer token. */
    protected function asAgent(): static
    {
        if ($this->agentToken === null) {
            $this->agentToken = app(AgentTokenService::class)->issueToken('factory-pc')['plainTextToken'];
        }

        // Forget the seated staff user, or the token is never resolved.
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->agentToken);
    }

    // ---- shared readers ---------------------------------------------------

    /** The row as the list shows it to a viewer, or null when the list omits it. */
    protected function listedRow(int $id): ?array
    {
        return collect($this->asUser($this->viewer())->getJson('/api/v1/tally-sync/entries?per_page=200')->assertOk()->json('data'))
            ->firstWhere('id', $id);
    }

    protected function show(int $id): TestResponse
    {
        return $this->asUser($this->viewer())->getJson("/api/v1/tally-sync/entries/{$id}")->assertOk();
    }

    /**
     * The history as GET /entries/{id} tells it — event names in order, and
     * (type, label) of who did each — asserted at every step so a step that
     * wrote the wrong row, or none, is caught where it happened.
     *
     * @param  list<string>  $events
     * @param  list<array{0: string, 1: ?string}>|null  $actors  [type, label] per row; null to skip
     */
    protected function assertHistory(int $id, array $events, ?array $actors = null): void
    {
        $history = $this->show($id)->json('data.history');

        $this->assertSame($events, array_column($history, 'event'), 'History must read in the order it happened');
        if ($actors !== null) {
            $this->assertSame(
                $actors,
                array_map(fn (array $row) => [$row['actor']['type'], $row['actor']['label']], $history),
                'Each history row must say who did it',
            );
        }
    }

    // ---- the contract ------------------------------------------------------

    public function test_the_lifecycle_walks_the_real_endpoints_in_order(): void
    {
        // The master data a GST-correct Sales voucher needs before the domain
        // act: SalesVoucherPayload refuses — and stages NOTHING, so there is no
        // lifecycle to walk — without the registration, the ledger roles, an
        // HSN and rate per item and a single resolvable godown. It sits here
        // rather than in setUp() because a concrete class makes its items,
        // customers and warehouses AFTER parent::setUp(); the trait completes
        // only what it finds blank and adds a Tally-linked warehouse only where
        // the type made none (two would make the godown ambiguous).
        $this->seedSalesTallyMasterData();

        $entry = $this->enqueueViaDomain();
        $id = $entry->id;
        $document = $this->expectedDocumentNumber($entry);

        // 1. Enqueued by the domain action: listed for a viewer with the right
        //    category, pending, never delivered — and one row of history.
        $row = $this->listedRow($id);
        $this->assertNotNull($row, 'The enqueued voucher must be on the list');
        $this->assertSame($this->expectedCategoryKey(), $row['category']['key']);
        $this->assertSame($this->expectedVoucherType(), $row['tally_voucher_type']);
        $this->assertSame($document, $row['document_number']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['delivered_at']);
        $this->assertSame(0, $row['attempts']);
        $this->assertArrayNotHasKey('history', $row, 'The list never carries history');
        $this->assertHistory($id, ['voucher.enqueued'], [['system', null]]);

        // 2. The agent's poll delivers it — as a FIRST delivery (delivered_at
        //    null on the wire, the agent's idempotency line) — and stamps it.
        $delivered = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertNotNull($delivered, 'The poll must hand the voucher out');
        $this->assertNull($delivered['delivered_at']);
        $this->assertSame($this->expectedVoucherType(), $delivered['tally_voucher_type']);
        $this->assertNotNull($entry->fresh()->delivered_at, 'The poll stamps the row on its way out');
        $this->assertHistory($id, ['voucher.enqueued', 'pending.delivered'], [['system', null], ['agent', 'factory-pc']]);

        // 3. Tally rejects it: failed, attempt 1, the error on the row — and
        //    the fix advice the resource derives from that exact error text.
        $failed = $this->asAgent()
            ->postJson("/api/v1/tally-sync/entries/{$id}/fail", ['error_message' => $this->tallyRejection()])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.attempts', 1)
            ->assertJsonPath('data.error_message', $this->tallyRejection())
            ->json('data');
        if ($this->expectedFixPath() === null) {
            $this->assertNull($failed['fix'], 'An unrecognised error carries no fix block rather than a wrong one');
        } else {
            $this->assertSame($this->expectedFixPath(), $failed['fix']['path']);
            $this->assertNotSame('', $failed['fix']['sentence']);
        }
        $this->assertSame('failed', $this->listedRow($id)['status']);
        $this->assertHistory($id, ['voucher.enqueued', 'pending.delivered', 'voucher.failed']);

        // 4. Retry: a viewer (no tally-sync.manage) is refused and writes no
        //    history; the accountant re-queues it — pending again, error
        //    cleared, delivered_at nulled (the one thing that re-arms the
        //    agent), the previous error kept on the resolution log.
        $this->asUser($this->viewer())->postJson("/api/v1/tally-sync/entries/{$id}/retry")->assertForbidden();
        $this->assertHistory($id, ['voucher.enqueued', 'pending.delivered', 'voucher.failed']);

        $retried = $this->asUser($this->manager('Priya Accounts'))
            ->postJson("/api/v1/tally-sync/entries/{$id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.error_message', null)
            ->assertJsonPath('data.delivered_at', null)
            ->assertJsonPath('data.attempts', 1)
            ->json('data');
        // The repair story records the error it retried past — readable to
        // this tally-sync-only manager unless the voucher's party is a
        // supplier, where Tally's words can name the vendor and the key is
        // withheld (FC-06, second half); the note and the actor stay.
        $this->assertSame('Payload regenerated from current mappings and re-queued.', $retried['resolution_log'][0]['note']);
        if (TallyTransactionCategory::from($this->expectedCategoryKey())->partyIsSupplier()) {
            $this->assertArrayNotHasKey('previous_error', $retried['resolution_log'][0]);
        } else {
            $this->assertSame($this->tallyRejection(), $retried['resolution_log'][0]['previous_error']);
        }
        $this->assertHistory(
            $id,
            ['voucher.enqueued', 'pending.delivered', 'voucher.failed', 'voucher.retried'],
            [['system', null], ['agent', 'factory-pc'], ['agent', 'factory-pc'], ['user', 'Priya Accounts']],
        );

        // 5. The next poll hands it out again as a first delivery.
        $again = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertNotNull($again, 'A retried voucher is offered again');
        $this->assertNull($again['delivered_at']);
        $this->assertHistory($id, ['voucher.enqueued', 'pending.delivered', 'voucher.failed', 'voucher.retried', 'pending.delivered']);

        // 6. The agent acknowledges: synced, synced_at set.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$id}/ack")
            ->assertOk()
            ->assertJsonPath('data.status', 'synced')
            ->assertJsonPath('data.error_message', null);
        $this->assertNotNull($entry->fresh()->synced_at);
        $this->assertHistory($id, ['voucher.enqueued', 'pending.delivered', 'voucher.failed', 'voucher.retried', 'pending.delivered', 'voucher.synced']);

        // 7. In the books now: retry is refused, naming the voucher to check.
        $refusedRetry = $this->asUser($this->manager())->postJson("/api/v1/tally-sync/entries/{$id}/retry")->assertStatus(422);
        $this->assertStringContainsString("already in Tally as {$document}", $refusedRetry->json('errors.entry.0'));
        $this->assertStringContainsString('check Tally before anything else', $refusedRetry->json('errors.entry.0'));

        // 8. A late failure report is refused, harmlessly: the row stays
        //    synced, no attempt is counted, and the refusal ITSELF is history.
        $this->asAgent()
            ->postJson("/api/v1/tally-sync/entries/{$id}/fail", ['error_message' => 'timeout of 15000ms exceeded'])
            ->assertOk()
            ->assertJsonPath('data.status', 'synced')
            ->assertJsonPath('data.error_message', null)
            ->assertJsonPath('data.attempts', 1);

        // 9. Dismiss is refused on a synced voucher.
        $refusedDismiss = $this->asUser($this->manager())->postJson("/api/v1/tally-sync/entries/{$id}/dismiss")->assertStatus(422);
        $this->assertStringContainsString("already in Tally as {$document}", $refusedDismiss->json('errors.entry.0'));

        // The whole story, in order, with who did each thing; the row itself
        // as the list shows it at the end.
        $this->assertHistory(
            $id,
            ['voucher.enqueued', 'pending.delivered', 'voucher.failed', 'voucher.retried', 'pending.delivered', 'voucher.synced', 'voucher.failure_refused'],
            [['system', null], ['agent', 'factory-pc'], ['agent', 'factory-pc'], ['user', 'Priya Accounts'], ['agent', 'factory-pc'], ['agent', 'factory-pc'], ['agent', 'factory-pc']],
        );
        $final = $this->listedRow($id);
        $this->assertSame('synced', $final['status']);
        $this->assertSame(1, $final['attempts']);
        $this->assertNull($final['error_message']);
        $this->assertNotNull($final['synced_at']);
        $this->assertSame($this->expectedCategoryKey(), $final['category']['key']);
    }

    public function test_a_failed_voucher_can_be_dismissed_and_a_dismissed_one_never_retried(): void
    {
        // Masters before the domain act, as in the lifecycle test above.
        $this->seedSalesTallyMasterData();

        $entry = $this->enqueueViaDomain();
        $id = $entry->id;
        $document = $this->expectedDocumentNumber($entry);

        $this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk();
        $this->asAgent()
            ->postJson("/api/v1/tally-sync/entries/{$id}/fail", ['error_message' => $this->tallyRejection()])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        // A viewer cannot write it off; the accountant can. The error stays
        // on the row (dismissal is not a repair), the fix advice goes (nobody
        // wants that repair), the act is on the record with the error it was
        // written off with.
        $this->asUser($this->viewer())->postJson("/api/v1/tally-sync/entries/{$id}/dismiss")->assertForbidden();

        $dismissed = $this->asUser($this->manager('Priya Accounts'))
            ->postJson("/api/v1/tally-sync/entries/{$id}/dismiss")
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed')
            ->assertJsonPath('data.fix', null)
            ->json('data');
        // The error stays on the row — readable to this tally-sync-only
        // manager for every type EXCEPT a supplier-party voucher, where
        // Tally's own words can name the vendor (FC-06, second half): there
        // it is withheld with a note, and finance reads it whole
        // (SupplierIdentityVisibilityTest).
        if (TallyTransactionCategory::from($this->expectedCategoryKey())->partyIsSupplier()) {
            $this->assertNull($dismissed['error_message']);
            $this->assertStringContainsString('FC-06', $dismissed['error_withheld']);
        } else {
            $this->assertSame($this->tallyRejection(), $dismissed['error_message']);
            $this->assertArrayNotHasKey('error_withheld', $dismissed);
        }
        $this->assertSame('Dismissed — will never be sent to Tally.', $dismissed['resolution_log'][0]['note']);
        $this->assertHistory(
            $id,
            ['voucher.enqueued', 'pending.delivered', 'voucher.failed', 'voucher.dismissed'],
            [['system', null], ['agent', 'factory-pc'], ['agent', 'factory-pc'], ['user', 'Priya Accounts']],
        );

        // Never resurrected: retry is refused in so many words, the row stays
        // dismissed, the history does not grow, and the agent is never
        // offered it again.
        $refused = $this->asUser($this->manager())->postJson("/api/v1/tally-sync/entries/{$id}/retry")->assertStatus(422);
        $this->assertStringContainsString("{$document} was dismissed — it is never to be sent to Tally.", $refused->json('errors.entry.0'));
        $this->assertSame('dismissed', $this->listedRow($id)['status']);
        $this->assertHistory($id, ['voucher.enqueued', 'pending.delivered', 'voucher.failed', 'voucher.dismissed']);

        $offered = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($offered->contains($id), 'A dismissed voucher is dead to the agent');
    }

    public function test_a_user_without_tally_sync_view_is_refused_on_list_show_and_summary(): void
    {
        // Masters before the domain act, as in the lifecycle test above.
        $this->seedSalesTallyMasterData();

        $entry = $this->enqueueViaDomain();

        $this->asUser($this->nobody());
        $this->getJson('/api/v1/tally-sync/entries')->assertForbidden();
        $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertForbidden();
        $this->getJson('/api/v1/tally-sync/summary')->assertForbidden();
        // Nor the writes, obviously — and a viewer can read but not write.
        $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/retry")->assertForbidden();

        $this->asUser($this->viewer());
        $this->getJson('/api/v1/tally-sync/entries')->assertOk();
        $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->assertJsonPath('data.category.key', $this->expectedCategoryKey());
        $this->getJson('/api/v1/tally-sync/summary')->assertOk();
        $this->postJson("/api/v1/tally-sync/entries/{$entry->id}/dismiss")->assertForbidden();

        // A refused request writes nothing: still only the enqueue.
        $this->assertHistory($entry->id, ['voucher.enqueued']);
    }

    public function test_enqueueing_the_same_source_twice_leaves_exactly_one_row(): void
    {
        // Masters before the domain act, as in the lifecycle test above.
        $this->seedSalesTallyMasterData();

        $entry = $this->enqueueViaDomain();

        $this->attemptDuplicateEnqueue($entry);

        $this->assertSame(1, TallySyncEntry::query()->count(), 'The same source must never mint a second voucher');
        $this->assertSame($entry->id, TallySyncEntry::query()->sole()->id);
        $this->assertHistory($entry->id, ['voucher.enqueued']);
    }
}
