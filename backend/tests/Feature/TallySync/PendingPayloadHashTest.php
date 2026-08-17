<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\AgentTokenService;
use App\Modules\TallySync\Services\PayloadHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `payload_hash` on the AGENT's /pending rows (Phase 4): the sha256 of the
 * payload as stored (PayloadHash::of), stamped so the agent can echo it back
 * on its post-Tally snapshot and the cloud can judge `payload_matches`
 * against the payload it holds THEN. PINNED HERE:
 *
 *   - every row the agent polls carries it, and it IS PayloadHash::of the
 *     stored payload — the payload the agent receives byte-identical;
 *   - it is stable across re-polls and changes when the payload changes
 *     (a retry rewrites resolution_log);
 *   - it is emitted to the AGENT ONLY, by its real token: a tally-sync.view
 *     reader, a finance reader, a staff session exercising /pending from
 *     the browser, and a person's token without the agent's abilities do
 *     NOT see it — there is nothing for them to echo, and the same key on
 *     the list would invite a client to compare hashes over a payload the
 *     resource strips per reader;
 *   - PayloadHash::of is exactly sha256(json_encode(payload,
 *     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) — the agent never
 *     recomputes it, but the algorithm is the contract between the stamp
 *     and the verdict, and it must not drift.
 */
class PendingPayloadHashTest extends TestCase
{
    use RefreshDatabase;

    private ?string $agentToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tally-sync.release_idle_minutes' => 0]);
    }

    public function test_every_row_the_agent_polls_carries_the_hash_of_the_payload_it_receives(): void
    {
        $journal = $this->journal();
        $batch = $this->batchVoucher();
        $grn = $this->receiptNote();

        $rows = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->keyBy('id');

        foreach ([$journal, $batch, $grn] as $entry) {
            $row = $rows->get($entry->id);
            $this->assertNotNull($row, "entry #{$entry->id} was handed to the agent");
            $this->assertArrayHasKey('payload_hash', $row);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['payload_hash']);
            $this->assertSame(PayloadHash::of($entry->fresh()->payload), $row['payload_hash']);
            // The hash is of the payload as delivered — the agent gets the
            // stored payload whole (SupplierIdentityVisibilityTest), so the
            // hash it echoes describes the document it built from.
            $this->assertSame($entry->fresh()->payload, $row['payload']);
            $this->assertSame(PayloadHash::of($row['payload']), $row['payload_hash']);
        }
    }

    public function test_the_hash_is_stable_across_re_polls_and_changes_when_the_payload_changes(): void
    {
        $journal = $this->journal();

        $first = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->json('data'))->firstWhere('id', $journal->id)['payload_hash'];
        $again = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->json('data'))->firstWhere('id', $journal->id)['payload_hash'];
        $this->assertSame($first, $again, 'a re-poll of an unchanged payload carries the same hash');

        // Tally rejects it; Accounts retries — retry() rewrites the payload
        // (resolution_log gains the repair) — and the next hand-out carries
        // a new hash.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$journal->id}/fail", ['error_message' => 'Ledger does not exist : 4000 - Sales'])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->staff(['tally-sync.view', 'tally-sync.manage']));
        $this->postJson("/api/v1/tally-sync/entries/{$journal->id}/retry")->assertOk();

        $after = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->json('data'))->firstWhere('id', $journal->id)['payload_hash'];
        $this->assertNotSame($first, $after);
        $this->assertSame(PayloadHash::of($journal->fresh()->payload), $after);
    }

    public function test_only_the_agent_by_its_real_token_receives_the_hash(): void
    {
        $journal = $this->journal();
        $grn = $this->receiptNote();

        // A tally-sync.view reader, on the list and on show.
        Sanctum::actingAs($this->staff(['tally-sync.view']));
        $list = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'));
        $this->assertArrayNotHasKey('payload_hash', $list->firstWhere('id', $journal->id));
        $this->assertArrayNotHasKey('payload_hash', $list->firstWhere('id', $grn->id));
        $this->assertArrayNotHasKey('payload_hash', $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json('data'));

        // Finance reads the payload whole — and still gets no hash: the key
        // is the agent's echo, not a reader's fact.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->staff(['tally-sync.view', 'finance.view']));
        $this->assertArrayNotHasKey('payload_hash', collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))->firstWhere('id', $grn->id));
        $this->assertArrayNotHasKey('payload_hash', $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json('data'));

        // A staff session exercising /pending from the browser — a REAL
        // web session (actingAs on the web guard, which Sanctum's guard
        // turns into a TransientToken; Sanctum::actingAs would mock a
        // PersonalAccessToken instead, which is not what a browser carries):
        // the transient token answers tokenCan() TRUE, but a session is not
        // the agent (AgentIdentity) — since Phase 4 the poll itself is
        // refused, so a hash cannot leak this way either.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff(['tally-sync.view', 'tally-sync.manage']), 'web');
        $this->getJson('/api/v1/tally-sync/pending')->assertForbidden();

        // A person's token without the agent's abilities is a person with
        // a token (CLAUDE.md #3), not the factory PC.
        $this->app['auth']->forgetGuards();
        $laptop = $this->staff(['tally-sync.view'])->createToken('laptop', [])->plainTextToken;
        $this->assertArrayNotHasKey('payload_hash', collect($this->withToken($laptop)->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))->firstWhere('id', $journal->id));

        // The agent, by its real token — the hash is there.
        $this->assertArrayHasKey('payload_hash', collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $journal->id));
    }

    public function test_the_hash_is_the_sha256_of_the_canonical_json_and_does_not_drift(): void
    {
        $payload = [
            'voucher_type' => 'Delivery Note',
            'voucher_number' => 'DN-3',
            'voucher_date' => '2026-08-03',
            'party_ledger' => 'Sri Aurobindo / Beverages (₹)',
            'lines' => [['item' => '500ml PET Bottle', 'quantity' => '2000.0000']],
            'memo' => null,
        ];

        // The algorithm, spelled out: unescaped slashes and unicode, keys in
        // stored order. Both sides of the verdict use this one function.
        $this->assertSame(
            hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            PayloadHash::of($payload),
        );
        $this->assertSame(64, strlen(PayloadHash::of($payload)));
        $this->assertSame(PayloadHash::of($payload), PayloadHash::of($payload), 'deterministic');

        // Order matters (it is the stored order, not a canonical sort), and
        // so does every byte: the verdict is "same payload?", not "same
        // meaning?".
        $this->assertNotSame(PayloadHash::of($payload), PayloadHash::of(array_reverse($payload, true)));
        $this->assertNotSame(PayloadHash::of($payload), PayloadHash::of(['memo' => 'x'] + $payload));

        // A known vector, so a change to the flags or the algorithm fails
        // here rather than silently flipping every payload_matches to false.
        $this->assertSame(
            hash('sha256', '{"a":"x/y","b":"₹","c":[1,2]}'),
            PayloadHash::of(['a' => 'x/y', 'b' => '₹', 'c' => [1, 2]]),
        );
    }

    // ---- fixtures -----------------------------------------------------------------------

    private function journal(): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => (new JournalEntry)->getMorphClass(),
            'syncable_id' => 4,
            'tally_voucher_type' => 'Journal',
            'payload' => [
                'voucher_type' => 'Journal', 'voucher_number' => 'JE-REF-9', 'voucher_date' => '2026-08-02',
                'lines' => [['ledger' => '4000 - Sales', 'debit' => '100.0000', 'credit' => '0.0000', 'memo' => null]],
            ],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);
    }

    private function batchVoucher(): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => (new ShiftProductionEntry)->getMorphClass(),
            'syncable_id' => 21,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => [
                'voucher_type' => 'Manufacturing Journal', 'voucher_number' => 'SPE-21', 'voucher_date' => '2026-08-10',
                'produced' => [['item' => '500ml PET Bottle', 'quantity' => '2000.0000']],
                'consumed' => [['item' => 'Relpet', 'quantity' => '50.0000', 'godown' => 'RM Store']],
            ],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);
    }

    private function receiptNote(): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => (new GoodsReceiptNote)->getMorphClass(),
            'syncable_id' => 7,
            'tally_voucher_type' => 'Receipt Note',
            'payload' => [
                'voucher_type' => 'Receipt Note', 'voucher_number' => 'GRN-7', 'voucher_date' => '2026-08-04',
                'party_ledger' => 'Reliance Industries', 'party_gstin' => '27AAACR1234A1Z5', 'godown' => 'RM Store',
                'lines' => [['item' => 'PET Resin', 'quantity' => '100.0000', 'rate' => '85.0000', 'amount' => '8500.0000']],
                'total_amount' => '8500.0000',
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
