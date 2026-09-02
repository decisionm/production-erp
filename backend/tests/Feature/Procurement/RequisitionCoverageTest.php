<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\RequisitionCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PR → PO → GOODS RECEIPT QUANTITY TRACKING, as one tested contract, under
 * the owner's rule of 2026-08-31:
 *
 *   a DRAFT order reserves nothing (nothing is held until the vendor has it);
 *   a CANCELLED order still counts — but ONLY if it was ever sent.
 *
 *   1. every requisition line reports requested / ordered / balance and one
 *      of Not Ordered / Partially Ordered / Fully Ordered;
 *   2. MANY orders may answer one requisition and their quantities COMBINE —
 *      and the combined quantity may never exceed the ask, refused at SEND
 *      (422, nothing written), because sending is where an order starts
 *      holding quantity. A draft may be typed for anything;
 *   3. an item the requisition never asked for is an over-order of zero;
 *   4. the refusal and the figures group PER ITEM, so a requisition that
 *      repeats an item is counted once and two items in different units are
 *      never added together;
 *   5. cancelling an unsent draft releases nothing (it held nothing);
 *      cancelling a SENT order leaves the allowance spent;
 *   6. the arithmetic is serialised on the requisition row.
 *
 * NOT re-proven here: that a fully received order becomes Closed and leaves
 * the receivable picker — PurchaseChainContractTest walks that, and
 * ProcurementListFiltersTest pins the `status[]` narrowing the picker asks
 * for.
 */
class RequisitionCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->desk = User::factory()->create(['name' => 'Procurement Desk', 'is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->desk->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->desk);

        $this->vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha', 'is_active' => true]);
    }

    // ---- 1. the four figures and the word ---------------------------------

    public function test_a_line_with_no_orders_reads_not_ordered_with_its_whole_quantity_still_to_order(): void
    {
        $resin = $this->item('RES-1', 'Kgs');
        $requisition = $this->requisition([[$resin, '500.0000']]);

        $line = $this->lineRow($requisition, $resin);

        $this->assertSame('500.0000', $line['requested_quantity']);
        $this->assertSame('0.0000', $line['ordered_quantity']);
        $this->assertSame('500.0000', $line['balance_quantity']);
        $this->assertSame('not_ordered', $line['order_status']);
        // The ask is still served under its original name too — every
        // existing reader asks for `quantity`.
        $this->assertSame('500.0000', $line['quantity']);
    }

    public function test_two_orders_against_one_requisition_combine_into_partially_then_fully_ordered(): void
    {
        $resin = $this->item('RES-2', 'Kgs');
        $requisition = $this->requisition([[$resin, '500.0000']]);

        $this->order($requisition, [[$resin, '200.0000']]);
        $partial = $this->lineRow($requisition, $resin);
        $this->assertSame('200.0000', $partial['ordered_quantity']);
        $this->assertSame('300.0000', $partial['balance_quantity']);
        $this->assertSame('partially_ordered', $partial['order_status']);

        // A SECOND order against the SAME requisition — the point of the
        // feature: the buyer split the ask across two orders.
        $this->order($requisition, [[$resin, '300.0000']]);
        $full = $this->lineRow($requisition, $resin);
        $this->assertSame('500.0000', $full['ordered_quantity']);
        $this->assertSame('0.0000', $full['balance_quantity']);
        $this->assertSame('fully_ordered', $full['order_status']);

        // And the requisition's own word rolls the lines up.
        $this->assertSame('fully_ordered', $this->requisitionRow($requisition)['order_status']);
    }

    public function test_the_requisition_word_is_partially_ordered_while_any_line_is_short(): void
    {
        $resin = $this->item('RES-3', 'Kgs');
        $film = $this->item('FLM-3', 'Nos');
        $requisition = $this->requisition([[$resin, '500.0000'], [$film, '40.0000']]);

        $this->order($requisition, [[$resin, '500.0000']]);

        $row = $this->requisitionRow($requisition);
        $this->assertSame('partially_ordered', $row['order_status']);
        $this->assertSame('fully_ordered', $this->lineRow($requisition, $resin)['order_status']);
        $this->assertSame('not_ordered', $this->lineRow($requisition, $film)['order_status']);
    }

    // ---- 2. the refusal ----------------------------------------------------

    public function test_sending_an_order_that_would_exceed_the_requisition_is_refused_and_writes_nothing(): void
    {
        $resin = $this->item('RES-4', 'Kgs');
        $requisition = $this->requisition([[$resin, '500.0000']]);
        $this->order($requisition, [[$resin, '400.0000']]);

        // The DRAFT is allowed — a draft holds nothing, so nothing it says
        // can exceed anything. The refusal belongs to the send.
        $draft = $this->draft($requisition, [[$resin, '150.0000']]);
        $before = PurchaseOrder::count();

        $response = $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/send")->assertStatus(422);

        // The message names the item and all three figures — a buyer must not
        // have to go and add the other orders up by hand.
        $message = $response->json('message');
        $this->assertStringContainsString('Relpet RES-4', $message);
        $this->assertStringContainsString('500.0000', $message);
        $this->assertStringContainsString('550.0000', $message);
        $this->assertStringContainsString('50.0000', $message);
        $this->assertStringContainsString('Kgs', $message);

        // NOTHING WRITTEN — the whole transaction rolled back. The order is
        // still a Draft, still unsent, and still holding nothing.
        $this->assertSame($before, PurchaseOrder::count());
        $refused = PurchaseOrder::find($draft['id']);
        $this->assertSame(PurchaseOrderStatus::Draft, $refused->status);
        $this->assertNull($refused->sent_at, 'a refused send must not leave the order stamped as sent');
        $this->assertSame('400.0000', $this->lineRow($requisition, $resin)['ordered_quantity']);
    }

    public function test_an_order_for_exactly_the_balance_is_allowed(): void
    {
        $resin = $this->item('RES-5', 'Kgs');
        $requisition = $this->requisition([[$resin, '500.0000']]);
        $this->order($requisition, [[$resin, '400.0000']]);

        $draft = $this->draft($requisition, [[$resin, '100.0000']]);
        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/send")->assertOk();

        $this->assertSame('fully_ordered', $this->lineRow($requisition, $resin)['order_status']);
    }

    public function test_two_drafts_may_each_be_typed_for_the_whole_requisition_and_the_second_send_is_refused(): void
    {
        // The cost the owner ACCEPTED when they ruled that a draft reserves
        // nothing, pinned here so it is a known consequence and not a
        // surprise: the refusal arrives at Send, after the vendor and the
        // rates were typed.
        $resin = $this->item('RES-5B', 'Kgs');
        $requisition = $this->requisition([[$resin, '500.0000']]);

        $first = $this->draft($requisition, [[$resin, '500.0000']]);
        $second = $this->draft($requisition, [[$resin, '500.0000']]);
        // Both typed happily: neither holds anything yet.
        $this->assertSame('not_ordered', $this->lineRow($requisition, $resin)['order_status']);

        $this->postJson("/api/v1/procurement/purchase-orders/{$first['id']}/send")->assertOk();
        $this->postJson("/api/v1/procurement/purchase-orders/{$second['id']}/send")->assertStatus(422);
    }

    public function test_an_order_carrying_no_requisition_at_all_is_never_capped(): void
    {
        $resin = $this->item('RES-6', 'Kgs');

        $free = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-30',
            'lines' => [['item_id' => $resin->id, 'quantity' => '9000.0000', 'unit_price' => '92.0000']],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/procurement/purchase-orders/{$free['id']}/send")->assertOk();
    }

    public function test_amending_a_draft_upwards_is_allowed_and_the_send_is_what_refuses_it(): void
    {
        $resin = $this->item('RES-7', 'Kgs');
        $requisition = $this->requisition([[$resin, '500.0000']]);

        $draft = $this->draft($requisition, [[$resin, '300.0000']]);

        // Amend is Draft-only and a draft holds nothing, so there is nothing
        // here for the coverage rule to refuse — whatever quantity is typed
        // is checked when the draft is SENT.
        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/amend", [
            'lines' => [['item_id' => $resin->id, 'quantity' => '900.0000', 'unit_price' => '92.0000']],
        ])->assertOk();
        $this->assertSame('not_ordered', $this->lineRow($requisition, $resin)['order_status']);

        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/send")->assertStatus(422);
        // Refused whole: the amended lines are still there, the order is
        // still a Draft, and nothing was stamped.
        $this->assertSame(1, PurchaseOrder::find($draft['id'])->lines()->count());
        $this->assertNull(PurchaseOrder::find($draft['id'])->sent_at);
    }

    public function test_amending_a_draft_downwards_lets_the_send_through(): void
    {
        $resin = $this->item('RES-8', 'Kgs');
        $requisition = $this->requisition([[$resin, '500.0000']]);
        $this->order($requisition, [[$resin, '300.0000']]);

        $draft = $this->draft($requisition, [[$resin, '500.0000']]);
        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/send")->assertStatus(422);

        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/amend", [
            'lines' => [['item_id' => $resin->id, 'quantity' => '200.0000', 'unit_price' => '92.0000']],
        ])->assertOk();
        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/send")->assertOk();

        $this->assertSame('fully_ordered', $this->lineRow($requisition, $resin)['order_status']);
    }

    // ---- 3. an item the requisition never asked for -------------------------

    public function test_an_item_the_requisition_never_asked_for_is_refused(): void
    {
        $resin = $this->item('RES-9', 'Kgs');
        $stranger = $this->item('STR-9', 'Nos');
        $requisition = $this->requisition([[$resin, '500.0000']]);

        $draft = $this->draft($requisition, [[$resin, '100.0000'], [$stranger, '5.0000']]);

        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Relpet STR-9'));
    }

    // ---- 4. per item, and never across units --------------------------------

    public function test_a_requisition_that_repeats_an_item_is_counted_once_and_filled_in_line_order(): void
    {
        $resin = $this->item('RES-10', 'Kgs');
        $requisition = $this->requisition([[$resin, '300.0000'], [$resin, '200.0000']]);

        // 400 of the item — the ITEM's ask is 500, so this is inside it, and
        // the two lines share it first-line-first.
        $this->order($requisition, [[$resin, '400.0000']]);

        $rows = $this->requisitionRow($requisition)['lines'];
        $this->assertSame('300.0000', $rows[0]['ordered_quantity']);
        $this->assertSame('fully_ordered', $rows[0]['order_status']);
        $this->assertSame('100.0000', $rows[1]['ordered_quantity']);
        $this->assertSame('100.0000', $rows[1]['balance_quantity']);
        $this->assertSame('partially_ordered', $rows[1]['order_status']);

        // And the cap is the ITEM's 500, not either line's — 150 more is 550.
        $over = $this->draft($requisition, [[$resin, '150.0000']]);
        $this->postJson("/api/v1/procurement/purchase-orders/{$over['id']}/send")->assertStatus(422);
    }

    public function test_two_items_in_different_units_are_never_added_together(): void
    {
        $resin = $this->item('RES-11', 'Kgs');
        $caps = $this->item('CAP-11', 'Nos');
        $requisition = $this->requisition([[$resin, '500.0000'], [$caps, '40.0000']]);

        $this->order($requisition, [[$resin, '500.0000'], [$caps, '40.0000']]);

        $row = $this->requisitionRow($requisition);

        // Each line carries its own unit, and its figures belong to it alone.
        $resinRow = $this->lineRow($requisition, $resin);
        $capsRow = $this->lineRow($requisition, $caps);
        $this->assertSame('Kgs', $resinRow['item']['uom']);
        $this->assertSame('Nos', $capsRow['item']['uom']);
        $this->assertSame('500.0000', $resinRow['ordered_quantity']);
        $this->assertSame('40.0000', $capsRow['ordered_quantity']);

        // 540 EXISTS NOWHERE. The requisition reports a WORD, never a total:
        // a figure summing Kgs and Nos would be a number in no unit at all.
        foreach (array_keys($row) as $key) {
            $this->assertStringNotContainsString('quantity', $key, "requisition-level key {$key}");
        }
        $this->assertSame('fully_ordered', $row['order_status']);
        $this->assertStringNotContainsString('540', json_encode($row));
    }

    // ---- 5. which orders hold quantity, and the lock ------------------------

    /**
     * THE OWNER'S RULE, made load-bearing. Every order here is identical but
     * for its STATUS and whether it was ever sent, so this test says exactly
     * one thing: which orders hold quantity against a requisition.
     *
     * Cancelled appears TWICE, because that is the case the rule exists for:
     * cancelled-after-sending spent the requisition's allowance, and
     * cancelled-while-still-a-draft never held anything to spend. A flat
     * status list cannot tell them apart, which is why reserves() is a
     * predicate.
     */
    public function test_which_orders_hold_quantity_and_which_hand_it_back(): void
    {
        $expected = [
            // label                     status                  sent?  reserves?
            'draft' => [PurchaseOrderStatus::Draft, false, false],
            'sent' => [PurchaseOrderStatus::Sent, true, true],
            'partially_received' => [PurchaseOrderStatus::PartiallyReceived, true, true],
            'closed' => [PurchaseOrderStatus::Closed, true, true],
            // The two faces of Cancelled — the reason this is a predicate.
            'cancelled after being sent' => [PurchaseOrderStatus::Cancelled, true, true],
            'cancelled while still a draft' => [PurchaseOrderStatus::Cancelled, false, false],
        ];

        // Every case of the enum is decided here: a new status cannot join
        // the lifecycle without this test forcing an answer for it.
        $this->assertSame(
            array_map(fn (PurchaseOrderStatus $c) => $c->value, PurchaseOrderStatus::cases()),
            array_values(array_unique(array_map(fn (array $case) => $case[0]->value, $expected))),
        );

        foreach ($expected as $label => [$status, $wasSent, $reserves]) {
            $item = $this->item('RES-BOUND-'.md5($label), 'Kgs');
            $requisition = $this->requisition([[$item, '500.0000']]);
            $order = $this->order($requisition, [[$item, '200.0000']], $status, $wasSent ? '2026-08-29 10:00:00' : null);

            $line = $this->lineRow($requisition, $item);
            $this->assertSame($reserves ? '200.0000' : '0.0000', $line['ordered_quantity'], $label);
            $this->assertSame($reserves ? 'partially_ordered' : 'not_ordered', $line['order_status'], $label);

            // The predicate, the SQL twin, and what the screen is told must
            // give one answer. Three readers of one rule is how two of them
            // eventually disagree.
            $this->assertSame($reserves, RequisitionCoverageService::reserves($order->fresh()), "reserves(): {$label}");
            $this->assertSame(
                $reserves,
                RequisitionCoverageService::scopeReserving(PurchaseOrder::query()->whereKey($order->id))->exists(),
                "scopeReserving(): {$label}",
            );
            $this->assertSame(
                $reserves,
                collect($this->requisitionRow($requisition)['purchase_orders'])->firstWhere('id', $order->id)['reserves_quantity'],
                "reserves_quantity on the wire: {$label}",
            );
        }
    }

    /**
     * THE CASE THE RULE WAS WRITTEN FOR, walked end to end through the real
     * endpoints. A draft holds nothing and a cancelled order still counts;
     * alone those two contradict, and cancelling an abandoned draft would eat
     * a requisition the draft never held.
     */
    public function test_cancelling_an_unsent_draft_leaves_the_balance_untouched_but_cancelling_a_sent_order_spends_it(): void
    {
        $resin = $this->item('RES-14', 'Kgs');

        // (a) typed, never sent, cancelled — the requisition is untouched.
        $abandoned = $this->requisition([[$resin, '500.0000']]);
        $draft = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'purchase_requisition_id' => $abandoned->id,
            'order_date' => '2026-08-30',
            'lines' => [['item_id' => $resin->id, 'quantity' => '500.0000', 'unit_price' => '92.0000']],
        ])->assertCreated()->json('data');
        $this->assertSame('500.0000', $this->lineRow($abandoned, $resin)['balance_quantity'], 'a draft must hold nothing');

        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/cancel", ['reason' => 'Typed by mistake.'])->assertOk();
        $this->assertSame('500.0000', $this->lineRow($abandoned, $resin)['balance_quantity'], 'cancelling an unsent draft must release nothing, because it held nothing');
        $this->assertSame('not_ordered', $this->lineRow($abandoned, $resin)['order_status']);

        // (b) typed, SENT, then cancelled — the allowance is spent and stays spent.
        $spent = $this->requisition([[$resin, '500.0000']]);
        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'purchase_requisition_id' => $spent->id,
            'order_date' => '2026-08-30',
            'lines' => [['item_id' => $resin->id, 'quantity' => '500.0000', 'unit_price' => '92.0000']],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/send")->assertOk();
        $this->assertSame('fully_ordered', $this->lineRow($spent, $resin)['order_status']);

        $this->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/cancel", ['reason' => 'The vendor cannot supply.'])->assertOk();
        $this->assertSame('0.0000', $this->lineRow($spent, $resin)['balance_quantity'], 'a cancelled order that reached the vendor keeps its allowance');
        $this->assertSame('fully_ordered', $this->lineRow($spent, $resin)['order_status']);

        // Wanting the material again means a NEW requisition, not a re-order
        // against this one — which is exactly what the owner chose. The
        // DRAFT is allowed (drafts hold nothing); the SEND is refused.
        $retry = $this->draft($spent, [[$resin, '500.0000']]);
        $this->postJson("/api/v1/procurement/purchase-orders/{$retry['id']}/send")->assertStatus(422);
    }

    /**
     * THE SUM IS SERIALISED ON THE REQUISITION ROW.
     *
     * Two buyers creating orders against one requisition at the same moment
     * would otherwise both read the same total, both find room, and both
     * commit. The guard therefore re-reads the requisition UNDER A ROW LOCK,
     * inside the writing transaction and AFTER the lines are inserted — so
     * the second transaction blocks until the first commits and then sums a
     * total that includes it.
     *
     * WHAT THIS TEST PROVES, AND ON WHICH DATABASE. Locally the suite runs
     * on SQLite (phpunit.xml, :memory:), whose grammar compiles
     * `lockForUpdate()` to NOTHING and which serialises writers anyway — so
     * no test can make two writers race there, and asserting a passing race
     * would be a test passing for the wrong reason. What is provable
     * everywhere is the ORDERING that makes the lock effective: the
     * requisition is re-read from the database, inside the transaction,
     * after the line INSERTs. A guard that summed a caller-loaded relation,
     * or sat outside the transaction, would pass every other test here.
     *
     * On MySQL — the CI job and production — the lock IS compiled, so this
     * additionally asserts `for update` on that very read. Which is why the
     * SQL is matched on a NORMALISED string: SQLite quotes identifiers with
     * "double quotes" and MySQL with `backticks`, and the first version of
     * this test matched SQLite's spelling literally, went green locally, and
     * failed on the database that actually runs it.
     *
     * AND IT IS THE SEND THAT IS WATCHED, not the create. send() already
     * locks the ORDER row, but two DIFFERENT drafts against one requisition
     * lock two different orders — the requisition is what they contend for,
     * so the requisition is what must be locked.
     */
    public function test_the_guard_re_reads_the_requisition_under_a_lock_after_the_order_is_marked_sent(): void
    {
        $resin = $this->item('RES-13', 'Kgs');
        $requisition = $this->requisition([[$resin, '500.0000']]);
        $draft = $this->draft($requisition, [[$resin, '100.0000']]);

        $trail = [];
        DB::listen(function ($query) use (&$trail) {
            // Identifier quoting is the DIALECT'S, not the query's: strip it
            // and match the statement itself.
            $sql = str_replace(['`', '"'], '', strtolower($query->sql));

            if (str_starts_with($sql, 'update purchase_orders set')) {
                $trail[] = ['marked_sent', DB::transactionLevel(), $sql];
            }
            if (str_starts_with($sql, 'select * from purchase_requisitions where')) {
                $trail[] = ['requisition_read', DB::transactionLevel(), $sql];
            }
        });

        $this->postJson("/api/v1/procurement/purchase-orders/{$draft['id']}/send")->assertOk();

        $sent = array_search('marked_sent', array_column($trail, 0), true);
        $read = array_search('requisition_read', array_column($trail, 0), true);

        $this->assertNotFalse($sent, 'no purchase_orders update was observed');
        $this->assertNotFalse($read, 'the guard never re-read the requisition from the database');
        $this->assertGreaterThan($sent, $read, 'the requisition was summed BEFORE this order was marked sent, so the order was not in its own sum');
        $this->assertGreaterThan(0, $trail[$read][1], 'the requisition was read outside the writing transaction');

        // And on a database whose grammar compiles row locks — MySQL in CI
        // and in production — that the read actually took one. SQLite's
        // grammar compiles lockForUpdate() to an empty string, so there is
        // nothing to assert there and claiming otherwise would be theatre.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->assertStringContainsString(
                'for update',
                $trail[$read][2],
                'the requisition was summed WITHOUT a row lock — two buyers can both find room',
            );
        }
    }

    // ---- fixtures -----------------------------------------------------------

    private function item(string $sku, string $uom): Item
    {
        return Item::create(['sku' => $sku, 'name' => "Relpet {$sku}", 'uom' => $uom, 'category' => ItemCategory::RawMaterial]);
    }

    /** @param  list<array{0: Item, 1: string}>  $lines */
    private function requisition(array $lines): PurchaseRequisition
    {
        $requisition = PurchaseRequisition::create([
            'status' => PurchaseRequisitionStatus::Approved,
            'requested_by' => $this->desk->id,
            'needed_by_date' => '2026-09-01',
        ]);

        foreach ($lines as [$item, $quantity]) {
            $requisition->lines()->create(['item_id' => $item->id, 'quantity' => $quantity]);
        }

        return $requisition;
    }

    /**
     * An order written STRAIGHT TO THE TABLE, so a test can put one in any
     * status — including the ones the guard would refuse — without walking
     * the lifecycle to get there.
     *
     * @param  list<array{0: Item, 1: string}>  $lines
     */
    private function order(
        PurchaseRequisition $requisition,
        array $lines,
        PurchaseOrderStatus $status = PurchaseOrderStatus::Sent,
        ?string $sentAt = '2026-08-29 10:00:00',
    ): PurchaseOrder {
        $order = PurchaseOrder::create([
            'vendor_id' => $this->vendor->id,
            'purchase_requisition_id' => $requisition->id,
            'status' => $status,
            'order_date' => '2026-08-29',
            // Written straight to the table like the status beside it, so a
            // test can build the ambiguous row — Cancelled, never sent — the
            // whole rule turns on.
            'sent_at' => $status === PurchaseOrderStatus::Draft ? null : $sentAt,
        ]);

        foreach ($lines as [$item, $quantity]) {
            $order->lines()->create([
                'item_id' => $item->id,
                'quantity' => $quantity,
                'unit_price' => '92.0000',
                'quantity_received' => 0,
            ]);
        }

        return $order;
    }

    /**
     * A DRAFT raised through the real endpoint — what a buyer actually types.
     * Never refused by the coverage rule (a draft holds nothing); it is the
     * SEND that is checked.
     *
     * @param  list<array{0: Item, 1: string}>  $lines
     * @return array<string, mixed>
     */
    private function draft(PurchaseRequisition $requisition, array $lines): array
    {
        return $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'purchase_requisition_id' => $requisition->id,
            'order_date' => '2026-08-30',
            'lines' => array_map(fn (array $line) => [
                'item_id' => $line[0]->id,
                'quantity' => $line[1],
                'unit_price' => '92.0000',
            ], $lines),
        ])->assertCreated()->json('data');
    }

    /** @return array<string, mixed> */
    private function requisitionRow(PurchaseRequisition $requisition): array
    {
        return collect($this->getJson('/api/v1/procurement/purchase-requisitions')->assertOk()->json('data'))
            ->firstWhere('id', $requisition->id);
    }

    /** @return array<string, mixed> */
    private function lineRow(PurchaseRequisition $requisition, Item $item): array
    {
        return collect($this->requisitionRow($requisition)['lines'])
            ->firstWhere('item.id', $item->id);
    }
}
