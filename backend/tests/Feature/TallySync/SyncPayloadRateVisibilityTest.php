<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FC-06 on the sync queue: a Receipt Note's payload IS the GRN's purchase
 * rate per line and the bill total — Owner/Accounts data, gated on
 * finance.view/finance.manage and OMITTED (never nulled) for everyone else,
 * exactly the rule GoodsReceiptNoteLineResource / ProcurementRateVisibilityTest
 * pin on the procurement payloads. Without this gate, Phase 2's filters
 * turned the Tally Sync page into a purchase-rate archive searchable by
 * vendor and date for anyone holding tally-sync.view.
 *
 * THE ONE THING HERE THAT MUST NEVER GO RED THE WRONG WAY: the sync AGENT
 * has to receive the whole payload. TallySyncAgentController::pending()
 * serialises through the same resource, and the agent's Receipt Note
 * builder (tally-sync-agent/src/tally/voucherBuilders/receiptNote.ts)
 * reads line.rate, line.amount and total_amount to write the XML — strip
 * them from the agent and every Receipt Note reaches Tally with zero rates.
 * The agent is known by a REAL personal access token carrying the
 * abilities AgentTokenService issues (AgentIdentity), so that is what this
 * test authenticates with: never Sanctum::actingAs, whose mocked token
 * carries no ability and would make the agent look like a plain user.
 */
class SyncPayloadRateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every key name that carries a price anywhere in this codebase's
     * payloads: the sync payload's own (rate, amount, total_amount) plus
     * the procurement names (unit_price, unit_cost) so a builder that ever
     * copies one of those onto a payload trips this walk too.
     */
    private const RATE_KEYS = ['rate', 'amount', 'total_amount', 'unit_price', 'unit_cost'];

    /** The abilities AgentTokenService::issueToken() puts on every agent token (its private ABILITIES). */
    private const AGENT_ABILITIES = ['tally-sync:poll', 'tally-sync:report', 'tally-sync:masters'];

    // ---- (a) a tally-sync.view reader without finance sees no rate anywhere ----

    public function test_a_tally_sync_reader_without_finance_sees_no_rate_at_any_depth_on_a_receipt_note(): void
    {
        $grn = $this->enqueueGoodsReceipt();
        $this->assertSame('85.0000', $grn->payload['lines'][0]['rate'], 'The fixture carries the rate the gate must hide');
        $this->assertSame('8500.0000', $grn->payload['total_amount']);

        $this->actAsStaff(['tally-sync.view']);

        $list = $this->getJson('/api/v1/tally-sync/entries')->assertOk()->json();
        $this->assertSame([], $this->rateKeyPaths($list), 'the list leaked a rate key');

        $row = collect($list['data'])->firstWhere('id', $grn->id);
        $this->assertNotNull($row, 'The Receipt Note is on the list — the gate hides rates, not vouchers');

        // ABSENT, not null: a null rate would read as "this resin cost nothing".
        $this->assertArrayNotHasKey('rate', $row['payload']['lines'][0]);
        $this->assertArrayNotHasKey('amount', $row['payload']['lines'][0]);
        $this->assertArrayNotHasKey('total_amount', $row['payload']);

        // Everything that is not a price is untouched — the voucher is still
        // readable as a voucher.
        $this->assertSame('GRN-7', $row['payload']['voucher_number']);
        $this->assertSame('Reliance Industries', $row['payload']['party_ledger']);
        $this->assertSame('27AAACR1234A1Z5', $row['payload']['party_gstin'], 'A GSTIN is an identity, not a rate');
        $this->assertSame('PET Resin', $row['payload']['lines'][0]['item']);
        $this->assertSame('100.0000', $row['payload']['lines'][0]['quantity']);
        $this->assertSame('GRN-7', $row['document_number']);
        $this->assertSame(['first' => 'PET Resin', 'count' => 1], $row['item_summary']);

        // The show endpoint is the same resource under the same gate.
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json();
        $this->assertSame([], $this->rateKeyPaths($shown), 'show leaked a rate key');
    }

    // ---- (b) finance sees them ----------------------------------------------

    public function test_a_finance_reader_sees_the_rates_exactly_as_stored(): void
    {
        $grn = $this->enqueueGoodsReceipt();

        $this->actAsStaff(['tally-sync.view', 'finance.view']);

        $row = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))
            ->firstWhere('id', $grn->id);

        $this->assertSame('85.0000', $row['payload']['lines'][0]['rate']);
        $this->assertSame('8500.0000', $row['payload']['lines'][0]['amount']);
        $this->assertSame('8500.0000', $row['payload']['total_amount']);
        $this->assertSame($grn->fresh()->payload, $row['payload'], 'finance reads the stored payload, byte for byte');
    }

    // ---- (c) THE AGENT receives the whole payload — the voucher is built from it ----

    public function test_the_agent_polling_pending_receives_the_rates_intact_byte_for_byte(): void
    {
        $grn = $this->enqueueGoodsReceipt();
        [, $token] = $this->agent('factory-pc');

        $delivered = collect($this->withToken($token)->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))
            ->firstWhere('id', $grn->id);

        $this->assertNotNull($delivered, 'The Receipt Note was handed to the agent');
        // What receiptNote.ts reads to write <RATE> and <AMOUNT>: present,
        // and identical to what the ERP stored.
        $this->assertSame('85.0000', $delivered['payload']['lines'][0]['rate']);
        $this->assertSame('8500.0000', $delivered['payload']['lines'][0]['amount']);
        $this->assertSame('8500.0000', $delivered['payload']['total_amount']);
        $this->assertSame($grn->fresh()->payload, $delivered['payload'], 'the agent must receive the stored payload unchanged');
        $this->assertSame(json_encode($grn->fresh()->payload), json_encode($delivered['payload']));

        // The agent discards the ack's body (cloudApi.ts posts and reads
        // nothing back), but it is the same resource answering the same
        // token, and the gate must not answer differently per endpoint.
        $acked = $this->withToken($token)
            ->postJson("/api/v1/tally-sync/entries/{$grn->id}/ack")
            ->assertOk()
            ->assertJsonPath('data.status', 'synced')
            ->json('data');
        $this->assertSame('85.0000', $acked['payload']['lines'][0]['rate']);
        $this->assertSame('8500.0000', $acked['payload']['total_amount']);
    }

    // ---- (d) a token WITHOUT the agent's abilities is a person with a token ----

    public function test_a_personal_access_token_without_the_agent_abilities_gets_the_stripped_payload(): void
    {
        $grn = $this->enqueueGoodsReceipt();

        // CLAUDE.md #3: any external client may drive the API with a token.
        // A tally-sync.view user's own token — no finance, no agent ability
        // — is exactly that client, and is not the factory PC.
        $user = $this->staffUser(['tally-sync.view']);
        $token = $user->createToken('laptop', [])->plainTextToken;

        $list = $this->withToken($token)->getJson('/api/v1/tally-sync/entries')->assertOk()->json();
        $this->assertSame([], $this->rateKeyPaths($list), 'a plain PAT leaked a rate key');

        $row = collect($list['data'])->firstWhere('id', $grn->id);
        $this->assertArrayNotHasKey('rate', $row['payload']['lines'][0]);
        $this->assertArrayNotHasKey('total_amount', $row['payload']);
        $this->assertSame('GRN-7', $row['payload']['voucher_number']);
    }

    // ---- (e) a Sales invoice is gated by the same keys --------------------

    public function test_a_sales_invoice_is_stripped_of_the_same_keys_for_a_reader_without_finance(): void
    {
        $line = new InvoiceLine(['quantity' => '1000.0000', 'unit_price' => '4.5000']);
        $line->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));
        $invoice = $this->existing(new Invoice(['invoice_date' => '2026-08-01']), 5);
        $invoice->setRelation('lines', collect([$line]));
        $invoice->setRelation('customer', new Customer(['name' => 'Sri Aurobindo Beverages']));

        $entry = app(TallySyncService::class)->enqueueSalesInvoice($invoice);
        $this->assertSame('4.5000', $entry->payload['lines'][0]['rate']);
        $this->assertSame('4500.0000', $entry->payload['total_amount']);

        // The agent first (Sanctum::actingAs below answers for every request
        // after it, SyncSummaryTest): the invoice reaches the agent whole.
        [, $token] = $this->agent('factory-pc');
        $delivered = collect($this->withToken($token)->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))
            ->firstWhere('id', $entry->id);
        $this->assertSame($entry->fresh()->payload, $delivered['payload']);

        $this->actAsStaff(['tally-sync.view']);

        $list = $this->getJson('/api/v1/tally-sync/entries')->assertOk()->json();
        $this->assertSame([], $this->rateKeyPaths($list), 'the Sales invoice leaked a rate key');

        $row = collect($list['data'])->firstWhere('id', $entry->id);
        $this->assertArrayNotHasKey('rate', $row['payload']['lines'][0]);
        $this->assertArrayNotHasKey('amount', $row['payload']['lines'][0]);
        $this->assertArrayNotHasKey('total_amount', $row['payload']);
        $this->assertSame('INV-5', $row['payload']['voucher_number']);
        $this->assertSame('Sri Aurobindo Beverages', $row['payload']['party_ledger']);
        $this->assertSame('1000.0000', $row['payload']['lines'][0]['quantity']);
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * Every key path in the payload, at any depth, whose name is one of the
     * rate keys. A walk (not assertJsonMissingPath on a handful of guessed
     * paths) so a rate that reappears anywhere — a new nested block, a
     * renamed key — fails this test instead of slipping past it. Copied from
     * ProcurementRateVisibilityTest with the sync payload's keys added.
     *
     * @return array<int, string>
     */
    private function rateKeyPaths(mixed $node, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";
            if (in_array($key, self::RATE_KEYS, true)) {
                $found[] = $here;
            }
            $found = [...$found, ...$this->rateKeyPaths($value, $here)];
        }

        return $found;
    }

    /** @param  list<string>  $permissions */
    private function staffUser(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** @param  list<string>  $permissions */
    private function actAsStaff(array $permissions): User
    {
        $user = $this->staffUser($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * The agent as it really authenticates: a REAL personal access token
     * with the abilities AgentTokenService issues, on a user with no
     * permission at all — the agent's endpoints are gated by token ability,
     * not by tally-sync.view (routes/api.php).
     *
     * @return array{0: User, 1: string}
     */
    private function agent(string $tokenName): array
    {
        $user = User::factory()->create(['name' => 'Tally Sync Agent', 'is_active' => true]);
        $issued = $user->createToken($tokenName, self::AGENT_ABILITIES);

        return [$user, $issued->plainTextToken];
    }

    /** The GRN of OutboundVoucherTest, through the real event → listener → enqueue chain. */
    private function enqueueGoodsReceipt(): TallySyncEntry
    {
        $po = new PurchaseOrder;
        $po->setRelation('vendor', new Vendor(['name' => 'Reliance Industries', 'gstin' => '27AAACR1234A1Z5']));

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '85.0000']);
        $line->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin']));
        $line->setRelation('scheduleAllocations', collect());

        $grn = $this->existing(new GoodsReceiptNote(['received_date' => '2026-08-04']), 7);
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', new Warehouse(['name' => 'RM Store']));
        $grn->setRelation('purchaseOrder', $po);

        event(new GoodsReceiptNoteReceived($grn));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Receipt Note')->sole();
    }

    /** Mark an in-memory model as an existing (persisted) record without a DB write. */
    private function existing(object $model, int $id): object
    {
        $model->id = $id;
        $model->exists = true;

        return $model;
    }
}
