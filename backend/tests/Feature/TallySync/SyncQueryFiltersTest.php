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
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /tally-sync/entries with the server-side filters of
 * TALLY-SYNC-CHAIN.md §3 "A query service" — every filter returns exactly
 * the right ids over one mixed queue built through the REAL enqueue paths:
 * a Receipt Note, two Delivery Notes, a Sales invoice, a Journal, one
 * batch-mode production voucher and one shift-mode voucher with two members
 * on two machines; one of them synced, one failed, one dismissed.
 *
 * What is pinned, and why it matters on a live queue:
 *   - category filters come from the ONE classification table, so a filter
 *     can never disagree with the category the row shows; a Tally-only key
 *     matches nothing rather than guessing;
 *   - from/to are the voucher's BUSINESS date, inclusive at both ends;
 *   - q is a plain case-insensitive contains-LIKE over voucher_number,
 *     party_ledger and batch_number — never fuzzy;
 *   - shift and machine filters reach a shift voucher through its MEMBERS
 *     (shift_production_entries.tally_sync_entry_id), the same membership
 *     the payload is built from;
 *   - held is the REAL release gate's verdict at the moment of the request;
 *   - direction=tally_to_erp is honestly empty: no entry is inbound;
 *   - the default order and the per_page clamp are unchanged.
 */
class SyncQueryFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shift $morning;

    private Shift $night;

    private WorkCenter $machine1;

    private WorkCenter $machine2;

    private WorkCenter $machine3;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    /** @var array<string, TallySyncEntry> keyed by the fixture's short name */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The gate and the approval chain are pinned by their own suites;
        // here they are configured, not neutralised, because `held` must
        // read the real gate. Clock and factory wall clock in ONE frame
        // (UTC) so "inside the morning shift" is unambiguous.
        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 15]);
        config(['tally-sync.factory_timezone' => 'UTC']);

        $this->morning = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->night = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->machine1 = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $this->machine2 = WorkCenter::create(['code' => 'M-02', 'name' => 'Machine 2']);
        $this->machine3 = WorkCenter::create(['code' => 'M-03', 'name' => 'Machine 3']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);

        // 10:00 on 23 July: the morning shift is collecting, so the shift
        // voucher enqueued below is HELD by the gate.
        Carbon::setTestNow(Carbon::parse('2026-07-23 10:00:00', 'UTC'));

        $this->buildQueue();
        $this->actAsStaff();
    }

    /**
     * The mixed queue, in creation order (ids ascend in this order):
     *
     *   grn      Receipt Note   2026-08-04  Reliance Industries      GRN-7            pending
     *   dn3      Delivery Note  2026-08-03  Sri Aurobindo Beverages  DN-3             pending
     *   inv      Sales          2026-08-01  Sri Aurobindo Beverages  INV-5            SYNCED
     *   je       Journal        2026-08-02  —                        JE-REF-9         FAILED
     *   dn4      Delivery Note  2026-08-05  Sri Aurobindo Beverages  DN-4             DISMISSED
     *   batch    Manuf. Journal 2026-07-22  —  SPE-{id}  batch L1-M-20260722-1  (Morning, M-01)   pending
     *   shift    Stock Journal  2026-07-23  —  SJ-20260723-S{morning} members: A (M-01), B (M-02)  pending, HELD
     */
    private function buildQueue(): void
    {
        $sync = app(TallySyncService::class);

        $this->entries['grn'] = $sync->enqueueGoodsReceiptNote($this->goodsReceipt(7, '2026-08-04', 'Reliance Industries'));
        $this->entries['dn3'] = $sync->enqueueDelivery($this->delivery(3, '2026-08-03', 'Sri Aurobindo Beverages'));

        $this->entries['inv'] = $sync->enqueueSalesInvoice($this->invoice(5, '2026-08-01', 'Sri Aurobindo Beverages'));
        $sync->markSynced($this->entries['inv']);

        $this->entries['je'] = $sync->enqueueJournalEntry($this->journal(4, '2026-08-02', 'JE-REF-9'));
        $sync->markFailed($this->entries['je'], 'Ledger does not exist');

        $this->entries['dn4'] = $sync->enqueueDelivery($this->delivery(4, '2026-08-05', 'Sri Aurobindo Beverages'));
        $sync->markFailed($this->entries['dn4'], "Could not set 'SVCurrentCompany'");
        $sync->dismiss($this->entries['dn4']->fresh());

        config(['tally-sync.voucher_granularity' => 'batch']);
        $batchEntry = $this->approvedProduction($this->morning, $this->machine1, '2026-07-22', 'L1-M-20260722-1');
        $this->entries['batch'] = $sync->enqueueShiftProductionEntry($batchEntry);

        config(['tally-sync.voucher_granularity' => 'shift']);
        $memberA = $this->approvedProduction($this->morning, $this->machine1, '2026-07-23', 'B-A');
        $memberB = $this->approvedProduction($this->morning, $this->machine2, '2026-07-23', 'B-B');
        $sync->enqueueShiftProductionEntry($memberA);
        $this->entries['shift'] = $sync->enqueueShiftProductionEntry($memberB)->fresh();

        // The fixture is what the docblock says it is — or nothing below
        // means anything.
        $this->assertCount(7, TallySyncEntry::all());
        $this->assertSame((new Shift)->getMorphClass(), $this->entries['shift']->syncable_type);
        $this->assertSame([$memberA->id, $memberB->id], ShiftProductionEntry::query()
            ->where('tally_sync_entry_id', $this->entries['shift']->id)->orderBy('id')->pluck('id')->all());
    }

    // ---- status -----------------------------------------------------------

    public function test_status_filters_to_exactly_those_statuses(): void
    {
        $this->assertIds(['je'], 'status[]=failed');
        $this->assertIds(['inv', 'dn4'], 'status[]=synced&status[]=dismissed');
        // A hand-typed scalar is as good as the SPA's array.
        $this->assertIds(['je'], 'status=failed');
        $this->assertIds(['grn', 'dn3', 'batch', 'shift'], 'status=pending');
    }

    public function test_an_unknown_status_is_refused_not_ignored(): void
    {
        $this->getJson('/api/v1/tally-sync/entries?status[]=exploded')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status.0']);
    }

    // ---- category ---------------------------------------------------------

    public function test_each_erp_category_returns_exactly_its_entries(): void
    {
        $this->assertIds(['grn'], 'category[]=receipt_note');
        $this->assertIds(['dn3', 'dn4'], 'category[]=delivery_note');
        $this->assertIds(['inv'], 'category[]=sales_invoice');
        $this->assertIds(['je'], 'category[]=journal');
        $this->assertIds(['batch'], 'category[]=production_stock_journal_batch');
        $this->assertIds(['shift'], 'category[]=production_stock_journal_shift');
        // Several at once OR together.
        $this->assertIds(['inv', 'batch', 'shift'], 'category[]=sales_invoice&category[]=production_stock_journal_batch&category[]=production_stock_journal_shift');
    }

    public function test_a_tally_only_or_planned_category_matches_nothing_and_unknown_is_the_complement(): void
    {
        // Lives in Tally, never mirrored: the honest answer is an empty list,
        // not a 422 and not "everything".
        $this->assertIds([], 'category[]=purchase');
        $this->assertIds([], 'category[]=payment');
        $this->assertIds([], 'category[]=sales_order');
        // Planned, not built (Phase 6).
        $this->assertIds([], 'category[]=purchase_order');
        // A Tally-only key OR'd with a real one adds nothing.
        $this->assertIds(['grn'], 'category[]=purchase&category[]=receipt_note');

        // Nothing here classifies Unknown …
        $this->assertIds([], 'category[]=unknown');
        // … until a row the table cannot place exists — the same row the
        // resource labels Unknown.
        $orphan = TallySyncEntry::create([
            'syncable_type' => 'shift_production_entry', 'syncable_id' => 1,
            'tally_voucher_type' => 'Manufacturing Journal', 'payload' => ['voucher_number' => 'SPE-1'],
            'status' => 'pending', 'attempts' => 0,
        ]);
        $this->assertSame([$orphan->id], $this->ids('category[]=unknown'));
        $this->assertSame([$orphan->id, $this->entries['grn']->id], $this->ids('category[]=unknown&category[]=receipt_note'));
        $this->assertSame('unknown', collect($this->getJson('/api/v1/tally-sync/entries')->json('data'))
            ->firstWhere('id', $orphan->id)['category']['key']);
    }

    // ---- voucher_type -----------------------------------------------------

    public function test_voucher_type_filters_on_the_raw_label(): void
    {
        $this->assertIds(['dn3', 'dn4'], 'voucher_type[]='.rawurlencode('Delivery Note'));
        $this->assertIds(['inv', 'je'], 'voucher_type[]=Sales&voucher_type[]=Journal');
        // The ERP's own batch label, as stored — not the wire type.
        $this->assertIds(['batch'], 'voucher_type[]='.rawurlencode('Manufacturing Journal'));
        $this->assertIds(['shift'], 'voucher_type[]='.rawurlencode('Stock Journal'));
    }

    // ---- from / to --------------------------------------------------------

    public function test_from_and_to_are_inclusive_on_the_business_date(): void
    {
        $this->assertIds(['grn', 'dn3', 'je'], 'from=2026-08-02&to=2026-08-04');
        // Both ends inclusive: the range that is exactly one voucher's date.
        $this->assertIds(['je'], 'from=2026-08-02&to=2026-08-02');
        // Open-ended either side.
        $this->assertIds(['grn', 'dn3', 'inv', 'je', 'dn4', 'shift'], 'from=2026-07-23');
        $this->assertIds(['batch', 'shift'], 'to=2026-07-23');
        // A range nothing falls in.
        $this->assertIds([], 'from=2026-09-01&to=2026-09-30');
    }

    public function test_a_reversed_or_malformed_date_range_is_refused(): void
    {
        $this->getJson('/api/v1/tally-sync/entries?from=2026-08-04&to=2026-08-02')
            ->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/tally-sync/entries?from=04-08-2026')
            ->assertStatus(422)->assertJsonValidationErrors(['from']);
    }

    // ---- q ----------------------------------------------------------------

    public function test_q_matches_voucher_number_party_and_batch_number_case_insensitively(): void
    {
        // Voucher number, exact.
        $this->assertIds(['grn'], 'q=GRN-7');
        // Voucher number, prefix, wrong case.
        $this->assertIds(['dn3', 'dn4'], 'q=dn-');
        $this->assertIds(['shift'], 'q=sj-2026');
        $this->assertIds(['batch'], 'q=SPE-'.$this->entries['batch']->syncable_id);
        // Party name, a word from the middle, lower case — a CUSTOMER, which
        // is not FC-06, so it matches for this tally-sync-only reader.
        $this->assertIds(['dn3', 'inv', 'dn4'], 'q=aurobindo');
        // A VENDOR is FC-06's second half (who supplied it — Owner/Accounts
        // only): for this reader the search box is not an oracle on
        // Receipt Notes, so the vendor's name finds nothing here. Finance
        // finds it — SupplierIdentityVisibilityTest pins both halves.
        $this->assertIds([], 'q=reliance');
        // Batch number (batch-mode vouchers carry it in the payload).
        $this->assertIds(['batch'], 'q=L1-M-20260722');
        // A LIKE wildcard typed by hand is a character, not a wildcard.
        $this->assertIds([], 'q='.rawurlencode('%'));
        $this->assertIds([], 'q='.rawurlencode('_'));
        // Nothing found is nothing found — no fuzziness.
        $this->assertIds([], 'q=GRN-8');
        $this->assertIds([], 'q=Aurobindoo');
    }

    /**
     * A JSON null in the payload is NOT a value to match. The shift voucher
     * writes `batch_number: null` on every payload (shiftVoucherPayload), and
     * an entry can carry `voucher_date: null` when its source had no date.
     * On SQLite json_extract() yields SQL NULL for those and nothing matches
     * by accident; on MySQL json_unquote(json_extract()) yields the STRING
     * 'null' — so q=ul would find every shift voucher and any `from` would
     * accept a dateless entry. The query guards each JSON-path predicate
     * with a whereNotNull on the same path (JSON-null-aware on MySQL); this
     * pins the contract on the driver the suite runs on, and the guard is
     * what carries it to the live one.
     */
    public function test_a_json_null_in_the_payload_never_matches_q_or_a_date_range(): void
    {
        $dateless = TallySyncEntry::create([
            'syncable_type' => 'shift_production_entry', 'syncable_id' => 999,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => 'SPE-999', 'voucher_date' => null, 'batch_number' => null, 'party_ledger' => null],
            'status' => 'pending', 'attempts' => 0,
        ]);
        $this->assertNull($this->entries['shift']->payload['batch_number'], 'The shift voucher carries a null batch_number');

        // Neither 'null' nor its substrings find a null.
        foreach (['null', 'ul', 'NULL'] as $term) {
            $found = $this->ids("q={$term}");
            $this->assertNotContains($this->entries['shift']->id, $found, "q={$term} matched the shift voucher's null batch_number");
            $this->assertNotContains($dateless->id, $found, "q={$term} matched a null");
        }
        $this->assertSame([], $this->ids('q=null'));

        // A null business date is outside every range.
        foreach (['from=2000-01-01', 'to=2099-12-31', 'from=2000-01-01&to=2099-12-31'] as $range) {
            $this->assertNotContains($dateless->id, $this->ids($range), "?{$range} accepted a null voucher_date");
        }
        // And the real dates still answer as before with the extra row present.
        $this->assertIds(['grn', 'dn3', 'je'], 'from=2026-08-02&to=2026-08-04');
    }

    // ---- shift_id ---------------------------------------------------------

    public function test_shift_id_reaches_batch_vouchers_and_shift_vouchers_alike(): void
    {
        // The batch voucher through its entry's shift; the shift voucher
        // through its Shift morph and its members.
        $this->assertIds(['batch', 'shift'], "shift_id={$this->morning->id}");
        $this->assertIds([], "shift_id={$this->night->id}");
    }

    // ---- work_center_id ---------------------------------------------------

    public function test_work_center_id_reaches_a_shift_voucher_through_any_member(): void
    {
        // Machine 2 ran only member B — and B rides the shift voucher.
        $this->assertIds(['shift'], "work_center_id={$this->machine2->id}");
        // Machine 1 ran the batch entry and member A.
        $this->assertIds(['batch', 'shift'], "work_center_id={$this->machine1->id}");
        // Machine 3 ran nothing.
        $this->assertIds([], "work_center_id={$this->machine3->id}");
    }

    // ---- held -------------------------------------------------------------

    public function test_held_is_the_real_gates_verdict_at_the_moment_of_the_request(): void
    {
        // 10:00, mid-shift: the shift voucher is collecting.
        $this->assertIds(['shift'], 'held=true');
        $this->assertIds(['grn', 'dn3', 'inv', 'je', 'dn4', 'batch'], 'held=false');
        $this->assertIds(['shift'], 'held=1');

        // 14:20: the shift ended at 14:00 and the last merge (10:00) is more
        // than the 15-minute idle-hold ago — released; nothing is held.
        Carbon::setTestNow(Carbon::parse('2026-07-23 14:20:00', 'UTC'));
        $this->assertIds([], 'held=true');
        $this->assertIds(['grn', 'dn3', 'inv', 'je', 'dn4', 'batch', 'shift'], 'held=false');
        $this->assertIds(['shift'], 'held=false&status=pending&category[]=production_stock_journal_shift');
    }

    // ---- direction --------------------------------------------------------

    public function test_direction_erp_to_tally_is_everything_and_tally_to_erp_is_nothing(): void
    {
        $this->assertIds(['grn', 'dn3', 'inv', 'je', 'dn4', 'batch', 'shift'], 'direction=erp_to_tally');
        // Inbound flows (a masters pull) never create an entry; they live on
        // tally_sync_events. The list says so with an empty page, not a lie.
        $this->assertIds([], 'direction=tally_to_erp');
        $this->getJson('/api/v1/tally-sync/entries?direction=sideways')->assertStatus(422);
    }

    // ---- sort -------------------------------------------------------------

    public function test_the_default_order_is_newest_first_and_status_rank_is_opt_in(): void
    {
        // Unchanged default: id descending, exactly what the page has always
        // received.
        $this->assertSame(
            $this->idsOf(['shift', 'batch', 'dn4', 'je', 'inv', 'dn3', 'grn']),
            $this->ids(''),
        );

        // failed → pending (newest first within) → synced → dismissed: the
        // page's client-side statusRank, now available server-side.
        $this->assertSame(
            $this->idsOf(['je', 'shift', 'batch', 'dn3', 'grn', 'inv', 'dn4']),
            $this->ids('sort=status_rank'),
        );

        // Sorting composes with filtering and with paging.
        $this->assertSame($this->idsOf(['je', 'shift']), $this->ids('sort=status_rank&per_page=2'));
        $this->getJson('/api/v1/tally-sync/entries?sort=status_rank&per_page=2')
            ->assertOk()->assertJsonPath('meta.total', 7);
        $this->getJson('/api/v1/tally-sync/entries?sort=alphabetical')->assertStatus(422);
    }

    // ---- filters compose ------------------------------------------------------

    public function test_filters_compose_with_and(): void
    {
        $this->assertIds(['dn3'], 'category[]=delivery_note&status=pending');
        $this->assertIds(['dn4'], 'q=aurobindo&status=dismissed');
        $this->assertIds(['shift'], "work_center_id={$this->machine1->id}&from=2026-07-23");
        $this->assertIds([], "work_center_id={$this->machine1->id}&status=synced");
    }

    // ---- no filter, no change -------------------------------------------------

    public function test_no_filters_is_the_whole_queue_paginated_as_before(): void
    {
        $this->getJson('/api/v1/tally-sync/entries')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.per_page', 20);

        // Empty parameters — what a cleared filter bar sends — are no filter.
        $this->assertIds(['grn', 'dn3', 'inv', 'je', 'dn4', 'batch', 'shift'], 'status=&category=&q=&from=&to=&held=&shift_id=&work_center_id=&direction=&sort=');

        // The clamp SyncQueueVisibilityTest pins is untouched by validation.
        $this->getJson('/api/v1/tally-sync/entries?per_page=999999')->assertOk()->assertJsonPath('meta.per_page', 1000);
        $this->getJson('/api/v1/tally-sync/entries?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    // ---- helpers ------------------------------------------------------------

    /** The ids the list returns for a query string, in the order returned. */
    private function ids(string $query): array
    {
        return $this->getJson('/api/v1/tally-sync/entries?per_page=100'.($query === '' ? '' : '&'.$query))
            ->assertOk()
            ->json('data.*.id');
    }

    /** @param  list<string>  $names */
    private function idsOf(array $names): array
    {
        return array_map(fn (string $name) => $this->entries[$name]->id, $names);
    }

    /**
     * Exactly these fixtures, no more and no fewer — order-insensitive,
     * because ordering has its own test.
     *
     * @param  list<string>  $names
     */
    private function assertIds(array $names, string $query): void
    {
        $expected = $this->idsOf($names);
        $actual = $this->ids($query);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, "?{$query}");
    }

    private function actAsStaff(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('tally-sync.manage', 'web');
        $user->givePermissionTo('tally-sync.manage');
        Sanctum::actingAs($user);
    }

    // ---- fixtures: the real enqueue paths' inputs ------------------------------

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
        $line = new InvoiceLine(['quantity' => '1000.0000', 'unit_price' => '4.5000']);
        $line->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $invoice = $this->existing(new Invoice(['invoice_date' => $date]), $id);
        $invoice->setRelation('lines', collect([$line]));
        $invoice->setRelation('customer', new Customer(['name' => $customer]));

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

    /** An already-approved production entry with one resin line — see SyncEventHistoryTest. */
    private function approvedProduction(Shift $shift, WorkCenter $machine, string $date, string $batchNumber): ShiftProductionEntry
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => $date,
            'batch_status' => BatchStatus::Completed,
            'batch_number' => $batchNumber,
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

    /** Mark an in-memory model as an existing (persisted) record without a DB write. */
    private function existing(object $model, int $id): object
    {
        $model->id = $id;
        $model->exists = true;

        return $model;
    }
}
