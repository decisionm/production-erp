<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Events\PurchaseOrderSent;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderRevision;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE PURCHASE ORDER LIFECYCLE (Phase 6, P6-01/P6-02) as append-only POST
 * actions: amend (Draft only — a revision row keeps the prior lines), close
 * (Sent | PartiallyReceived, with a reason; the remaining quantity per line
 * is recorded), cancel (Draft | Sent with ZERO receipts, with a reason —
 * the enum's Cancelled case comes to life here), send (Draft → Sent, and
 * the ONE event Procurement announces: PurchaseOrderSent, after commit).
 * A Tally-originated mirror refuses all three: the ERP never rewrites
 * Tally's book. Every refusal is a 422; the resource's `can` flags are the
 * SAME predicate the actions enforce, so a button and its refusal cannot
 * disagree.
 *
 * Fixture values are synthetic (FC-06): "Vendor Alpha", ITEM_A, rate 1.00.
 * Nothing here posts to Tally, reads Tally, or moves stock except the one
 * goods receipt a test needs to make an order PartiallyReceived.
 */
class PurchaseOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private Item $itemA;

    private Item $itemB;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);
        // The Receipt Note listener would enqueue a Tally entry off the one
        // GRN below; that queue is not this test's subject.
        Event::fake([GoodsReceiptNoteReceived::class]);

        $this->vendor = Vendor::create(['code' => 'V-ALPHA', 'name' => 'Vendor Alpha']);
        $this->itemA = Item::create(['sku' => 'ITEM_A', 'name' => 'Item A', 'uom' => 'Kgs']);
        $this->itemB = Item::create(['sku' => 'ITEM_B', 'name' => 'Item B', 'uom' => 'Kgs']);
        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']);
    }

    /** @param list<string> $permissions */
    private function actAs(array $permissions = ['procurement.view', 'procurement.manage']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function orderPayload(array $overrides = []): array
    {
        return array_replace([
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [
                ['item_id' => $this->itemA->id, 'quantity' => '100', 'unit_price' => '1.00'],
                ['item_id' => $this->itemB->id, 'quantity' => '50', 'unit_price' => '1.00'],
            ],
        ], $overrides);
    }

    private function draftOrder(array $overrides = []): PurchaseOrder
    {
        $id = $this->postJson('/api/v1/procurement/purchase-orders', $this->orderPayload($overrides))
            ->assertCreated()
            ->json('data.id');

        return PurchaseOrder::findOrFail($id);
    }

    private function sentOrder(array $overrides = []): PurchaseOrder
    {
        $order = $this->draftOrder($overrides);
        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/send")->assertOk();

        return $order->fresh();
    }

    private function mirrorOrder(): PurchaseOrder
    {
        return $this->draftOrder(['source' => 'tally', 'tally_order_no' => 'MIRROR-0001']);
    }

    /** One arrival of $quantity of ITEM_A against the order (a bag lot rides along). */
    private function receive(PurchaseOrder $order, string $quantity, string $key = 'rk-1'): void
    {
        $line = $order->lines()->where('item_id', $this->itemA->id)->firstOrFail();

        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => $key,
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-08-15',
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'quantity' => $quantity,
            ]],
        ])->assertCreated();
    }

    // ---- amend --------------------------------------------------------------------

    public function test_amend_in_draft_replaces_the_lines_and_records_the_prior_lines_as_a_revision(): void
    {
        $this->actAs();
        $order = $this->draftOrder();
        $priorLineIds = $order->lines()->pluck('id')->all();

        $response = $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/amend", [
            'reason' => 'vendor confirmed a smaller first lot',
            'lines' => [[
                'item_id' => $this->itemA->id,
                'quantity' => '60',
                'unit_price' => '1.00',
                'schedules' => [
                    ['due_date' => '2026-08-25', 'quantity' => '40'],
                    ['due_date' => '2026-08-18', 'quantity' => '20'],
                ],
            ]],
        ])->assertOk();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revisions_count', 1)
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.quantity', '60.0000')
            // Schedules oldest-due first, as create() serves them.
            ->assertJsonPath('data.lines.0.schedules.0.due_date', '2026-08-18')
            ->assertJsonPath('data.lines.0.schedules.1.due_date', '2026-08-25')
            ->assertJsonPath('data.can.amend', true);

        // The prior lines are gone from the live order and kept, verbatim,
        // in the revision — item, quantity, rate — under revision_no 1.
        $order->refresh();
        $this->assertSame([], array_intersect($priorLineIds, $order->lines()->pluck('id')->all()));

        $revision = PurchaseOrderRevision::query()->where('purchase_order_id', $order->id)->sole();
        $this->assertSame(1, $revision->revision_no);
        $this->assertSame('amend', $revision->kind);
        $this->assertSame('vendor confirmed a smaller first lot', $revision->reason);
        $this->assertCount(2, $revision->lines_json);
        $this->assertSame($this->itemA->id, $revision->lines_json[0]['item_id']);
        $this->assertSame('100.0000', $revision->lines_json[0]['quantity']);
        $this->assertSame('1.0000', $revision->lines_json[0]['unit_price']);

        // A second amendment is revision 2 and keeps the FIRST amendment's lines.
        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/amend", [
            'lines' => [['item_id' => $this->itemB->id, 'quantity' => '5', 'unit_price' => '1.00']],
        ])->assertOk()->assertJsonPath('data.revisions_count', 2);

        $second = PurchaseOrderRevision::query()->where('purchase_order_id', $order->id)->where('revision_no', 2)->sole();
        $this->assertSame('60.0000', $second->lines_json[0]['quantity']);
        $this->assertCount(2, $second->lines_json[0]['schedules']);
    }

    public function test_amend_refuses_schedules_that_promise_more_than_the_line(): void
    {
        $this->actAs();
        $order = $this->draftOrder();

        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/amend", [
            'lines' => [[
                'item_id' => $this->itemA->id, 'quantity' => '10', 'unit_price' => '1.00',
                'schedules' => [['due_date' => '2026-08-25', 'quantity' => '11']],
            ]],
        ])->assertStatus(422);

        // Nothing changed: the original two lines stand, no revision was written.
        $this->assertSame(2, $order->lines()->count());
        $this->assertSame(0, PurchaseOrderRevision::query()->count());
    }

    public function test_amend_after_send_is_refused_and_leaves_the_order_untouched(): void
    {
        $this->actAs();
        $order = $this->sentOrder();

        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/amend", [
            'lines' => [['item_id' => $this->itemA->id, 'quantity' => '1', 'unit_price' => '1.00']],
        ])->assertStatus(422)
            ->assertJsonPath('code', 'amend_not_draft')
            ->assertJsonFragment(['message' => 'Purchase order PO-'.$order->id.' is sent: amend only in Draft — short-close or cancel it.']);

        $this->assertSame(2, $order->lines()->count());
        $this->assertSame(0, PurchaseOrderRevision::query()->count());
    }

    // ---- close --------------------------------------------------------------------

    public function test_close_from_sent_records_reason_actor_time_and_the_remaining_per_line(): void
    {
        $user = $this->actAs();
        $order = $this->sentOrder();

        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/close", [
            'reason' => 'vendor cannot supply the balance',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_reason', 'vendor cannot supply the balance')
            ->assertJsonPath('data.closed_by', $user->id)
            ->assertJsonPath('data.revisions_count', 1)
            ->assertJsonPath('data.can', ['amend' => false, 'close' => false, 'cancel' => false, 'send' => false]);

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Closed, $order->status);
        $this->assertNotNull($order->closed_at);

        $revision = PurchaseOrderRevision::query()->where('purchase_order_id', $order->id)->sole();
        $this->assertSame('close', $revision->kind);
        $this->assertSame('vendor cannot supply the balance', $revision->reason);
        $remaining = collect($revision->lines_json)->pluck('remaining', 'item_id')->all();
        $this->assertSame(['100.0000', '50.0000'], [$remaining[$this->itemA->id], $remaining[$this->itemB->id]]);
    }

    public function test_close_from_partially_received_records_what_was_still_open(): void
    {
        $this->actAs();
        $order = $this->sentOrder();
        $this->receive($order, '30');
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);

        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/close", ['reason' => 'short-closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $revision = PurchaseOrderRevision::query()->where('purchase_order_id', $order->id)->sole();
        $lineA = collect($revision->lines_json)->firstWhere('item_id', $this->itemA->id);
        $this->assertSame('30.0000', $lineA['quantity_received']);
        $this->assertSame('70.0000', $lineA['remaining']);
    }

    public function test_close_refuses_draft_and_already_closed_and_needs_a_reason(): void
    {
        $this->actAs();

        $draft = $this->draftOrder();
        $this->postJson("/api/v1/procurement/purchase-orders/{$draft->id}/close", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'close_not_open');
        $this->assertSame(PurchaseOrderStatus::Draft, $draft->fresh()->status);

        $sent = $this->sentOrder();
        // No reason: a validation 422 (the FormRequest), not a transition.
        $this->postJson("/api/v1/procurement/purchase-orders/{$sent->id}/close", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
        $this->assertSame(PurchaseOrderStatus::Sent, $sent->fresh()->status);

        $this->postJson("/api/v1/procurement/purchase-orders/{$sent->id}/close", ['reason' => 'done'])->assertOk();
        $this->postJson("/api/v1/procurement/purchase-orders/{$sent->id}/close", ['reason' => 'again'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'close_not_open');
        // The first close's record stands; the refused second one wrote nothing.
        $this->assertSame('done', $sent->fresh()->closed_reason);
        $this->assertSame(1, PurchaseOrderRevision::query()->where('purchase_order_id', $sent->id)->count());
    }

    // ---- cancel -------------------------------------------------------------------

    public function test_cancel_from_draft_or_sent_with_zero_receipts_brings_the_cancelled_case_to_life(): void
    {
        $user = $this->actAs();

        $draft = $this->draftOrder();
        $this->postJson("/api/v1/procurement/purchase-orders/{$draft->id}/cancel", ['reason' => 'raised twice'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_reason', 'raised twice')
            ->assertJsonPath('data.cancelled_by', $user->id)
            ->assertJsonPath('data.can', ['amend' => false, 'close' => false, 'cancel' => false, 'send' => false]);
        $this->assertSame(PurchaseOrderStatus::Cancelled, $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->cancelled_at);

        $sent = $this->sentOrder();
        $this->postJson("/api/v1/procurement/purchase-orders/{$sent->id}/cancel", ['reason' => 'vendor declined'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // A cancelled order refuses everything else, including send.
        $this->postJson("/api/v1/procurement/purchase-orders/{$draft->id}/send")->assertStatus(422);
        $this->postJson("/api/v1/procurement/purchase-orders/{$sent->id}/close", ['reason' => 'x'])->assertStatus(422);

        // The list filter finds them under the fifth status.
        $ids = collect($this->getJson('/api/v1/procurement/purchase-orders?status=cancelled')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$draft->id, $sent->id], $ids);
    }

    public function test_cancel_after_a_receipt_is_refused(): void
    {
        $this->actAs();

        $partial = $this->sentOrder();
        $this->receive($partial, '30', 'rk-partial');
        $this->postJson("/api/v1/procurement/purchase-orders/{$partial->id}/cancel", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cancel_not_open');
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $partial->fresh()->status);

        // Fully received (Closed by the receipt) — refused too.
        $closed = $this->sentOrder(['lines' => [['item_id' => $this->itemA->id, 'quantity' => '10', 'unit_price' => '1.00']]]);
        $this->receive($closed, '10', 'rk-full');
        $this->assertSame(PurchaseOrderStatus::Closed, $closed->fresh()->status);
        $this->postJson("/api/v1/procurement/purchase-orders/{$closed->id}/cancel", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cancel_not_open');
    }

    public function test_cancel_needs_a_reason(): void
    {
        $this->actAs();
        $order = $this->draftOrder();

        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
        $this->assertSame(PurchaseOrderStatus::Draft, $order->fresh()->status);
    }

    // ---- the Tally mirror ---------------------------------------------------------

    public function test_a_tally_originated_order_refuses_amend_close_and_cancel(): void
    {
        $this->actAs();
        $mirror = $this->mirrorOrder();
        $this->assertSame(PurchaseOrderStatus::Sent, $mirror->status);

        foreach ([
            'amend' => ['lines' => [['item_id' => $this->itemA->id, 'quantity' => '1', 'unit_price' => '1.00']]],
            'close' => ['reason' => 'x'],
            'cancel' => ['reason' => 'x'],
        ] as $action => $body) {
            $this->postJson("/api/v1/procurement/purchase-orders/{$mirror->id}/{$action}", $body)
                ->assertStatus(422)
                ->assertJsonPath('code', 'tally_mirror')
                ->assertJsonFragment(['message' => 'Purchase order PO-'.$mirror->id.' is a Tally-originated order: change it in Tally.']);
        }

        $mirror->refresh();
        $this->assertSame(PurchaseOrderStatus::Sent, $mirror->status);
        $this->assertNull($mirror->closed_at);
        $this->assertNull($mirror->cancelled_at);
        $this->assertSame(0, PurchaseOrderRevision::query()->count());

        // And the resource says so up front.
        $this->getJson("/api/v1/procurement/purchase-orders/{$mirror->id}")
            ->assertOk()
            ->assertJsonPath('data.can', ['amend' => false, 'close' => false, 'cancel' => false, 'send' => false]);
    }

    // ---- receipts against a closed / cancelled order --------------------------------

    public function test_a_goods_receipt_against_a_cancelled_or_closed_order_is_refused(): void
    {
        $this->actAs();

        $cancelled = $this->sentOrder();
        $this->postJson("/api/v1/procurement/purchase-orders/{$cancelled->id}/cancel", ['reason' => 'x'])->assertOk();
        $line = $cancelled->lines()->where('item_id', $this->itemA->id)->firstOrFail();
        $this->postJson('/api/v1/procurement/goods-receipts', [
            'purchase_order_id' => $cancelled->id,
            'warehouse_id' => $this->store->id,
            'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '10']],
        ])->assertStatus(422);

        $closed = $this->sentOrder();
        $this->postJson("/api/v1/procurement/purchase-orders/{$closed->id}/close", ['reason' => 'x'])->assertOk();
        $line = $closed->lines()->where('item_id', $this->itemA->id)->firstOrFail();
        $this->postJson('/api/v1/procurement/goods-receipts', [
            'purchase_order_id' => $closed->id,
            'warehouse_id' => $this->store->id,
            'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '10']],
        ])->assertStatus(422);

        // No receipt, no stock, on either.
        $this->assertSame(0, DB::table('goods_receipt_notes')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, $cancelled->receipts()->count());
    }

    // ---- send + the event -----------------------------------------------------------

    public function test_send_dispatches_purchase_order_sent_exactly_once(): void
    {
        Event::fake([GoodsReceiptNoteReceived::class, PurchaseOrderSent::class]);
        $this->actAs();
        $order = $this->draftOrder();

        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.can.send', false)
            ->assertJsonPath('data.can.close', true)
            ->assertJsonPath('data.can.cancel', true);

        Event::assertDispatchedTimes(PurchaseOrderSent::class, 1);
        Event::assertDispatched(PurchaseOrderSent::class, fn (PurchaseOrderSent $event) => $event->order->id === $order->id
            && $event->order->status === PurchaseOrderStatus::Sent);

        // A second send is refused and announces nothing more.
        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/send")->assertStatus(422);
        Event::assertDispatchedTimes(PurchaseOrderSent::class, 1);

        // A mirror is born Sent and never announced by send() (it cannot be sent).
        $mirror = $this->mirrorOrder();
        $this->postJson("/api/v1/procurement/purchase-orders/{$mirror->id}/send")->assertStatus(422);
        Event::assertDispatchedTimes(PurchaseOrderSent::class, 1);
    }

    public function test_send_announces_after_the_transaction_committed(): void
    {
        $this->actAs();
        $order = $this->draftOrder();

        // A real listener (not a fake): what it can see at dispatch time IS
        // the proof. Under RefreshDatabase the test's own wrapper is level 1;
        // send()'s transaction is level 2 — a listener at level 1 runs after
        // that transaction committed, and a fresh read sees the status.
        $observed = [];
        Event::listen(PurchaseOrderSent::class, function (PurchaseOrderSent $event) use (&$observed) {
            $observed[] = [
                'level' => DB::transactionLevel(),
                // The raw column (not the cast enum): what another
                // connection would read at that instant.
                'status' => DB::table('purchase_orders')->where('id', $event->order->id)->value('status'),
            ];
        });

        app(PurchaseOrderService::class)->send($order);

        $this->assertSame([['level' => 1, 'status' => 'sent']], $observed);
    }

    // ---- can, permissions, filters, identity, staging ------------------------------

    public function test_can_follows_the_state_machine_on_every_read(): void
    {
        $this->actAs();

        $draft = $this->draftOrder();
        $this->getJson("/api/v1/procurement/purchase-orders/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.can', ['amend' => true, 'close' => false, 'cancel' => true, 'send' => true]);

        $sent = $this->sentOrder();
        $this->getJson("/api/v1/procurement/purchase-orders/{$sent->id}")
            ->assertOk()
            ->assertJsonPath('data.can', ['amend' => false, 'close' => true, 'cancel' => true, 'send' => false]);

        $partial = $this->sentOrder();
        $this->receive($partial, '30', 'rk-can');
        $this->getJson("/api/v1/procurement/purchase-orders/{$partial->id}")
            ->assertOk()
            ->assertJsonPath('data.can', ['amend' => false, 'close' => true, 'cancel' => false, 'send' => false])
            ->assertJsonPath('data.receipts_count', 1);

        // The list carries the same flags per row.
        $rows = collect($this->getJson('/api/v1/procurement/purchase-orders')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['amend' => true, 'close' => false, 'cancel' => true, 'send' => true], $rows[$draft->id]['can']);
        $this->assertSame(['amend' => false, 'close' => true, 'cancel' => false, 'send' => false], $rows[$partial->id]['can']);
    }

    public function test_lifecycle_actions_need_procurement_manage(): void
    {
        $this->actAs();
        $order = $this->draftOrder();

        $this->actAs(['procurement.view']);
        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/amend", ['lines' => []])->assertForbidden();
        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/close", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/cancel", ['reason' => 'x'])->assertForbidden();
        // Reads are open to the viewer.
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")->assertOk();
    }

    public function test_the_status_filter_takes_one_value_or_a_list(): void
    {
        $this->actAs();
        $draft = $this->draftOrder();
        $sent = $this->sentOrder();
        $cancelled = $this->draftOrder();
        $this->postJson("/api/v1/procurement/purchase-orders/{$cancelled->id}/cancel", ['reason' => 'x'])->assertOk();

        $ids = fn (string $query) => collect($this->getJson("/api/v1/procurement/purchase-orders?{$query}")->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();

        // The single value every earlier caller sends still works.
        $this->assertSame([$sent->id], $ids('status=sent'));
        // A list is a whereIn.
        $this->assertSame([$draft->id, $cancelled->id], $ids('status[]=draft&status[]=cancelled'));
        $this->assertSame([$draft->id, $sent->id, $cancelled->id], $ids('status[]=draft&status[]=sent&status[]=cancelled'));
        // A value that is not a status is refused either way.
        $this->getJson('/api/v1/procurement/purchase-orders?status=shipped')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/procurement/purchase-orders?status[]=draft&status[]=shipped')->assertStatus(422)->assertJsonValidationErrors(['status.1']);
    }

    public function test_q_matches_the_order_number_and_the_tally_order_number(): void
    {
        $this->actAs();
        $erp = $this->draftOrder();
        $mirror = $this->mirrorOrder();

        $ids = fn (string $q) => collect($this->getJson('/api/v1/procurement/purchase-orders?q='.urlencode($q))->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([$erp->id], $ids("PO-{$erp->id}"));
        $this->assertSame([$mirror->id], $ids('MIRROR-0001'));
        $this->assertSame([$mirror->id], $ids('mirror-00'));
    }

    public function test_a_vendor_carries_its_tally_ledger_name_settable_on_store_and_update(): void
    {
        $this->actAs();

        $created = $this->postJson('/api/v1/procurement/vendors', [
            'code' => 'V-BETA', 'name' => 'Vendor Beta', 'tally_ledger_name' => 'Vendor Beta (Tally)',
        ])->assertCreated()->json('data');
        $this->assertSame('Vendor Beta (Tally)', $created['tally_ledger_name']);

        // Nothing populates it by itself: a vendor created without one has null.
        $this->assertNull($this->vendor->fresh()->tally_ledger_name);
        $this->putJson("/api/v1/procurement/vendors/{$this->vendor->id}", ['tally_ledger_name' => 'Vendor Alpha (Tally)'])
            ->assertOk()
            ->assertJsonPath('data.tally_ledger_name', 'Vendor Alpha (Tally)');
        $this->putJson("/api/v1/procurement/vendors/{$this->vendor->id}", ['tally_ledger_name' => null])
            ->assertOk()
            ->assertJsonPath('data.tally_ledger_name', null);
        $this->putJson("/api/v1/procurement/vendors/{$this->vendor->id}", ['tally_ledger_name' => str_repeat('x', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tally_ledger_name']);

        // The vendor block on an order shows it too.
        $this->putJson("/api/v1/procurement/vendors/{$this->vendor->id}", ['tally_ledger_name' => 'Vendor Alpha (Tally)'])->assertOk();
        $order = $this->draftOrder();
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.vendor.tally_ledger_name', 'Vendor Alpha (Tally)');
    }

    public function test_tally_staging_is_recorded_through_the_service_and_served_honestly(): void
    {
        $this->actAs();

        // Before send nobody has judged the order: null, not a state.
        $draft = $this->draftOrder();
        $this->getJson("/api/v1/procurement/purchase-orders/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.tally_staging', null)
            ->assertJsonPath('data.tally', null);

        // On send, TallySync's listener answers through recordTallyStaging.
        // With PO posting OFF (the default — owner gate Q35; phpunit.xml pins
        // it false) the honest word is 'disabled': no queue entry, no link.
        $order = $this->sentOrder();
        $this->assertFalse((bool) config('tally-sync.purchase_orders_enabled'));
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.tally_staging.state', 'disabled')
            ->assertJsonPath('data.tally_staging.reasons.0.code', 'purchase_orders_disabled')
            ->assertJsonPath('data.tally', null);
        $this->assertSame(0, DB::table('tally_sync_entries')->count());

        $service = app(PurchaseOrderService::class);
        $service->recordTallyStaging($order, [
            'state' => 'refused',
            'reasons' => [
                ['code' => 'party_unmapped', 'detail' => 'vendor has no Tally ledger name'],
                ['code' => 'item_unmapped', 'detail' => 'ITEM_A (#'.$this->itemA->id.')'],
            ],
        ]);

        $staging = $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")->assertOk()->json('data.tally_staging');
        $this->assertSame('refused', $staging['state']);
        $this->assertSame(['party_unmapped', 'item_unmapped'], array_column($staging['reasons'], 'code'));
        $this->assertNotEmpty($staging['at']);
        $this->assertArrayNotHasKey('entry_id', $staging);

        // A later staging replaces the earlier one (the latest word is the truth).
        $service->recordTallyStaging($order->fresh(), ['state' => 'enqueued', 'reasons' => [], 'entry_id' => 42]);
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.tally_staging.state', 'enqueued')
            ->assertJsonPath('data.tally_staging.entry_id', 42)
            ->assertJsonPath('data.tally_staging.reasons', []);

        // A state outside the vocabulary is refused before anything is written.
        $this->expectException(\InvalidArgumentException::class);
        $service->recordTallyStaging($order->fresh(), ['state' => 'posted']);
    }
}
