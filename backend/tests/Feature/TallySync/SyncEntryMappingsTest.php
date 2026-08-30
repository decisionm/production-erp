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
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallyLedgerMapping;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * GET /tally-sync/entries/{id} carries `mappings` + `mapping_summary`
 * (MASTER-PLAN P3-04): for every name the voucher will hand Tally — each
 * line's item and godown, a Journal's ledgers, the party, the Sales
 * ledger — whether this ERP resolved it by IDENTITY (a row carrying a
 * Tally GUID / a configured role mapping), by NAME ONLY (a row, no GUID —
 * Tally matches by name and the ERP cannot know), or not at all
 * (unmapped / fixture / ambiguous). Derived at read time from the payload's
 * names against the masters — no conflict table, no Tally read (audit
 * §116/§62).
 *
 * The block rides the SAME gate as `history`: only the show endpoint loads
 * `events`, so only show carries mappings — the list, the agent's poll and
 * the action responses keep their exact prior shape. And FC-06 holds: the
 * mapping block carries names, ids, GUIDs, states and notes — never a
 * rate — so a tally-sync.view reader without finance sees the mapping
 * state of a Receipt Note and still no purchase rate anywhere.
 */
class SyncEntryMappingsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    /** SyncPayloadRateVisibilityTest's walk keys — the same walk, so the two suites cannot disagree on what a rate is. */
    private const RATE_KEYS = ['rate', 'amount', 'total_amount', 'debit', 'credit', 'unit_price', 'unit_cost'];

    private const COMPANY_GODOWN = 'SWAASHPET POLYMERS PVT LTD';

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);
        config(['tally-sync.release_idle_minutes' => 0]);
    }

    // ---- a Receipt Note ------------------------------------------------------

    public function test_show_of_a_receipt_note_maps_each_line_item_the_godown_and_the_party(): void
    {
        // The masters the names will be resolved against: the resin carries
        // a Tally GUID, the store is the one real godown.
        Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'itm-resin']);
        Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);

        $grn = $this->enqueueGoodsReceipt();
        // Finance: the vendor row is FC-06's "who supplied it" and reads only
        // for a reader who may see purchase details (the withheld shape for
        // everyone else is pinned below and in SupplierIdentityVisibilityTest).
        $this->actAsStaff(['tally-sync.view', 'finance.view']);

        $data = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json('data');

        // `purchase_ledger` is Phase 6's additive key (the Purchase Order's
        // TallyLedgerRole::Purchase row) — null on every other category.
        $this->assertSame(['lines', 'ledgers', 'party', 'sales_ledger', 'purchase_ledger'], array_keys($data['mappings']));
        $this->assertNull($data['mappings']['purchase_ledger'], 'Only a Purchase Order carries a purchase ledger');

        $line = $data['mappings']['lines'][0];
        $this->assertSame('lines', $line['side']);
        $this->assertSame('PET Resin', $line['item']['name']);
        $this->assertSame('identity', $line['item']['state']);
        $this->assertSame('itm-resin', $line['item']['tally_stock_item_guid']);
        $this->assertNotNull($line['item']['item_id']);
        // A Receipt Note names its godown once, at voucher level — each line
        // lands there, so each line's godown state is that godown's.
        $this->assertSame('RM Store', $line['godown']['name']);
        $this->assertSame('identity', $line['godown']['state']);
        $this->assertSame('gd-rm', $line['godown']['tally_guid']);
        $this->assertSame('self', $line['godown']['resolved_via']);

        // The vendor is a NAME on the payload — the ERP has no vendor →
        // Tally ledger link — so the honest state is name_only, and the
        // note says so.
        $this->assertSame('Reliance Industries', $data['mappings']['party']['name']);
        $this->assertSame('name_only', $data['mappings']['party']['state']);
        $this->assertStringContainsString('name only', $data['mappings']['party']['note']);

        $this->assertSame([], $data['mappings']['ledgers'], 'A Receipt Note posts no ledger lines');
        $this->assertNull($data['mappings']['sales_ledger'], 'Only a Sales invoice carries a sales ledger');

        // 2 identity (item + godown), 1 name_only (party).
        $this->assertSame(
            ['identity' => 2, 'name_only' => 1, 'unmapped' => 0, 'fixture' => 0, 'ambiguous' => 0],
            $data['mapping_summary'],
        );
    }

    // ---- a shift voucher: per-line states -----------------------------------

    public function test_show_of_a_shift_voucher_states_every_line_item_and_godown_on_its_own(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        // The one Tally godown and the internal day bin under it (the
        // GodownAliasingTest layout); a bottle Tally knows, a resin the ERP
        // has but Tally never sent (no GUID), and a masterbatch consumed
        // from a store nobody has linked or parented.
        $godown = Warehouse::create(['code' => 'GDN', 'name' => self::COMPANY_GODOWN, 'is_active' => true, 'tally_guid' => 'gd-company']);
        $dayBin = Warehouse::create(['code' => 'BIN', 'name' => 'Factory Day Bin', 'is_active' => true, 'parent_id' => $godown->id]);
        $second = Warehouse::create(['code' => 'GDN-2', 'name' => 'Second Godown', 'is_active' => true, 'tally_guid' => 'gd-second']);
        $loose = Warehouse::create(['code' => 'LOOSE', 'name' => 'Loose Bin', 'is_active' => true]);

        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'is_active' => true, 'tally_stock_item_guid' => 'itm-bottle']);
        $resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true]);
        $masterbatch = Item::create(['sku' => 'MB-1', 'name' => 'Masterbatch Amber', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'itm-mb']);

        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $godown->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'batch_number' => 'B-1',
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Approved,
        ]);
        $entry->materialConsumptions()->create(['item_id' => $resin->id, 'warehouse_id' => $dayBin->id, 'quantity_issued_kg' => '250.0000']);
        $entry->materialConsumptions()->create(['item_id' => $masterbatch->id, 'warehouse_id' => $loose->id, 'quantity_issued_kg' => '2.0000']);

        $voucher = app(TallySyncService::class)->enqueueShiftProductionEntry($entry);
        $this->assertSame((new Shift)->getMorphClass(), $voucher->syncable_type, 'The fixture is a shift voucher');
        // The day-bin line was written under the PARENT's name (resolveName);
        // the loose bin, unresolvable in a two-godown system, kept its own.
        $this->assertSame(self::COMPANY_GODOWN, $voucher->payload['consumed'][0]['godown']);
        $this->assertSame('Loose Bin', $voucher->payload['consumed'][1]['godown']);

        $this->actAsStaff(['tally-sync.view']);
        $data = $this->getJson("/api/v1/tally-sync/entries/{$voucher->id}")->assertOk()->json('data');

        $lines = collect($data['mappings']['lines']);
        $this->assertSame(['produced', 'consumed', 'consumed'], $lines->pluck('side')->all());

        $produced = $lines[0];
        $this->assertSame('500ml PET Bottle', $produced['item']['name']);
        $this->assertSame('identity', $produced['item']['state']);
        $this->assertSame(self::COMPANY_GODOWN, $produced['godown']['name']);
        $this->assertSame('identity', $produced['godown']['state']);

        $resinLine = $lines[1];
        $this->assertSame('PET Resin', $resinLine['item']['name']);
        $this->assertSame('name_only', $resinLine['item']['state']);
        $this->assertNull($resinLine['item']['tally_stock_item_guid']);
        $this->assertSame($resin->id, $resinLine['item']['item_id']);
        $this->assertSame('identity', $resinLine['godown']['state']);
        $this->assertSame('gd-company', $resinLine['godown']['tally_guid']);

        $mbLine = $lines[2];
        $this->assertSame('identity', $mbLine['item']['state']);
        $this->assertSame('Loose Bin', $mbLine['godown']['name']);
        // A warehouse row exists by that name, but it has no GUID and, with
        // two real godowns, aliases to nothing — name only.
        $this->assertSame('name_only', $mbLine['godown']['state']);
        $this->assertSame($loose->id, $mbLine['godown']['warehouse_id']);
        $this->assertNull($mbLine['godown']['tally_guid']);
        $this->assertNull($mbLine['godown']['resolved_via']);

        $this->assertNull($data['mappings']['party']);
        $this->assertSame([], $data['mappings']['ledgers']);
        $this->assertNull($data['mappings']['sales_ledger']);
        // items: identity, name_only, identity · godowns: identity, identity, name_only
        $this->assertSame(
            ['identity' => 4, 'name_only' => 2, 'unmapped' => 0, 'fixture' => 0, 'ambiguous' => 0],
            $data['mapping_summary'],
        );
    }

    // ---- a Sales invoice: party name-only, sales ledger by role -------------

    public function test_show_of_a_sales_invoice_maps_the_party_by_name_and_the_sales_ledger_by_role(): void
    {
        // THE ROLE MOVED WITH THE LEDGER. Before the 31-Aug-2026 GST rewrite a
        // Sales voucher named ONE ledger from the single `sales` role. It now
        // names one PER LINE, chosen by supply type: this fixture's buyer is in
        // Tamil Nadu (33) and the company is in Puducherry (34), so the sale is
        // interstate and the interstate role is the one that must be read.
        $entry = app(TallySyncService::class)->enqueueSalesInvoice($this->invoice());

        $this->assertArrayNotHasKey('sales_ledger', $entry->payload, 'the single top-level ledger was retired');
        $this->assertSame('Interstate Sales Taxable', $entry->payload['lines'][0]['sales_ledger']);
        $this->assertSame('4.5000', $entry->payload['lines'][0]['rate'], 'The fixture carries the rate the block must not');

        $this->actAsStaff(['tally-sync.view']);
        $data = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->json('data');

        // The item exists in the masters now (the GST payload cannot be built
        // from an item that does not), so it reads name_only rather than
        // unmapped — it has a row but no Tally GUID. And a Sales voucher DOES
        // name a godown now: every line carries one, because the real vouchers
        // do and Tally rejects a stock line without one.
        $line = $data['mappings']['lines'][0];
        $this->assertSame('500ml PET Bottle', $line['item']['name']);
        $this->assertSame('name_only', $line['item']['state']);
        $this->assertNotNull($line['item']['item_id']);
        $this->assertSame('identity', $line['godown']['state']);
        $this->assertSame('SWAASHPET POLYMERS PVT LTD', $line['godown']['name']);

        $this->assertSame('Sri Aurobindo Beverages', $data['mappings']['party']['name']);
        $this->assertSame('name_only', $data['mappings']['party']['state']);

        // THE DRAWER NAMES THE LEDGER AND THE ROLE IT CAME FROM. Reading the
        // retired `sales` role here would show a NAMELESS row wearing a green
        // identity badge — a lie about configuration, and the defect this
        // assertion exists to keep out.
        $this->assertSame('Interstate Sales Taxable', $data['mappings']['sales_ledger']['name']);
        $this->assertSame('identity', $data['mappings']['sales_ledger']['state']);
        $this->assertStringContainsString('Interstate Sales Taxable', $data['mappings']['sales_ledger']['note']);
        $this->assertStringContainsString('interstate', $data['mappings']['sales_ledger']['note']);

        $this->assertSame(
            ['identity' => 2, 'name_only' => 2, 'unmapped' => 0, 'fixture' => 0, 'ambiguous' => 0],
            $data['mapping_summary'],
        );
    }

    public function test_a_sales_invoice_whose_role_mapping_was_cleared_reads_unmapped(): void
    {
        $entry = app(TallySyncService::class)->enqueueSalesInvoice($this->invoice());

        // The mapping is cleared AFTER the voucher was queued: the state is
        // read against the mapping as it stands now, and the note says the
        // queued voucher still names the old ledger.
        //
        // AFTER is now the only order this can be written in, and that is
        // itself the point: with the mapping cleared FIRST, SalesVoucherPayload
        // refuses and stages nothing at all, so there is no voucher to read a
        // stale mapping on. The drawer's "unmapped" state exists for exactly
        // this case — a voucher queued while the mapping stood, read after
        // somebody cleared it.
        TallyLedgerMapping::query()->delete();

        $this->actAsStaff(['tally-sync.view']);
        $data = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->json('data');

        $this->assertSame('Interstate Sales Taxable', $data['mappings']['sales_ledger']['name']);
        $this->assertSame('unmapped', $data['mappings']['sales_ledger']['state']);
        $this->assertStringContainsString('Interstate Sales Taxable', $data['mappings']['sales_ledger']['note']);
    }

    // ---- a Journal: ledgers ------------------------------------------------

    public function test_show_of_a_journal_maps_its_ledgers_by_name_only(): void
    {
        $entry = app(TallySyncService::class)->enqueueJournalEntry($this->journal());

        $this->actAsStaff(['tally-sync.view']);
        $data = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->json('data');

        $this->assertSame([], $data['mappings']['lines'], 'A Journal moves no stock');
        $this->assertNull($data['mappings']['party']);
        $this->assertNull($data['mappings']['sales_ledger']);

        $ledgers = $data['mappings']['ledgers'];
        $this->assertCount(2, $ledgers);
        $this->assertSame(['4000 - Sales', '1200 - Debtors'], array_column($ledgers, 'name'));
        $this->assertSame(['name_only', 'name_only'], array_column($ledgers, 'state'));
        // The GL account is the ERP's own chart; it is not linked to a
        // Tally ledger, and the note must say so rather than imply a match.
        $this->assertStringContainsString('no link', $ledgers[0]['note']);

        $this->assertSame(
            ['identity' => 0, 'name_only' => 2, 'unmapped' => 0, 'fixture' => 0, 'ambiguous' => 0],
            $data['mapping_summary'],
        );
    }

    // ---- the list, the actions and the agent do NOT carry it ----------------

    public function test_the_list_and_the_action_responses_do_not_carry_mappings(): void
    {
        $grn = $this->enqueueGoodsReceipt();
        $this->actAsStaff(['tally-sync.view', 'tally-sync.manage']);

        $row = collect($this->getJson('/api/v1/tally-sync/entries')->assertOk()->json('data'))->firstWhere('id', $grn->id);
        $this->assertArrayNotHasKey('mappings', $row);
        $this->assertArrayNotHasKey('mapping_summary', $row);

        $failed = TallySyncEntry::create([
            'syncable_type' => 'shift_production_entry', 'syncable_id' => 1,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => 'SPE-2'],
            'status' => TallySyncStatus::Failed, 'error_message' => 'x', 'attempts' => 1,
        ]);
        $retried = $this->postJson("/api/v1/tally-sync/entries/{$failed->id}/retry")->assertOk()->json('data');
        $this->assertArrayNotHasKey('mappings', $retried);
        $this->assertArrayNotHasKey('mapping_summary', $retried);
    }

    // ---- FC-06: mappings for a non-finance reader, and no rate anywhere ------

    public function test_a_reader_without_finance_sees_the_mapping_state_and_no_rate_at_any_depth(): void
    {
        Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'itm-resin']);
        $grn = $this->enqueueGoodsReceipt();
        $this->assertSame('85.0000', $grn->payload['lines'][0]['rate']);

        $this->actAsStaff(['tally-sync.view']);
        $shown = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json();

        $this->assertSame('identity', $shown['data']['mappings']['lines'][0]['item']['state'], 'the mapping state is visible to a non-finance reader');
        $this->assertSame([], $this->rateKeyPaths($shown), 'show leaked a rate key');
        // FC-06's second half: the party row of a Receipt Note is the vendor
        // — kept, emptied and explained for this reader, never the name.
        $this->assertSame(
            ['name' => null, 'state' => 'withheld', 'note' => 'The supplier on this voucher is withheld: supplier identity is Owner/Accounts only (FC-06).'],
            $shown['data']['mappings']['party'],
        );

        // And a finance reader gets the same LINE states — the gate is on
        // the rates and the supplier, not on how a name resolved — plus the
        // vendor row itself.
        $this->actAsStaff(['tally-sync.view', 'finance.view']);
        $finance = $this->getJson("/api/v1/tally-sync/entries/{$grn->id}")->assertOk()->json('data');
        $this->assertSame($shown['data']['mappings']['lines'], $finance['mappings']['lines']);
        $this->assertSame('Reliance Industries', $finance['mappings']['party']['name']);
        $this->assertSame('name_only', $finance['mappings']['party']['state']);
        // The summary counts what each reader is shown: `withheld` is not a
        // counted state, so the vendor's name_only appears for finance only.
        $this->assertSame(0, $shown['data']['mapping_summary']['name_only']);
        $this->assertSame(1, $finance['mapping_summary']['name_only']);
    }

    // ---- helpers ------------------------------------------------------------

    /**
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
    private function actAsStaff(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** The GRN of SyncPayloadRateVisibilityTest, through the real enqueue. */
    private function enqueueGoodsReceipt(): TallySyncEntry
    {
        $po = new PurchaseOrder;
        $po->setRelation('vendor', new Vendor(['name' => 'Reliance Industries', 'gstin' => '27AAACR1234A1Z5', 'tally_ledger_name' => 'Reliance Industries']));

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '85.0000']);
        $line->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin', 'tally_stock_item_guid' => 'itm-resin']));
        $line->setRelation('scheduleAllocations', collect());

        $grn = $this->existing(new GoodsReceiptNote(['received_date' => '2026-08-04']), 7);
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', new Warehouse(['name' => 'RM Store', 'tally_guid' => 'gd-rm']));
        $grn->setRelation('purchaseOrder', $po);

        return app(TallySyncService::class)->enqueueGoodsReceiptNote($grn);
    }

    /**
     * The item and the party are REAL ROWS, and the GST masters are seeded,
     * because SalesVoucherPayload assembles the voucher from the customer's
     * Tally ledger name and state and the item's HSN — and stages NOTHING when
     * any of it is missing. The invoice itself stays in-memory: nothing here
     * needs it persisted.
     */
    private function invoice(): Invoice
    {
        $item = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $customer = Customer::create(['code' => 'CUST-INV-5', 'name' => 'Sri Aurobindo Beverages']);
        $this->seedSalesTallyMasterData();

        $line = new InvoiceLine(['quantity' => '1000.0000', 'unit_price' => '4.5000']);
        $line->setRelation('item', $item->fresh());
        $invoice = $this->existing(new Invoice(['invoice_date' => '2026-08-01']), 5);
        $invoice->setRelation('lines', collect([$line]));
        $invoice->setRelation('customer', $customer->fresh());

        return $invoice;
    }

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
