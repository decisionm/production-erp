<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\PurchaseOrderSent;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\TallySync\Exceptions\PurchaseOrderNotPostable;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\TallyLedgerMappingService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PO → Tally, STAGED, flag OFF (Phase 6, WS-C) — the DEC-20260812-002 proof,
 * cloud half: "the PO is an ORDER voucher; it must not touch accounts or
 * stock in Tally; stated in the code and proved by a test."
 *
 *   (i)   with the flag ON (config() inside the test ONLY — phpunit.xml pins
 *         it OFF and nothing else ever flips it), enqueuePurchaseOrder()
 *         writes exactly ONE tally_sync_entries row and changes NOTHING in
 *         stock_movements, stock_balances, material_lots, the journal
 *         tables or the GRN tables — counted before and after;
 *   (ii)  the payload names only RECORDED Tally identities — the vendor's
 *         ledger, the mapped purchase ledger, Tally-sourced item names, the
 *         one Tally godown — and an unmapped vendor / item / purchase ledger
 *         / godown, or an order with no lines, is REFUSED with the named
 *         reason(s) and no entry (never a default, never a guess);
 *   (iii) with the flag OFF, the send event stages nothing (the listener
 *         records 'disabled'), and a direct enqueue refuses too;
 *   (iv)  the generic replay guard holds: enqueue twice → one row;
 *   (v)   FC-06 on the entry: a tally-sync.view-only reader sees
 *         party_withheld and no party_ledger / party_gstin / rate / amount /
 *         total_amount — schedule amounts included; finance and the agent
 *         see the payload whole;
 *   (vi)  the entry classifies as purchase_order (not Unknown), and the
 *         summary / mappings / flags surfaces describe it.
 *
 * Fixture values are SYNTHETIC on purpose (FC-06): "Vendor Alpha", "ITEM_A",
 * rate 1.0000, a made-up ledger name. No real rate, vendor, GSTIN, Tally
 * item name or voucher number appears here.
 *
 * vendors.tally_ledger_name and purchase_orders.tally_staging are the
 * additive columns of migration 2026_08_18_100001, and
 * PurchaseOrderService::recordTallyStaging() is their one writer. Both have
 * landed, so this file asserts them outright: a column or a method that
 * went missing FAILS here rather than being tiptoed around.
 *
 * (vii) the order's OWN END: cancelled or short-closed. A staged voucher
 * the agent has not collected is WITHDRAWN (Dismissed through the same
 * write-off dismiss() performs, with the reason on the history row) and the
 * order records state 'dismissed'; one the agent already holds is left
 * alone and the order only notes `after`. What Tally should be told about
 * an order it already received is owner question Q48 — not built.
 *
 * TWO ROADS INTO THE QUEUE ARE TESTED APART. The pure enqueue contract
 * (enqueuePurchaseOrder on an order already Sent — sentOrder() sets the
 * status directly for that reason, so the send() event does not run first)
 * and the real road (PurchaseOrderService::send() → PurchaseOrderSent after
 * commit → the TallySync listener → tally_staging on the order).
 */
class PurchaseOrderTallyStagingTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT_ABILITIES = ['tally-sync:poll', 'tally-sync:report', 'tally-sync:masters'];

    private const VENDOR = 'Vendor Alpha';

    private const VENDOR_LEDGER = 'Vendor Alpha (Sundry Creditor)';

    private const VENDOR_GSTIN = '00AAAAA0000A0Z0';

    private const PURCHASE_LEDGER = 'Purchase Ledger Alpha';

    private const GODOWN = 'Godown Alpha';

    /** Every table the enqueue must leave untouched (DEC-20260812-002 (i)). */
    private const UNTOUCHED_TABLES = [
        'stock_movements', 'stock_balances', 'material_lots',
        'journal_entries', 'journal_entry_lines',
        'goods_receipt_notes', 'goods_receipt_note_lines',
        'purchase_orders', 'purchase_order_lines', 'purchase_order_schedules',
    ];

    private Item $itemA;

    private Item $itemB;

    private Vendor $vendor;

    private Warehouse $godown;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itemA = Item::create(['sku' => 'ITEM-A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'itm-a']);
        $this->itemB = Item::create(['sku' => 'ITEM-B', 'name' => 'ITEM_B', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'itm-b']);
        $this->godown = Warehouse::create(['code' => 'GA', 'name' => self::GODOWN, 'tally_guid' => 'gd-alpha']);
        $this->vendor = Vendor::create(['code' => 'VA', 'name' => self::VENDOR, 'gstin' => self::VENDOR_GSTIN]);
        Vendor::query()->whereKey($this->vendor->id)->update(['tally_ledger_name' => self::VENDOR_LEDGER]);
        $this->vendor->refresh();

        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Purchase->value => self::PURCHASE_LEDGER]);
    }

    // ---- (i) one row, nothing else ------------------------------------------

    public function test_with_the_flag_on_enqueue_writes_one_entry_and_touches_no_stock_lot_or_journal_table(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->sentOrder([
            ['item' => $this->itemA, 'quantity' => '100.0000', 'rate' => '1.0000', 'schedules' => [
                ['due_date' => '2026-09-01', 'quantity' => '60.0000'],
                ['due_date' => '2026-09-15', 'quantity' => '40.0000'],
            ]],
            ['item' => $this->itemB, 'quantity' => '25.0000', 'rate' => '2.0000', 'schedules' => []],
        ], expectedDate: '2026-09-30', notes: 'September resin');

        $before = $this->tableCounts();
        $this->assertSame(0, TallySyncEntry::query()->count());

        $entry = app(TallySyncService::class)->enqueuePurchaseOrder($po);

        $this->assertSame($before, $this->tableCounts(), 'An ORDER voucher moves nothing: every non-queue table is exactly as it was');
        $this->assertSame(1, TallySyncEntry::query()->count(), 'exactly one tally_sync_entries row');
        $this->assertSame(1, TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherEnqueued->value)->count());

        $this->assertSame('Purchase Order', $entry->tally_voucher_type);
        $this->assertSame((new PurchaseOrder)->getMorphClass(), $entry->syncable_type);
        $this->assertSame($po->id, $entry->syncable_id);
        $this->assertSame('pending', $entry->status->value);

        $payload = $entry->payload;
        $this->assertSame([
            'voucher_type', 'voucher_date', 'voucher_number', 'voucher_number_source', 'party_ledger', 'party_gstin',
            'purchase_ledger', 'godown', 'reference', 'narration', 'lines', 'total_amount',
        ], array_keys($payload));
        $this->assertSame('Purchase Order', $payload['voucher_type']);
        $this->assertSame('2026-08-20', $payload['voucher_date']);
        $this->assertSame("PO-{$po->id}", $payload['voucher_number'], 'the ERP reference, like GRN-{id}; Q35(c) pending');
        $this->assertSame('erp', $payload['voucher_number_source']);
        $this->assertSame(self::VENDOR_LEDGER, $payload['party_ledger'], 'the vendor\'s recorded Tally ledger — never the raw name');
        $this->assertSame(self::VENDOR_GSTIN, $payload['party_gstin']);
        $this->assertSame(self::PURCHASE_LEDGER, $payload['purchase_ledger'], 'TallyLedgerRole::Purchase via the mappings');
        $this->assertSame(self::GODOWN, $payload['godown'], 'the one Tally-linked godown');
        $this->assertSame("PO-{$po->id}", $payload['reference']);
        $this->assertSame('September resin', $payload['narration']);
        $this->assertSame('150.0000', $payload['total_amount']);

        $this->assertCount(2, $payload['lines']);
        $this->assertSame([
            'item' => 'ITEM_A', 'quantity' => '100.0000', 'rate' => '1.0000', 'amount' => '100.0000',
            'schedules' => [
                ['due_date' => '2026-09-01', 'quantity' => '60.0000', 'amount' => '60.0000'],
                ['due_date' => '2026-09-15', 'quantity' => '40.0000', 'amount' => '40.0000'],
            ],
        ], $payload['lines'][0]);
        // No schedule → ONE allocation for the whole line on the order's
        // expected date (Tally's ORDERDUEDATE mirror), never an invented one.
        $this->assertSame([
            'item' => 'ITEM_B', 'quantity' => '25.0000', 'rate' => '2.0000', 'amount' => '50.0000',
            'schedules' => [['due_date' => '2026-09-30', 'quantity' => '25.0000', 'amount' => '50.0000']],
        ], $payload['lines'][1]);
        // No unit anywhere (Q40): the ERP cannot vouch that Item.uom is the
        // Tally symbol, so the agent emits bare decimals.
        $this->assertArrayNotHasKey('unit', $payload['lines'][0]);
    }

    public function test_a_line_without_schedule_on_an_order_without_expected_date_carries_no_allocation(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);

        $entry = app(TallySyncService::class)->enqueuePurchaseOrder($po);

        $this->assertSame([], $entry->payload['lines'][0]['schedules'], 'no due date is invented — the agent emits no ORDERDUEDATE');
    }

    public function test_allocation_amounts_always_sum_to_the_line_the_last_taking_the_remainder(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        // 3 × 33.3333 at 1.0000 → 33.3333 each would sum to 99.9999 against a
        // 100.0000 line; the last allocation absorbs the 0.0001.
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '100.0000', 'rate' => '1.0000', 'schedules' => [
            ['due_date' => '2026-09-01', 'quantity' => '33.3333'],
            ['due_date' => '2026-09-08', 'quantity' => '33.3333'],
            ['due_date' => '2026-09-15', 'quantity' => '33.3334'],
        ]]]);

        $line = app(TallySyncService::class)->enqueuePurchaseOrder($po)->payload['lines'][0];

        $this->assertSame(['33.3333', '33.3333', '33.3334'], array_column($line['schedules'], 'amount'));
        $this->assertSame($line['amount'], array_reduce($line['schedules'], fn ($c, $s) => bcadd($c, $s['amount'], 4), '0.0000'));
    }

    /**
     * An UNDER-SCHEDULED line — the schedules promise less than the line —
     * gets one allocation per schedule at ITS OWN quantity × rate, plus ONE
     * remainder allocation for what no schedule dated: quantity = line − Σ,
     * amount = line amount − Σ (rounding lands on the remainder), due on
     * the order's expected_date when there is one, else undated (the agent
     * emits no ORDERDUEDATE — never a made-up date). Quantities AND amounts
     * sum to the line; the schedule's own figures are never inflated to
     * absorb what it did not promise.
     */
    public function test_an_under_scheduled_line_gets_a_remainder_allocation_and_quantities_and_amounts_sum_to_the_line(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        // 100 @ 1 with one schedule of 60 → [60/60, 40/40]; the order has an
        // expected date, so the remainder is dated with it.
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '100.0000', 'rate' => '1.0000', 'schedules' => [
            ['due_date' => '2026-09-01', 'quantity' => '60.0000'],
        ]]], expectedDate: '2026-09-30');

        $line = app(TallySyncService::class)->enqueuePurchaseOrder($po)->payload['lines'][0];

        $this->assertSame([
            ['due_date' => '2026-09-01', 'quantity' => '60.0000', 'amount' => '60.0000'],
            ['due_date' => '2026-09-30', 'quantity' => '40.0000', 'amount' => '40.0000'],
        ], $line['schedules']);
        $this->assertAllocationsSumToTheLine($line);

        // 100 @ 2 with [30, 50] → [30/60, 50/100, 20/40]; no expected date, so
        // the remainder carries no due date at all.
        $po = $this->sentOrder([['item' => $this->itemB, 'quantity' => '100.0000', 'rate' => '2.0000', 'schedules' => [
            ['due_date' => '2026-09-01', 'quantity' => '30.0000'],
            ['due_date' => '2026-09-15', 'quantity' => '50.0000'],
        ]]]);

        $entry = app(TallySyncService::class)->enqueuePurchaseOrder($po);
        $line = $entry->payload['lines'][0];

        $this->assertSame([
            ['due_date' => '2026-09-01', 'quantity' => '30.0000', 'amount' => '60.0000'],
            ['due_date' => '2026-09-15', 'quantity' => '50.0000', 'amount' => '100.0000'],
            ['due_date' => null, 'quantity' => '20.0000', 'amount' => '40.0000'],
        ], $line['schedules']);
        $this->assertAllocationsSumToTheLine($line);

        // The queue's summary line shows the undated remainder too, so the
        // quantities it lists add up to the line — never an amount.
        $this->actAsStaff(['tally-sync.view', 'finance.view']);
        $summary = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->json('data.summary.lines');
        $this->assertSame(['ITEM_B × 100 → '.self::GODOWN.' · due 01-Sep-2026 × 30, 15-Sep-2026 × 50, undated × 20'], $summary);
    }

    public function test_the_remainder_absorbs_the_rounding_when_the_schedules_do_not_multiply_out_exactly(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        // 100 @ 1.5 with one schedule of 33.3333: 33.3333 × 1.5 = 49.99995 →
        // 49.9999 at 4 dp; the remainder (66.6667) takes 150.0000 − 49.9999 =
        // 100.0001, so the two still sum to the line to the paisa.
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '100.0000', 'rate' => '1.5000', 'schedules' => [
            ['due_date' => '2026-09-01', 'quantity' => '33.3333'],
        ]]]);

        $line = app(TallySyncService::class)->enqueuePurchaseOrder($po)->payload['lines'][0];

        $this->assertSame('150.0000', $line['amount']);
        $this->assertSame([
            ['due_date' => '2026-09-01', 'quantity' => '33.3333', 'amount' => '49.9999'],
            ['due_date' => null, 'quantity' => '66.6667', 'amount' => '100.0001'],
        ], $line['schedules']);
        $this->assertAllocationsSumToTheLine($line);
    }

    public function test_an_exactly_covered_line_and_an_unscheduled_line_are_unchanged_by_the_remainder_rule(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->sentOrder([
            ['item' => $this->itemA, 'quantity' => '100.0000', 'rate' => '1.0000', 'schedules' => [
                ['due_date' => '2026-09-01', 'quantity' => '60.0000'],
                ['due_date' => '2026-09-15', 'quantity' => '40.0000'],
            ]],
            ['item' => $this->itemB, 'quantity' => '25.0000', 'rate' => '2.0000', 'schedules' => []],
        ], expectedDate: '2026-09-30');

        $lines = app(TallySyncService::class)->enqueuePurchaseOrder($po)->payload['lines'];

        // Exact cover: one allocation per schedule, no remainder row.
        $this->assertSame([
            ['due_date' => '2026-09-01', 'quantity' => '60.0000', 'amount' => '60.0000'],
            ['due_date' => '2026-09-15', 'quantity' => '40.0000', 'amount' => '40.0000'],
        ], $lines[0]['schedules']);
        // Unscheduled: ONE allocation for the whole line on the expected date.
        $this->assertSame([
            ['due_date' => '2026-09-30', 'quantity' => '25.0000', 'amount' => '50.0000'],
        ], $lines[1]['schedules']);
        foreach ($lines as $line) {
            $this->assertAllocationsSumToTheLine($line);
        }
    }

    // ---- (iv) idempotent ------------------------------------------------------

    public function test_enqueueing_the_same_order_twice_leaves_one_row(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
        $service = app(TallySyncService::class);

        $first = $service->enqueuePurchaseOrder($po);
        $second = $service->enqueuePurchaseOrder($po);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TallySyncEntry::query()->count());
        $this->assertSame(1, TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherEnqueued->value)->count());
    }

    // ---- (iii) the flag ---------------------------------------------------------

    public function test_the_flag_defaults_off_and_is_pinned_off_for_the_suite(): void
    {
        $this->assertFalse(config('tally-sync.purchase_orders_enabled'));
    }

    public function test_with_the_flag_off_a_direct_enqueue_refuses_and_writes_nothing(): void
    {
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);

        try {
            app(TallySyncService::class)->enqueuePurchaseOrder($po);
            $this->fail('expected PurchaseOrderNotPostable');
        } catch (PurchaseOrderNotPostable $refusal) {
            $this->assertSame(['purchase_orders_disabled'], $refusal->codes());
        }

        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertSame(0, TallySyncEvent::query()->count());
    }

    public function test_with_the_flag_off_the_real_send_stages_nothing_and_records_disabled(): void
    {
        $po = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
        $before = $this->tableCounts();

        app(PurchaseOrderService::class)->send($po);

        $this->assertSame(PurchaseOrderStatus::Sent, $po->fresh()->status, 'the order IS sent — Tally staging never blocks it');
        $this->assertSame(0, TallySyncEntry::query()->count(), 'flag off: nothing enqueued');
        $this->assertSame(0, TallySyncEvent::query()->count());
        $this->assertSame(array_diff_key($before, ['purchase_orders' => 0]), array_diff_key($this->tableCounts(), ['purchase_orders' => 0]));
        $this->assertStaging($po, 'disabled', ['purchase_orders_disabled']);
    }

    public function test_with_the_flag_on_the_real_send_enqueues_once_and_records_enqueued(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);

        app(PurchaseOrderService::class)->send($po);
        // A replayed event does not mint a second voucher.
        event(new PurchaseOrderSent($po->fresh()));

        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Purchase Order', $entry->tally_voucher_type);
        $this->assertSame($po->id, $entry->syncable_id);
        $this->assertStaging($po, 'enqueued', [], $entry->id);
    }

    // ---- (ii) refusals: never a guess ------------------------------------------

    public function test_a_vendor_without_a_tally_ledger_is_refused_party_unmapped(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        Vendor::query()->whereKey($this->vendor->id)->update(['tally_ledger_name' => null]);
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);

        $this->assertRefused($po, ['party_unmapped']);
    }

    public function test_an_item_without_tally_identity_is_refused_item_unmapped_naming_the_item(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $local = Item::create(['sku' => 'ITEM-C', 'name' => 'ITEM_C', 'uom' => 'Kgs']);
        $po = $this->sentOrder([
            ['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []],
            ['item' => $local, 'quantity' => '5.0000', 'rate' => '1.0000', 'schedules' => []],
        ]);

        $refusal = $this->assertRefused($po, ['item_unmapped']);
        $this->assertStringContainsString("item #{$local->id} 'ITEM_C'", $refusal->reasons[0]['detail']);
    }

    public function test_a_local_fixture_item_is_refused_even_with_a_guid(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $fixture = Item::create(['sku' => 'LOCAL-X', 'name' => 'LOCAL_X', 'uom' => 'Nos', 'is_local_fixture' => true, 'tally_stock_item_guid' => 'itm-x']);
        $po = $this->sentOrder([['item' => $fixture, 'quantity' => '5.0000', 'rate' => '1.0000', 'schedules' => []]]);

        $this->assertRefused($po, ['item_unmapped']);
    }

    public function test_an_unmapped_purchase_ledger_is_refused_never_defaulted(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Purchase->value => '']);
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);

        $refusal = $this->assertRefused($po, ['purchase_ledger_unmapped']);
        $this->assertStringNotContainsString('Purchase Ledger', $refusal->getMessage(), 'no hardcoded ledger name stands in');
    }

    public function test_an_order_with_no_lines_is_refused(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->sentOrder([]);

        $this->assertRefused($po, ['no_lines']);
    }

    public function test_without_a_single_tally_godown_the_order_is_refused_godown_unresolved(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        // Two Tally-linked warehouses: nothing to choose by, so nothing is chosen.
        Warehouse::create(['code' => 'GB', 'name' => 'Godown Beta', 'tally_guid' => 'gd-beta']);
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);

        $this->assertRefused($po, ['godown_unresolved']);
    }

    public function test_every_reason_is_collected_before_refusing(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        Vendor::query()->whereKey($this->vendor->id)->update(['tally_ledger_name' => null]);
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Purchase->value => null]);
        $local = Item::create(['sku' => 'ITEM-C', 'name' => 'ITEM_C', 'uom' => 'Kgs']);
        $po = $this->sentOrder([['item' => $local, 'quantity' => '5.0000', 'rate' => '1.0000', 'schedules' => []]]);

        $refusal = $this->assertRefused($po, ['party_unmapped', 'purchase_ledger_unmapped', 'item_unmapped']);
        // FC-06 on the reasons themselves: shown to whoever reads the order,
        // so no vendor name, no GSTIN, no rate.
        foreach ([self::VENDOR, self::VENDOR_GSTIN, '1.0000'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($refusal->reasons));
        }
    }

    public function test_the_listener_records_a_refusal_on_the_order_and_never_throws_out_of_the_event(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        Vendor::query()->whereKey($this->vendor->id)->update(['tally_ledger_name' => null]);
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);

        event(new PurchaseOrderSent($po)); // the listener body — must not throw

        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertStaging($po, 'refused', ['party_unmapped']);

        // And through the real road: a Draft sent with the vendor unmapped is
        // still Sent, and its refusal is on the order.
        $draft = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
        app(PurchaseOrderService::class)->send($draft);
        $this->assertSame(PurchaseOrderStatus::Sent, $draft->fresh()->status);
        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertStaging($draft, 'refused', ['party_unmapped']);
    }

    // ---- (v) FC-06 on the entry ---------------------------------------------------

    public function test_a_tally_sync_only_reader_sees_neither_supplier_nor_rate_on_the_staged_entry(): void
    {
        $entry = $this->stagedEntry();

        $this->actAsStaff(['tally-sync.view', 'tally-sync.manage']);

        $showUrl = "/api/v1/tally-sync/entries/{$entry->id}";
        foreach (['/api/v1/tally-sync/entries', $showUrl] as $url) {
            $response = $this->getJson($url)->assertOk();
            $raw = $response->getContent();
            $data = $url === $showUrl ? $response->json('data') : collect($response->json('data'))->firstWhere('id', $entry->id);

            $this->assertNotNull($data);
            $this->assertNull($data['party']);
            $this->assertStringContainsString('FC-06', $data['party_withheld']);
            $this->assertArrayNotHasKey('party_ledger', $data['payload']);
            $this->assertArrayNotHasKey('party_gstin', $data['payload']);
            $this->assertArrayNotHasKey('total_amount', $data['payload']);
            foreach ($data['payload']['lines'] as $line) {
                $this->assertArrayNotHasKey('rate', $line);
                $this->assertArrayNotHasKey('amount', $line);
                foreach ($line['schedules'] as $schedule) {
                    $this->assertArrayNotHasKey('amount', $schedule, 'a schedule\'s share of the amount is the rate by another road');
                    $this->assertArrayHasKey('due_date', $schedule);
                }
            }
            $this->assertSame("PO-{$entry->syncable_id}", $data['payload']['voucher_number']);
            $this->assertSame(self::GODOWN, $data['payload']['godown']);
            $this->assertSame(self::PURCHASE_LEDGER, $data['payload']['purchase_ledger'], 'the purchase ledger is the accountant\'s ledger, not the supplier');
            // The rate, the line amount, each schedule's amount, the total —
            // none of them may be on the wire for this reader (FC-06).
            foreach ([self::VENDOR, self::VENDOR_LEDGER, self::VENDOR_GSTIN, '"3.0000"', '"300.0000"', '"180.0000"', '"120.0000"'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, "{$secret} leaked on {$url}");
            }
        }

        // The Phase 3 surfaces: headline without the party, mapping row withheld.
        $show = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->json('data');
        $this->assertStringStartsWith("Purchase Order PO-{$entry->syncable_id} · 20-Aug-2026 · 1 item × 100 · waiting for agent", $show['summary']['headline']);
        $this->assertSame('withheld', $show['mappings']['party']['state']);
        $this->assertNull($show['mappings']['party']['name']);

        // And no yes/no oracle through the search: ?q=<vendor ledger> finds
        // nothing for this reader (the generic supplier-party guard covers
        // the new category), while the document number still does.
        $this->getJson('/api/v1/tally-sync/entries?q=Vendor+Alpha')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/tally-sync/entries?q=PO-{$entry->syncable_id}")->assertOk()->assertJsonPath('data.0.id', $entry->id);
    }

    public function test_finance_and_the_agent_read_the_staged_payload_whole(): void
    {
        $entry = $this->stagedEntry();

        $this->actAsStaff(['tally-sync.view', 'finance.view']);
        $data = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->json('data');
        $this->assertSame(self::VENDOR_LEDGER, $data['party']);
        $this->assertSame($entry->fresh()->payload, $data['payload'], 'finance reads the stored payload byte for byte');
        $this->assertSame(self::VENDOR_LEDGER, $data['mappings']['party']['name']);
        $this->assertSame(self::PURCHASE_LEDGER, $data['mappings']['purchase_ledger']['name']);
        $this->assertSame('identity', $data['mappings']['purchase_ledger']['state']);
        $this->assertStringContainsString(self::VENDOR_LEDGER, $data['summary']['headline']);

        // The line mappings work off the same keys a Receipt Note line has
        // (item at line level, godown at voucher level): the Tally-sourced
        // item and the one Tally godown both resolve by identity; the party
        // is a NAME Accounts typed (vendors.tally_ledger_name), so name_only
        // is the honest state; the purchase ledger is the mapped role.
        $line = $data['mappings']['lines'][0];
        $this->assertSame('lines', $line['side']);
        $this->assertSame('ITEM_A', $line['item']['name']);
        $this->assertSame('identity', $line['item']['state']);
        $this->assertSame('itm-a', $line['item']['tally_stock_item_guid']);
        $this->assertSame(self::GODOWN, $line['godown']['name']);
        $this->assertSame('identity', $line['godown']['state']);
        $this->assertSame('name_only', $data['mappings']['party']['state']);
        $this->assertSame([], $data['mappings']['ledgers'], 'a Purchase Order posts no journal ledger lines');
        $this->assertNull($data['mappings']['sales_ledger']);
        $this->assertSame(
            ['identity' => 3, 'name_only' => 1, 'unmapped' => 0, 'fixture' => 0, 'ambiguous' => 0],
            $data['mapping_summary'],
        );
        $this->app['auth']->forgetGuards();

        [, $token] = $this->agent('factory-pc');
        $pending = collect($this->withToken($token)->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))
            ->firstWhere('id', $entry->id);
        $this->assertNotNull($pending, 'a staged Purchase Order is handed to the agent like any pending voucher');
        $this->assertSame($entry->fresh()->payload, $pending['payload'], 'the agent builds the voucher from the whole payload');
    }

    // ---- (vi) classified, described ----------------------------------------------

    public function test_the_staged_entry_classifies_as_purchase_order_and_is_described_as_an_order(): void
    {
        $entry = $this->stagedEntry();

        $this->actAsStaff(['tally-sync.view', 'finance.view']);
        $data = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->json('data');

        $this->assertSame('purchase_order', $data['category']['key']);
        $this->assertSame('Purchase Order', $data['category']['wire_voucher_type']);
        $this->assertSame('erp', $data['category']['source']);
        $this->assertSame('built', $data['category']['erp_build']);
        $this->assertSame('erp_to_tally', $data['category']['direction']);
        $this->assertSame('procurement', $data['category']['source_module']);
        $this->assertSame('2026-08-20', $data['business_date']);
        $this->assertSame("PO-{$entry->syncable_id}", $data['document_number']);
        $this->assertSame(['first' => 'ITEM_A', 'count' => 1], $data['item_summary']);

        // Summary lines: item × quantity → godown, then the due dates — never an amount.
        $this->assertSame(['ITEM_A × 100 → '.self::GODOWN.' · due 01-Sep-2026 × 60, 15-Sep-2026 × 40'], $data['summary']['lines']);

        // The honesty flag, in the builder's own words: staged, never yet posted.
        $this->assertArrayHasKey('unvalidated_builder', $data['flags']);
        $this->assertSame('tally-sync-agent/src/tally/voucherBuilders/purchaseOrder.ts', $data['flags']['unvalidated_builder']['builder']);
        $this->assertStringContainsString('NOT YET POSTED TO A REAL TALLY', $data['flags']['unvalidated_builder']['note']);
        $this->assertSame('DEC-20260812-002', $data['flags']['unvalidated_builder']['decision']);

        // The catalogue and the filter agree with the row.
        $summary = collect($this->getJson('/api/v1/tally-sync/summary')->assertOk()->json('data.by_category'))->firstWhere('key', 'purchase_order');
        $this->assertSame(1, $summary['count'], 'a staged row is measured — an honest count, not a null');
        $ids = collect($this->getJson('/api/v1/tally-sync/entries?category[]=purchase_order')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$entry->id], $ids);
    }

    // ---- (vii) cancelled / closed AFTER send: the ERP's own queue row ---------------

    /**
     * A staged order the ERP then cancels BEFORE the agent collected it: the
     * queue row is the ERP's own (never handed out), so the ERP withdraws it
     * — status Dismissed through the existing write-off path, a
     * voucher.dismissed event with the reason — and the order says so:
     * tally_staging {state: 'dismissed', reasons: [cancelled_before_delivery],
     * entry_id}. Nothing else changes; Tally never saw the voucher.
     */
    public function test_cancel_before_the_agent_collected_it_dismisses_the_staged_entry_and_records_it_on_the_order(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
        app(PurchaseOrderService::class)->send($po);
        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('pending', $entry->status->value);
        $this->assertNull($entry->delivered_at);
        $before = $this->tableCounts();

        app(PurchaseOrderService::class)->cancel($po->fresh(), 'vendor cannot supply', null);

        $entry->refresh();
        $this->assertSame('dismissed', $entry->status->value, 'the uncollected row is withdrawn');
        $this->assertNull($entry->delivered_at);
        $this->assertNull($entry->synced_at);
        $this->assertSame(1, TallySyncEntry::query()->count(), 'no second row minted');
        $this->assertSame(array_diff_key($before, ['purchase_orders' => 0]), array_diff_key($this->tableCounts(), ['purchase_orders' => 0]));

        // The write-off is on the durable record in the shape dismiss() writes.
        $dismissed = TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherDismissed->value)->sole();
        $this->assertSame($entry->id, $dismissed->tally_sync_entry_id);
        $this->assertSame('cancelled_before_delivery', $dismissed->details['reason']);
        $log = $entry->payload['resolution_log'];
        $this->assertCount(1, $log);
        $this->assertStringContainsString('cancelled', $log[0]['note']);

        // And the order says so, in the staging shape the frontend words.
        $this->assertStaging($po, 'dismissed', ['cancelled_before_delivery'], $entry->id);
        $staging = $po->fresh()->tally_staging;
        $this->assertArrayNotHasKey('after', $staging);
        $this->assertStringContainsString('before the agent collected it', $staging['reasons'][0]['detail']);
        $this->assertSame(PurchaseOrderStatus::Cancelled, $po->fresh()->status);

        // The order's tally link now reads dismissed too (TallySyncLinkService).
        $this->actAsStaff(['procurement.view']);
        $this->getJson("/api/v1/procurement/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.tally.status', 'dismissed')
            ->assertJsonPath('data.tally_staging.state', 'dismissed')
            ->assertJsonPath('data.tally_staging.reasons.0.code', 'cancelled_before_delivery');
    }

    public function test_close_before_the_agent_collected_it_dismisses_the_staged_entry_with_its_own_reason(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
        app(PurchaseOrderService::class)->send($po);
        $entry = TallySyncEntry::query()->sole();

        app(PurchaseOrderService::class)->close($po->fresh(), 'short-closed, nothing more coming', null);

        $this->assertSame('dismissed', $entry->fresh()->status->value);
        $this->assertSame('closed_before_delivery', TallySyncEvent::query()->where('event', TallySyncEventKind::VoucherDismissed->value)->sole()->details['reason']);
        $this->assertStaging($po, 'dismissed', ['closed_before_delivery'], $entry->id);
        $this->assertSame(PurchaseOrderStatus::Closed, $po->fresh()->status);
    }

    /**
     * Once the agent HAS collected the row (delivered_at set — or it synced,
     * or failed) the ERP no longer owns its fate: the entry is left exactly
     * as it is, and the order records `after: cancelled_after_delivery` on
     * its unchanged staging — the Tally side is the owner's question.
     */
    public function test_cancel_after_the_agent_collected_it_leaves_the_entry_and_records_after_on_the_order(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
        app(PurchaseOrderService::class)->send($po);
        $entry = TallySyncEntry::query()->sole();
        // As if the agent's poll had handed it out (fixture write; the poll
        // itself is VoucherPostedOnceTest's subject).
        TallySyncEntry::query()->whereKey($entry->id)->update(['delivered_at' => now()->subMinute()]);
        $eventsBefore = TallySyncEvent::query()->count();

        app(PurchaseOrderService::class)->cancel($po->fresh(), 'vendor cannot supply', null);

        $entry->refresh();
        $this->assertSame('pending', $entry->status->value, 'a collected voucher is not withdrawn under the agent');
        $this->assertNotNull($entry->delivered_at);
        $this->assertArrayNotHasKey('resolution_log', $entry->payload);
        $this->assertSame($eventsBefore, TallySyncEvent::query()->count(), 'no dismissal recorded');

        $staging = $po->fresh()->tally_staging;
        $this->assertSame('enqueued', $staging['state'], 'the staging state is unchanged');
        $this->assertSame([], $staging['reasons']);
        $this->assertSame($entry->id, $staging['entry_id']);
        $this->assertSame('cancelled_after_delivery', $staging['after']);
        $this->assertSame(PurchaseOrderStatus::Cancelled, $po->fresh()->status);
    }

    public function test_close_after_the_voucher_synced_or_failed_leaves_the_entry_and_records_after(): void
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        foreach (['synced' => ['status' => 'synced', 'synced_at' => now()], 'failed' => ['status' => 'failed', 'error_message' => 'rejected']] as $case => $columns) {
            $po = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
            app(PurchaseOrderService::class)->send($po);
            $entry = TallySyncEntry::query()->where('syncable_id', $po->id)->sole();
            TallySyncEntry::query()->whereKey($entry->id)->update(['delivered_at' => now()->subMinute(), ...$columns]);
            $eventsBefore = TallySyncEvent::query()->count();

            app(PurchaseOrderService::class)->close($po->fresh(), 'short-closed', null);

            $this->assertSame($case, $entry->fresh()->status->value, "a {$case} voucher is left as it is");
            $this->assertSame($eventsBefore, TallySyncEvent::query()->count());
            $staging = $po->fresh()->tally_staging;
            $this->assertSame('enqueued', $staging['state']);
            $this->assertSame($entry->id, $staging['entry_id']);
            $this->assertSame('closed_after_delivery', $staging['after']);
        }
    }

    public function test_with_the_flag_off_cancel_and_close_record_nothing_new_and_the_staging_stays_disabled(): void
    {
        $cancelled = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
        $closed = $this->draftOrder([['item' => $this->itemA, 'quantity' => '10.0000', 'rate' => '1.0000', 'schedules' => []]]);
        app(PurchaseOrderService::class)->send($cancelled);
        app(PurchaseOrderService::class)->send($closed);
        $this->assertStaging($cancelled, 'disabled', ['purchase_orders_disabled']);
        $stagingBefore = [$cancelled->fresh()->tally_staging, $closed->fresh()->tally_staging];

        app(PurchaseOrderService::class)->cancel($cancelled->fresh(), 'never mind', null);
        app(PurchaseOrderService::class)->close($closed->fresh(), 'short-closed', null);

        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertSame(0, TallySyncEvent::query()->count());
        $this->assertSame($stagingBefore, [$cancelled->fresh()->tally_staging, $closed->fresh()->tally_staging], 'nothing new on the order');
        $this->assertArrayNotHasKey('after', $cancelled->fresh()->tally_staging);
        $this->assertSame(PurchaseOrderStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::Closed, $closed->fresh()->status);
    }

    // ---- helpers ------------------------------------------------------------------

    /**
     * A DRAFT purchase order with the given lines — through Procurement's
     * service (the real lines/schedules writer).
     *
     * @param  list<array{item: Item, quantity: string, rate: string, schedules: list<array{due_date: string, quantity: string}>}>  $lines
     */
    private function draftOrder(array $lines, ?string $expectedDate = null, ?string $notes = null): PurchaseOrder
    {
        if ($lines === []) {
            // create() needs at least one line; an empty order is a fixture
            // for the no_lines refusal only.
            return PurchaseOrder::create([
                'vendor_id' => $this->vendor->id, 'status' => PurchaseOrderStatus::Draft,
                'order_date' => '2026-08-20', 'expected_date' => $expectedDate, 'notes' => $notes, 'source' => 'erp',
            ])->fresh();
        }

        return app(PurchaseOrderService::class)->create([
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-20',
            'expected_date' => $expectedDate,
            'notes' => $notes,
            'lines' => array_map(fn (array $line) => [
                'item_id' => $line['item']->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['rate'],
                'schedules' => $line['schedules'],
            ], $lines),
        ], null)->fresh();
    }

    /**
     * The same order already SENT — status set directly (a fixture write,
     * said out loud) so that send()'s PurchaseOrderSent event does NOT run
     * the listener first: these tests call enqueuePurchaseOrder() themselves
     * to see the pure contract. The real send() road has its own tests above.
     *
     * @param  list<array{item: Item, quantity: string, rate: string, schedules: list<array{due_date: string, quantity: string}>}>  $lines
     */
    private function sentOrder(array $lines, ?string $expectedDate = null, ?string $notes = null): PurchaseOrder
    {
        $po = $this->draftOrder($lines, $expectedDate, $notes);
        PurchaseOrder::query()->whereKey($po->id)->update(['status' => PurchaseOrderStatus::Sent->value]);

        return $po->fresh();
    }

    /**
     * One staged (flag on, inside this helper only) two-schedule order, then
     * the flag back off. Rate 3.0000 on purpose: every amount (300 / 180 /
     * 120) is then a figure no quantity (100 / 60 / 40) shares, so a leak
     * test can tell "the amount showed" from "the quantity showed".
     */
    private function stagedEntry(): TallySyncEntry
    {
        config(['tally-sync.purchase_orders_enabled' => true]);
        $po = $this->sentOrder([['item' => $this->itemA, 'quantity' => '100.0000', 'rate' => '3.0000', 'schedules' => [
            ['due_date' => '2026-09-01', 'quantity' => '60.0000'],
            ['due_date' => '2026-09-15', 'quantity' => '40.0000'],
        ]]]);
        $entry = app(TallySyncService::class)->enqueuePurchaseOrder($po);
        config(['tally-sync.purchase_orders_enabled' => false]);

        return $entry;
    }

    /** @param  list<string>  $codes */
    private function assertRefused(PurchaseOrder $po, array $codes): PurchaseOrderNotPostable
    {
        $before = $this->tableCounts();

        try {
            app(TallySyncService::class)->enqueuePurchaseOrder($po);
        } catch (PurchaseOrderNotPostable $refusal) {
            $this->assertSame($codes, $refusal->codes());
            $this->assertSame(0, TallySyncEntry::query()->count(), 'a refusal writes no entry');
            $this->assertSame(0, TallySyncEvent::query()->count(), 'a refusal records no event');
            $this->assertSame($before, $this->tableCounts());

            return $refusal;
        }

        $this->fail('expected PurchaseOrderNotPostable with '.implode(', ', $codes));
    }

    /**
     * What the listener recorded on the order, through the one writer —
     * PurchaseOrderService::recordTallyStaging().
     *
     * @param  list<string>  $codes
     */
    private function assertStaging(PurchaseOrder $po, string $state, array $codes, ?int $entryId = null): void
    {
        $staging = $po->fresh()->tally_staging;
        $staging = is_string($staging) ? json_decode($staging, true) : $staging;
        $this->assertIsArray($staging, 'tally_staging recorded on the order');
        $this->assertSame($state, $staging['state']);
        $this->assertSame($codes, array_column($staging['reasons'] ?? [], 'code'));
        if ($entryId !== null) {
            $this->assertSame($entryId, $staging['entry_id']);
        }
    }

    /** Every line's allocations sum to the line — quantities and amounts both. */
    private function assertAllocationsSumToTheLine(array $line): void
    {
        $this->assertSame($line['quantity'], array_reduce($line['schedules'], fn ($c, $s) => bcadd($c, $s['quantity'], 4), '0.0000'), 'allocation quantities sum to the line');
        $this->assertSame($line['amount'], array_reduce($line['schedules'], fn ($c, $s) => bcadd($c, $s['amount'], 4), '0.0000'), 'allocation amounts sum to the line');
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (self::UNTOUCHED_TABLES as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
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

    /** @return array{0: User, 1: string} */
    private function agent(string $tokenName): array
    {
        $user = User::factory()->create(['name' => 'Tally Sync Agent', 'is_active' => true]);
        $issued = $user->createToken($tokenName, self::AGENT_ABILITIES);

        return [$user, $issued->plainTextToken];
    }
}
