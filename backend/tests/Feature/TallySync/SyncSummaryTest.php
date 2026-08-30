<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Finance\Models\GLAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalEntryLine;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * GET /tally-sync/summary — the Control Center's header
 * (TALLY-SYNC-CHAIN.md §3): today's counts in the FACTORY's day, all-time
 * counts, one count per catalogue category, and when the agent and Tally
 * were last heard from.
 *
 * The one thing here that would silently lie if got wrong: "today" is the
 * voucher's business date judged in the factory timezone, NOT the row's
 * created_at in the app's UTC. The fixture is the live case — the night
 * shift's voucher, dated yesterday, enqueued at 00:30 IST — and it must
 * count as yesterday's business even though it was written today.
 */
class SyncSummaryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private Shift $night;

    private WorkCenter $machine;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    /** @var array<string, TallySyncEntry> */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 15]);
        // The LIVE split: app clock UTC, factory wall clock IST.
        config(['tally-sync.factory_timezone' => 'Asia/Kolkata']);
        config(['tally-sync.voucher_granularity' => 'shift']);

        $this->night = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);

        // The Sales voucher in the fixture queue is now assembled GST-correct or
        // not staged at all: the registration, the ledger mappings, the single
        // Tally-linked godown and the items' HSN all have to exist BEFORE the
        // invoice is issued. Neither store carries a tally_guid, so this adds
        // the one godown that resolves.
        $this->seedSalesTallyMasterData();
    }

    /**
     * 00:30 IST on 17 Aug (19:00 UTC on 16 Aug). The night shift that
     * started 22:00 on the 16th is still running (ends 06:00 IST on the
     * 17th), so its voucher — dated 16 Aug — is HELD, and is yesterday's
     * business. Everything else is dated today (17 Aug IST) except a
     * dismissed delivery from the 15th.
     *
     *   night   Stock Journal  2026-08-16  pending, HELD  (yesterday IST; created today UTC-wise)
     *   inv     Sales          2026-08-17  SYNCED by the agent's ack
     *   grn     Receipt Note   2026-08-17  pending
     *   je      Journal        2026-08-17  FAILED
     *   dn      Delivery Note  2026-08-15  DISMISSED
     */
    private function buildQueue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 19:00:00', 'UTC'));
        $sync = app(TallySyncService::class);

        $member = $this->approvedProduction($this->night, '2026-08-16');
        $this->entries['night'] = $sync->enqueueShiftProductionEntry($member);

        $this->entries['inv'] = $sync->enqueueSalesInvoice($this->invoice(5, '2026-08-17', 'Sri Aurobindo Beverages'));
        $this->entries['grn'] = $sync->enqueueGoodsReceiptNote($this->goodsReceipt(7, '2026-08-17', 'Reliance Industries'));

        $this->entries['je'] = $sync->enqueueJournalEntry($this->journal(4, '2026-08-17', 'JE-REF-9'));
        $sync->markFailed($this->entries['je'], 'Ledger does not exist');

        $this->entries['dn'] = $sync->enqueueDelivery($this->delivery(3, '2026-08-15', 'Sri Aurobindo Beverages'));
        $sync->markFailed($this->entries['dn'], "Could not set 'SVCurrentCompany'");
        $sync->dismiss($this->entries['dn']->fresh());

        $this->assertSame('2026-08-16', $this->entries['night']->payload['voucher_date']);
    }

    public function test_today_is_the_factory_day_on_the_business_date_not_created_at(): void
    {
        $this->buildQueue();
        $this->actAsStaff();

        $summary = $this->summary();

        // 00:30 IST on the 17th IS the 17th for the factory, whatever the
        // UTC clock says.
        $this->assertSame('2026-08-17', $summary['today']['date']);

        // Three vouchers carry today's business date. The night voucher was
        // CREATED in this same instant and is NOT among them: it is dated
        // yesterday, so it is yesterday's business.
        $this->assertSame([
            'date' => '2026-08-17',
            'total' => 3,
            'synced' => 0,
            'pending' => 2,
            'failed' => 1,
            'dismissed' => 0,
            'held' => 0,
        ], $summary['today']);

        $this->assertSame([
            'total' => 5,
            'synced' => 0,
            'pending' => 3,
            'failed' => 1,
            'dismissed' => 1,
            // The night voucher: the REAL gate says the shift is collecting.
            'held' => 1,
        ], $summary['all_time']);

        // THE NIGHT-SHIFT CASE: today.held is 0 (the held voucher is dated
        // yesterday) while the gate is holding it RIGHT NOW. held_now is
        // the state, not the window — the header must say "1 held now"
        // over this queue, never "0 held".
        $this->assertSame(0, $summary['today']['held']);
        $this->assertSame(1, $summary['held_now']);
    }

    public function test_by_category_names_the_whole_catalogue_once_with_measured_counts_or_null(): void
    {
        $this->buildQueue();
        $this->actAsStaff();

        $rows = collect($this->summary()['by_category']);

        // Every catalogue key, exactly once, in the catalogue's stable order.
        $this->assertSame(
            array_map(fn (TallyTransactionCategory $c) => $c->value, TallyTransactionCategory::cases()),
            $rows->pluck('key')->all(),
        );
        foreach ($rows as $row) {
            $this->assertSame(['key', 'label', 'source', 'erp_build', 'wire_voucher_type', 'count'], array_keys($row), $row['key']);
        }

        $counts = $rows->pluck('count', 'key');

        // ERP-built: measured, including the honest zeros.
        $this->assertSame(1, $counts['production_stock_journal_shift']);
        $this->assertSame(0, $counts['production_stock_journal_batch']);
        $this->assertSame(1, $counts['sales_invoice']);
        $this->assertSame(1, $counts['delivery_note']);
        $this->assertSame(1, $counts['receipt_note']);
        $this->assertSame(1, $counts['journal']);
        // Purchase Order (Phase 6): ERP-built and STAGED, flag off — this
        // queue staged none, so the count is an honest, measured 0 (never a
        // null: the row is the ERP's own, not the accountant's 92).
        $this->assertSame(0, $counts['purchase_order']);
        // Rows the table cannot place are real rows too — measured (none here).
        $this->assertSame(0, $counts['unknown']);

        // Tally-only and absent: NULL, never 0. Nothing was measured
        // because nothing is mirrored; a zero would claim the books were read.
        foreach (['purchase', 'payment', 'receipt', 'contra', 'credit_note', 'debit_note', 'sales_order'] as $key) {
            $this->assertNull($counts[$key], $key);
            $this->assertArrayHasKey($key, $counts->all(), $key);
        }

        // Label, source, erp_build and wire type ride along from the catalogue.
        $purchase = $rows->firstWhere('key', 'purchase');
        $this->assertSame('tally', $purchase['source']);
        $this->assertSame('none', $purchase['erp_build']);
        $this->assertSame('Purchase', $purchase['wire_voucher_type']);
        $this->assertSame('Purchase (lives in Tally)', $purchase['label']);

        // Purchase Order (Phase 6): the ERP builds and stages it (source erp,
        // erp_build built) — the 92 accountant-keyed orders in the books are
        // NOT this row's; its count is the ERP's own staged rows, 0 here.
        $purchaseOrder = $rows->firstWhere('key', 'purchase_order');
        $this->assertSame('erp', $purchaseOrder['source']);
        $this->assertSame('built', $purchaseOrder['erp_build']);
        $this->assertSame('Purchase Order', $purchaseOrder['wire_voucher_type']);
        $this->assertSame(0, $purchaseOrder['count']);

        // Sales Order: no such voucher type in the books at all — absent,
        // and null because there was nothing to measure, not because
        // nothing was mirrored.
        $salesOrder = $rows->firstWhere('key', 'sales_order');
        $this->assertSame('absent', $salesOrder['source']);
        $this->assertSame('none', $salesOrder['erp_build']);
        $this->assertNull($salesOrder['wire_voucher_type']);
        $this->assertNull($salesOrder['count']);
    }

    public function test_nothing_heard_yet_reads_as_nulls_not_epoch_zero(): void
    {
        $this->buildQueue();
        $this->actAsStaff();

        $quiet = $this->summary();
        $this->assertSame([
            'last_action_at' => null,
            'last_action_event' => null,
            'last_action_label' => null,
            // Nothing has polled either — "never", which the page words
            // differently from "the agent went quiet".
            'last_checked_at' => null,
        ], $quiet['agent']);
        $this->assertNull($quiet['last_synced_at']);
        $this->assertNull($quiet['last_masters_pull_at']);
    }

    public function test_agent_action_last_synced_and_last_masters_pull_come_from_the_history(): void
    {
        $this->buildQueue();

        // The agent speaks through its REAL token (no staff session in the
        // way — Sanctum::actingAs would answer for every request after it).
        // A masters pull at 01:00 IST …
        [, $token] = $this->agent('factory-pc');
        Carbon::setTestNow($mastersAt = Carbon::parse('2026-08-16 19:30:00', 'UTC'));
        $this->withToken($token)->postJson('/api/v1/tally-sync/masters', [
            'company' => 'SWAASHPET POLYMERS PVT LTD',
            'godowns' => [['guid' => 'gd-main', 'name' => 'Main Store']],
        ])->assertOk();

        // … then an ack of the invoice at 01:10 IST: the last agent action.
        Carbon::setTestNow($ackAt = Carbon::parse('2026-08-16 19:40:00', 'UTC'));
        $this->entries['inv']->update(['delivered_at' => now()]);
        $this->withToken($token)->postJson("/api/v1/tally-sync/entries/{$this->entries['inv']->id}/ack")->assertOk();

        // A person acting LATER with a personal access token that lacks the
        // agent's abilities is a USER on the record — a laptop is not the
        // factory PC, and it does not move the light. (The sanctum guard
        // caches the resolved user across requests in one test; forgotten
        // so the second token is actually resolved.)
        Carbon::setTestNow(Carbon::parse('2026-08-16 19:50:00', 'UTC'));
        $this->app['auth']->forgetGuards();
        $laptop = $this->staffUser()->createToken('laptop', [])->plainTextToken;
        $this->withToken($laptop)->postJson("/api/v1/tally-sync/entries/{$this->entries['je']->id}/dismiss")->assertOk();
        $this->assertSame('user', $this->entries['je']->events()->where('event', 'voucher.dismissed')->sole()->actor_type);

        $this->actAsStaff();
        $summary = $this->summary();

        // The shape, pinned so a new key cannot appear unnoticed …
        $this->assertSame(
            ['last_action_at', 'last_action_event', 'last_action_label', 'last_checked_at'],
            array_keys($summary['agent']),
        );
        $this->assertSame($ackAt->toIso8601String(), $summary['agent']['last_action_at']);
        $this->assertSame('voucher.synced', $summary['agent']['last_action_event']);
        // The installation, by its token's NAME — never the token.
        $this->assertSame('factory-pc', $summary['agent']['last_action_label']);

        // … and the CHECK-IN, which is a different fact from the last
        // action: Sanctum stamps last_used_at on the agent's calls, so the
        // agent is demonstrably here. Not pinned to an exact instant —
        // within ONE test process the sanctum guard caches the resolved
        // token and does not re-stamp it on the second call with the same
        // token, which is a harness artefact and not the contract. What IS
        // the contract: an agent that has called is not null, and the
        // LAPTOP's later call (a token with no agent ability) did not move
        // it past the agent's own last call.
        $this->assertNotNull($summary['agent']['last_checked_at']);
        $this->assertGreaterThanOrEqual($mastersAt->toIso8601String(), $summary['agent']['last_checked_at']);
        $this->assertLessThanOrEqual($ackAt->toIso8601String(), $summary['agent']['last_checked_at']);
        $this->assertStringNotContainsString($token, json_encode($summary));
        $this->assertSame($ackAt->toIso8601String(), $summary['last_synced_at']);
        $this->assertSame($mastersAt->toIso8601String(), $summary['last_masters_pull_at']);

        // The ack moved the invoice: today's synced count follows.
        $this->assertSame(1, $summary['today']['synced']);
        $this->assertSame(1, $summary['today']['pending']);
        $this->assertSame(1, $summary['all_time']['synced']);
    }

    public function test_held_follows_the_real_gate_as_the_clock_moves(): void
    {
        $this->buildQueue();
        $this->actAsStaff();

        $this->assertSame(1, $this->summary()['all_time']['held']);

        // 06:20 IST on the 17th: the night shift ended at 06:00 and the last
        // merge (00:30) is long past the idle-hold — released.
        Carbon::setTestNow(Carbon::parse('2026-08-17 00:50:00', 'UTC'));
        $released = $this->summary();
        $this->assertSame(0, $released['all_time']['held']);
        $this->assertSame(0, $released['held_now']);
        $this->assertSame(3, $released['all_time']['pending'], 'Released is still pending — held is a sub-state, not a status');
    }

    public function test_the_counts_follow_the_lists_filters_and_the_liveness_fields_do_not(): void
    {
        $this->buildQueue();
        $this->actAsStaff();

        // from/to: only today's business.
        $today = $this->summary('from=2026-08-17&to=2026-08-17');
        $this->assertSame(3, $today['all_time']['total']);
        $this->assertSame(0, $today['all_time']['held']);
        // held_now ignores the business-date range — the night voucher,
        // dated yesterday, is held at this moment whichever day is asked
        // about — but follows every other filter (below).
        $this->assertSame(1, $today['held_now']);
        $this->assertSame(0, collect($today['by_category'])->firstWhere('key', 'production_stock_journal_shift')['count']);
        $this->assertSame(1, collect($today['by_category'])->firstWhere('key', 'sales_invoice')['count']);
        // Tally-only stays null under any filter — a filter measures nothing there either.
        $this->assertNull(collect($today['by_category'])->firstWhere('key', 'purchase')['count']);

        // A status filter narrows every count the same way.
        $failed = $this->summary('status[]=failed');
        $this->assertSame(1, $failed['all_time']['total']);
        $this->assertSame(1, $failed['today']['total']);
        $this->assertSame(1, $failed['today']['failed']);
        $this->assertSame(0, $failed['held_now'], 'A held voucher is pending, not failed');
        $this->assertSame(1, collect($failed['by_category'])->firstWhere('key', 'journal')['count']);

        // A category the ERP does not build: honest zeros for the counts, no
        // 422 — the same request the list accepts.
        $none = $this->summary('category[]=purchase');
        $this->assertSame(0, $none['all_time']['total']);
        $this->assertSame(0, $none['today']['total']);

        // The same malformed input the list refuses, the summary refuses.
        $this->getJson('/api/v1/tally-sync/summary?status[]=exploded')->assertStatus(422);
    }

    // ---- helpers ------------------------------------------------------------

    /** @return array<string, mixed> the summary's data block */
    private function summary(string $query = ''): array
    {
        return $this->getJson('/api/v1/tally-sync/summary'.($query === '' ? '' : '?'.$query))
            ->assertOk()
            ->json('data');
    }

    private function actAsStaff(): void
    {
        Sanctum::actingAs($this->staffUser());
    }

    private function staffUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['tally-sync.view', 'tally-sync.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /**
     * A REAL personal access token: the agent's label is the token's NAME,
     * which Sanctum::actingAs() cannot stand in for (SyncEventHistoryTest).
     *
     * @return array{0: User, 1: string}
     */
    private function agent(string $tokenName): array
    {
        $user = User::factory()->create(['name' => 'Tally Sync Agent', 'is_active' => true]);
        $issued = $user->createToken($tokenName, ['tally-sync:poll', 'tally-sync:report', 'tally-sync:masters']);

        return [$user, $issued->plainTextToken];
    }

    // ---- fixtures: the real enqueue paths' inputs ------------------------------

    private function approvedProduction(Shift $shift, string $date): ShiftProductionEntry
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => $date,
            'batch_status' => BatchStatus::Completed,
            'batch_number' => 'B-N-1',
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Approved,
        ]);

        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '250.0000',
        ]);

        return $entry;
    }

    private function goodsReceipt(int $id, string $date, string $vendor): GoodsReceiptNote
    {
        $po = new PurchaseOrder;
        $po->setRelation('vendor', new Vendor(['name' => $vendor, 'gstin' => '27AAACR1234A1Z5', 'tally_ledger_name' => $vendor]));

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '85.0000']);
        $line->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin', 'tally_stock_item_guid' => 'itm-resin']));
        $line->setRelation('scheduleAllocations', collect());

        $grn = $this->existing(new GoodsReceiptNote(['received_date' => $date]), $id);
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', new Warehouse(['name' => 'RM Store', 'tally_guid' => 'gd-rm']));
        $grn->setRelation('purchaseOrder', $po);

        return $grn;
    }

    private function delivery(int $id, string $date, string $customer): Delivery
    {
        $so = new SalesOrder;
        $so->setRelation('customer', new Customer(['name' => $customer]));

        $line = new DeliveryLine(['quantity' => '2000.0000']);
        $line->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $delivery = $this->existing(new Delivery(['delivered_date' => $date]), $id);
        $delivery->setRelation('lines', collect([$line]));
        $delivery->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $delivery->setRelation('salesOrder', $so);

        return $delivery;
    }

    private function invoice(int $id, string $date, string $customer): Invoice
    {
        // Unlike the delivery and GRN fixtures, the party and the item here are
        // REAL rows: the Sales voucher is built from their own masters — the
        // customer's Tally ledger name and state code, the item's HSN and rate —
        // and the seeding writes those onto rows that exist. Completed here,
        // after the customer row is made and before the invoice is issued.
        $party = Customer::create(['code' => "CUST-{$id}", 'name' => $customer]);
        $this->completeSalesTallyMastersOnExistingRows();

        $line = new InvoiceLine(['quantity' => '1000.0000', 'unit_price' => '4.5000']);
        $line->setRelation('item', $this->bottle->fresh());

        $invoice = $this->existing(new Invoice(['invoice_date' => $date]), $id);
        $invoice->setRelation('lines', collect([$line]));
        $invoice->setRelation('customer', $party->fresh());

        return $invoice;
    }

    private function journal(int $id, string $date, string $reference): JournalEntry
    {
        $line = new JournalEntryLine(['debit' => '100.0000', 'credit' => '0.0000']);
        $line->setRelation('glAccount', new GLAccount(['code' => '4000', 'name' => 'Sales']));

        $journal = $this->existing(new JournalEntry(['entry_date' => $date, 'reference' => $reference]), $id);
        $journal->setRelation('lines', collect([$line]));

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
