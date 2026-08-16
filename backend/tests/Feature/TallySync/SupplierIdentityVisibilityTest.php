<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalEntryLine;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FC-06, THE SECOND HALF, on the sync queue: "Purchase rates and supplier
 * details are Owner/Accounts only. Floor and sales logins never see what a
 * material cost or WHO SUPPLIED IT." A Receipt Note's party IS the vendor,
 * and Phase 3 put that name on three new surfaces a plain tally-sync.view
 * reader could see — the summary headline, the mapping surface's party
 * row, the drawer's ERP-source Party row (root `party`) — while
 * `party_gstin` (the vendor's GSTIN) and `party_ledger` rode the raw
 * payload past the rate strip.
 *
 * ONE predicate decides (AgentIdentity::mayReadPurchaseDetails — the SAME
 * one that gates the rates: finance.view/finance.manage, or the sync agent
 * by its real token), and this suite pins every surface against it:
 *
 *   tally-sync-only reader on a Receipt Note → no vendor name in the headline;
 *       root party null (list AND show); mappings.party {name: null, state:
 *       withheld, note}; no party_ledger / party_gstin anywhere in the payload;
 *   finance reader                          → all of it, exactly as stored;
 *   THE AGENT on /pending                   → party_ledger + party_gstin
 *       byte-identical (receiptNote.ts writes PARTYLEDGERNAME from it);
 *   a Delivery Note's CUSTOMER              → visible to the tally-sync-only
 *       reader (a customer is not FC-06);
 *   a Journal's debit / credit              → money on the same resource,
 *       stripped by the same one rule for the same reader; whole for
 *       finance and the agent.
 */
class SupplierIdentityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** The abilities AgentTokenService::issueToken() puts on every agent token (its private ABILITIES). */
    private const AGENT_ABILITIES = ['tally-sync:poll', 'tally-sync:report', 'tally-sync:masters'];

    private const VENDOR = 'Reliance Industries';

    private const VENDOR_GSTIN = '27AAACR1234A1Z5';

    // ---- (a) a tally-sync-only reader on a Receipt Note sees no supplier anywhere ----

    public function test_a_tally_sync_only_reader_never_sees_who_supplied_a_receipt_note(): void
    {
        $grn = $this->enqueueGoodsReceipt();
        $this->assertSame(self::VENDOR, $grn->payload['party_ledger'], 'The fixture carries the vendor the gate must hide');
        $this->assertSame(self::VENDOR_GSTIN, $grn->payload['party_gstin']);

        $this->actAsStaff(['tally-sync.view', 'tally-sync.manage']);

        // The list: root party null, supplier keys ABSENT from the payload
        // (not nulled — a null party would read as "no party").
        $list = $this->getJson('/api/v1/tally-sync/entries')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $grn->id);
        $this->assertNotNull($row, 'The Receipt Note is on the list — the gate hides the supplier, not the voucher');
        $this->assertNull($row['party']);
        $this->assertArrayNotHasKey('party_ledger', $row['payload']);
        $this->assertArrayNotHasKey('party_gstin', $row['payload']);
        $this->assertSame('GRN-7', $row['payload']['voucher_number'], 'the voucher is still readable as a voucher');
        $this->assertSame('RM Store', $row['payload']['godown']);
        $this->assertStringNotContainsString(self::VENDOR, $list->getContent(), 'the vendor name leaked somewhere on the list');
        $this->assertStringNotContainsString(self::VENDOR_GSTIN, $list->getContent(), 'the vendor GSTIN leaked somewhere on the list');

        // Show: the same resource, plus the Phase 3 surfaces — headline
        // without the party segment, party row withheld and explained.
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk();
        $data = $shown->json('data');
        $this->assertNull($data['party']);
        $this->assertSame('Receipt Note GRN-7 · 04-Aug-2026 · 1 item × 100 · waiting for agent', $data['summary']['headline']);
        $this->assertSame(
            ['name' => null, 'state' => 'withheld', 'note' => 'The supplier on this voucher is withheld: supplier identity is Owner/Accounts only (FC-06).'],
            $data['mappings']['party'],
        );
        $this->assertArrayNotHasKey('party_ledger', $data['payload']);
        $this->assertArrayNotHasKey('party_gstin', $data['payload']);
        // The mapping states of the LINES are still whole — the gate is on
        // the supplier and the rates, not on how a name resolved.
        $this->assertSame('PET Resin', $data['mappings']['lines'][0]['item']['name']);
        $this->assertStringNotContainsString(self::VENDOR, $shown->getContent(), 'the vendor name leaked somewhere on show');
        $this->assertStringNotContainsString(self::VENDOR_GSTIN, $shown->getContent(), 'the vendor GSTIN leaked somewhere on show');
    }

    // ---- (b) finance sees all of it -----------------------------------------

    public function test_a_finance_reader_sees_the_supplier_on_every_surface_exactly_as_stored(): void
    {
        $grn = $this->enqueueGoodsReceipt();

        $this->actAsStaff(['tally-sync.view', 'finance.view']);

        $row = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))->firstWhere('id', $grn->id);
        $this->assertSame(self::VENDOR, $row['party']);
        $this->assertSame($grn->fresh()->payload, $row['payload'], 'finance reads the stored payload, byte for byte');

        $data = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json('data');
        $this->assertSame(self::VENDOR, $data['party']);
        $this->assertSame('Receipt Note GRN-7 · Reliance Industries · 04-Aug-2026 · 1 item × 100 · waiting for agent', $data['summary']['headline']);
        $this->assertSame(self::VENDOR, $data['mappings']['party']['name']);
        $this->assertSame('name_only', $data['mappings']['party']['state']);
        $this->assertSame(self::VENDOR, $data['payload']['party_ledger']);
        $this->assertSame(self::VENDOR_GSTIN, $data['payload']['party_gstin']);

        // finance.manage is the same half of the same rule.
        $this->actAsStaff(['tally-sync.view', 'finance.manage']);
        $this->assertSame(self::VENDOR, $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json('data.party'));
    }

    // ---- (c) THE AGENT receives the supplier whole — the voucher names it ----

    public function test_the_agent_polling_pending_receives_the_supplier_byte_identical(): void
    {
        $grn = $this->enqueueGoodsReceipt();
        [, $token] = $this->agent('factory-pc');

        $delivered = collect($this->withToken($token)->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))
            ->firstWhere('id', $grn->id);

        $this->assertNotNull($delivered, 'The Receipt Note was handed to the agent');
        // What receiptNote.ts writes into <PARTYLEDGERNAME>: present, and
        // identical to what the ERP stored.
        $this->assertSame(self::VENDOR, $delivered['payload']['party_ledger']);
        $this->assertSame(self::VENDOR_GSTIN, $delivered['payload']['party_gstin']);
        $this->assertSame(self::VENDOR, $delivered['party']);
        $this->assertSame($grn->fresh()->payload, $delivered['payload'], 'the agent must receive the stored payload unchanged');
        $this->assertSame(json_encode($grn->fresh()->payload), json_encode($delivered['payload']));
    }

    // ---- (d) a customer is not FC-06 ----------------------------------------

    public function test_a_delivery_notes_customer_stays_visible_to_a_tally_sync_only_reader(): void
    {
        $delivery = $this->enqueueDelivery();
        $this->assertFalse(TallyTransactionCategory::DeliveryNote->partyIsSupplier());
        $this->assertFalse(TallyTransactionCategory::SalesInvoice->partyIsSupplier());
        $this->assertTrue(TallyTransactionCategory::ReceiptNote->partyIsSupplier());

        $this->actAsStaff(['tally-sync.view']);

        $row = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))->firstWhere('id', $delivery->id);
        $this->assertSame('Sri Aurobindo Beverages', $row['party']);
        $this->assertSame('Sri Aurobindo Beverages', $row['payload']['party_ledger']);
        $this->assertArrayHasKey('party_gstin', $row['payload'], 'a customer\'s GSTIN is not a supplier detail');

        $data = $this->getJson("/api/v1/tally-sync/entries/{$delivery->id}")->assertOk()->json('data');
        $this->assertSame('Delivery Note DN-3 · Sri Aurobindo Beverages · 03-Aug-2026 · 1 item × 2000 · waiting for agent', $data['summary']['headline']);
        $this->assertSame('Sri Aurobindo Beverages', $data['mappings']['party']['name']);
        $this->assertSame('name_only', $data['mappings']['party']['state']);
    }

    // ---- (e) a Journal's sides are money on the same resource ---------------

    public function test_a_journals_debit_and_credit_are_stripped_for_a_tally_sync_only_reader_and_whole_for_finance_and_the_agent(): void
    {
        $journal = app(TallySyncService::class)->enqueueJournalEntry($this->journal());
        $this->assertSame('100.0000', $journal->payload['lines'][0]['debit'], 'The fixture carries the figures the gate must hide');

        // The agent first (Sanctum::actingAs below answers for every request
        // after it): the journal reaches the agent whole.
        [, $token] = $this->agent('factory-pc');
        $delivered = collect($this->withToken($token)->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))
            ->firstWhere('id', $journal->id);
        $this->assertSame($journal->fresh()->payload, $delivered['payload']);

        $this->actAsStaff(['tally-sync.view']);
        $row = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))->firstWhere('id', $journal->id);
        $this->assertSame(
            [['ledger' => '4000 - Sales', 'memo' => null], ['ledger' => '1200 - Debtors', 'memo' => null]],
            $row['payload']['lines'],
            'debit and credit are OMITTED, not nulled, and the rest of the line is untouched',
        );

        $this->actAsStaff(['tally-sync.view', 'finance.view']);
        $finance = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))->firstWhere('id', $journal->id);
        $this->assertSame($journal->fresh()->payload, $finance['payload']);
    }

    // ---- (f) the predicate itself --------------------------------------------

    /**
     * The side channel a resource-level gate cannot close on its own: the
     * list's free-text `q` LIKE-matched payload->party_ledger for every
     * reader, so a tally-sync-only user could ask "?q=Reliance" and learn,
     * from the row count alone, which Receipt Notes that vendor supplied —
     * a yes/no oracle on FC-06's second half. For such a reader the party
     * clause no longer applies to supplier-party categories; a customer on
     * a Delivery Note stays searchable, and finance and the agent are
     * unchanged.
     */
    public function test_the_search_box_is_not_an_oracle_on_who_supplied_a_receipt_note(): void
    {
        $grn = $this->enqueueGoodsReceipt();
        $delivery = $this->enqueueDelivery();

        // Vendor name → nothing for a tally-sync-only reader; the GRN is
        // still findable by its own number, so the filter is not simply off.
        $this->actAsStaff(['tally-sync.view', 'tally-sync.manage']);
        $this->getJson('/api/v1/tally-sync/entries?q=Reliance')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tally-sync/entries?q=GRN-7')->assertOk()->assertJsonPath('data.0.id', $grn->id);
        // A customer is not FC-06: the Delivery Note still matches by name.
        $this->getJson('/api/v1/tally-sync/entries?q=Aurobindo')->assertOk()->assertJsonPath('data.0.id', $delivery->id);
        // And the summary counts follow the same rule.
        $this->getJson('/api/v1/tally-sync/summary?q=Reliance')->assertOk()->assertJsonPath('data.all_time.total', 0);

        $this->app['auth']->forgetGuards();

        // Finance still searches by vendor.
        $this->actAsStaff(['tally-sync.view', 'finance.view']);
        $this->getJson('/api/v1/tally-sync/entries?q=Reliance')->assertOk()->assertJsonPath('data.0.id', $grn->id);
        $this->getJson('/api/v1/tally-sync/summary?q=Reliance')->assertOk()->assertJsonPath('data.all_time.total', 1);
    }

    public function test_the_one_predicate_answers_for_finance_and_the_agent_and_for_nobody_else(): void
    {
        $this->assertFalse(AgentIdentity::mayReadPurchaseDetails(null));
        $this->assertFalse(AgentIdentity::mayReadPurchaseDetails($this->staffUser(['tally-sync.view', 'tally-sync.manage'])));
        $this->assertTrue(AgentIdentity::mayReadPurchaseDetails($this->staffUser(['finance.view'])));
        $this->assertTrue(AgentIdentity::mayReadPurchaseDetails($this->staffUser(['finance.manage'])));

        // The agent, by its real token — and a person's token without the
        // agent's abilities is a person (CLAUDE.md #3: any client may drive
        // the API with a token; that does not make it the factory PC).
        [$agent] = $this->agent('factory-pc');
        $issued = $agent->tokens()->first();
        $this->assertTrue(AgentIdentity::mayReadPurchaseDetails($agent->withAccessToken($issued)));
        $laptop = $this->staffUser(['tally-sync.view']);
        $laptop->createToken('laptop', []);
        $this->assertFalse(AgentIdentity::mayReadPurchaseDetails($laptop->withAccessToken($laptop->tokens()->first())));
    }

    // ---- helpers ------------------------------------------------------------

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
     * permission at all (SyncPayloadRateVisibilityTest).
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
        $po->setRelation('vendor', new Vendor(['name' => self::VENDOR, 'gstin' => self::VENDOR_GSTIN]));

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

    private function enqueueDelivery(): TallySyncEntry
    {
        $so = new SalesOrder;
        $so->setRelation('customer', new Customer(['name' => 'Sri Aurobindo Beverages', 'gstin' => '33AAACS1234A1Z9']));

        $bottle = new DeliveryLine(['quantity' => '2000.0000']);
        $bottle->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $delivery = $this->existing(new Delivery(['delivered_date' => '2026-08-03']), 3);
        $delivery->setRelation('lines', collect([$bottle]));
        $delivery->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $delivery->setRelation('salesOrder', $so);

        event(new DeliveryDispatched($delivery));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Delivery Note')->sole();
    }

    /** A two-line journal (Dr Sales / Cr Debtors), in memory, for the real enqueue path. */
    private function journal(): JournalEntry
    {
        $debit = new JournalEntryLine(['debit' => '100.0000', 'credit' => '0.0000']);
        $debit->setRelation('glAccount', new GLAccount(['code' => '4000', 'name' => 'Sales']));
        $credit = new JournalEntryLine(['debit' => '0.0000', 'credit' => '100.0000']);
        $credit->setRelation('glAccount', new GLAccount(['code' => '1200', 'name' => 'Debtors']));

        $journal = $this->existing(new JournalEntry(['entry_date' => '2026-08-02', 'reference' => 'JE-REF-9']), 4);
        $journal->setRelation('lines', collect([$debit, $credit]));

        return $journal;
    }

    /** Mark an in-memory model as an existing (persisted) record without a DB write. */
    private function existing(object $model, int $id): object
    {
        $model->id = $id;
        $model->exists = true;

        return $model;
    }
}
