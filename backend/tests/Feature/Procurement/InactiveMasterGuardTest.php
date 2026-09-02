<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHAT A NEW PURCHASE-ORDER LINE MAY NAME — enforced at the API, not at the
 * dropdown.
 *
 * The picker half of this rule lives in `purchasableItemOptions`
 * (purchaseOrders.ts) and a picker is not a guard: it can be bypassed by any
 * client that posts JSON, and the point of the Configuration Lifecycle
 * Contract is that the server refuses what the screen declines to offer. So
 * every case here goes over the wire.
 *
 * THE ARCHIVED-ITEM RULE IS UNCHANGED: an ARCHIVED item may not be put on a
 * new line, on create or on amend, whatever its category.
 *
 * THE CATEGORY RULE IS NEW. DEC-20260902-023 settled the open half of Q59
 * this file used to pin: an ERP-entered document (a requisition, or a
 * purchase order whose `source` is not `tally`) now refuses a finished good
 * outright and demands a reason for an unclassified item —
 * `App\Modules\Procurement\Support\PurchaseLineEligibility`, pinned in full
 * by `PurchaseLineEligibilityTest` rather than duplicated case-by-case here.
 * `test_create_now_refuses_a_finished_good` and
 * `test_an_unclassified_item_is_still_purchasable_with_a_reason` below are
 * this file's share of that reconciliation.
 *
 * IT REACHES AMEND TOO, SCOPED TO A NEW OR CHANGED LINE.
 * `test_amend_now_refuses_a_new_finished_good_line` and
 * `test_amend_now_refuses_a_new_unclassified_line_without_a_reason` pin the
 * refusal; `test_amend_keeps_an_existing_unclassified_line_unchanged_without_a_reason`
 * pins the one deliberate carve-out — a line already on the order,
 * resubmitted with the same item, quantity and unit_price, is grandfathered
 * rather than forced through a rule that postdates it. See
 * `AmendPurchaseOrderRequest::withValidator()` for exactly how "unchanged" is
 * decided.
 *
 * Fixture values are synthetic (FC-06). Nothing here posts to Tally or moves
 * stock beyond the one goods receipt a test needs.
 */
class InactiveMasterGuardTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private Item $rawMaterial;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        // The Receipt Note listener would enqueue a Tally entry off the GRNs
        // below; that queue is not this test's subject.
        Event::fake([GoodsReceiptNoteReceived::class]);

        $this->vendor = Vendor::create(['code' => 'V-ALPHA', 'name' => 'Vendor Alpha']);
        $this->rawMaterial = Item::create([
            'sku' => 'ITEM_RM',
            'name' => 'Item RM',
            'uom' => 'Kgs',
            'category' => ItemCategory::RawMaterial,
        ]);
        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']);

        $this->actAs();
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
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
            'lines' => [
                ['item_id' => $this->rawMaterial->id, 'quantity' => '100', 'unit_price' => '1.00'],
            ],
        ], $overrides);
    }

    private function postOrder(array $overrides = [])
    {
        return $this->postJson('/api/v1/procurement/purchase-orders', $this->orderPayload($overrides));
    }

    /** One line naming $item, in the shape create() and amend() both take. */
    private function lineFor(Item $item, ?string $unclassifiedReason = null): array
    {
        return array_filter([
            'item_id' => $item->id,
            'quantity' => '10',
            'unit_price' => '1.00',
            'unclassified_reason' => $unclassifiedReason,
        ], fn ($value) => $value !== null);
    }

    // ---- refusals: create ---------------------------------------------------------

    public function test_create_refuses_an_archived_item(): void
    {
        $archived = Item::create(['sku' => 'ITEM_OLD', 'name' => 'Item Old', 'uom' => 'Kgs', 'is_active' => false]);

        $this->postOrder(['lines' => [$this->lineFor($archived)]])
            ->assertStatus(422)
            // The SENTENCE, not just the key: the two refusals say different
            // things for different reasons, and a cleanup that collapsed them
            // into one message would also have collapsed the reasoning.
            ->assertJsonValidationErrors(['lines.0.item_id' => 'is archived']);
    }

    public function test_create_refuses_a_soft_deleted_item(): void
    {
        // `exists:items,id` queries the TABLE and applies no model scope, so a
        // trashed row passes it. The rule therefore has to look with
        // withTrashed() — a plain find() returns null for a trashed row and
        // the guard would wave it through on the "id is missing, exists
        // already said so" branch.
        $deleted = Item::create(['sku' => 'ITEM_GONE', 'name' => 'Item Gone', 'uom' => 'Kgs']);
        $deleted->delete();
        $this->assertSoftDeleted($deleted);

        $this->postOrder(['lines' => [['item_id' => $deleted->id, 'quantity' => '10', 'unit_price' => '1.00']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.item_id' => 'is archived']);
    }

    public function test_amend_refuses_a_soft_deleted_item(): void
    {
        $order = $this->draftOrder();
        $deleted = Item::create(['sku' => 'ITEM_GONE', 'name' => 'Item Gone', 'uom' => 'Kgs']);
        $deleted->delete();

        $this->amend($order, [['item_id' => $deleted->id, 'quantity' => '10', 'unit_price' => '1.00']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.item_id' => 'is archived']);
    }

    public function test_the_refusal_names_the_offending_line_not_just_the_order(): void
    {
        $archived = Item::create(['sku' => 'ITEM_OLD', 'name' => 'Item Old', 'uom' => 'Kgs', 'is_active' => false]);

        // Second line, so a message keyed to the first would be caught.
        $this->postOrder(['lines' => [$this->lineFor($this->rawMaterial), $this->lineFor($archived)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.1.item_id')
            ->assertJsonMissingValidationErrors('lines.0.item_id');
    }

    public function test_create_now_refuses_a_finished_good(): void
    {
        // Was "still purchasable" while Q59(a) was open; DEC-20260902-023
        // answers it. A dedicated behaviour pin lives in
        // PurchaseLineEligibilityTest — this is this file's reconciliation of
        // the negative control it used to carry.
        $bottle = Item::create([
            'sku' => 'BTL-PET-1000',
            'name' => 'Bottle PET 1000',
            'uom' => 'Nos.',
            'category' => ItemCategory::FinishedGood,
        ]);

        $this->postOrder(['lines' => [$this->lineFor($bottle)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.item_id');
    }

    // ---- refusals: amend ----------------------------------------------------------

    public function test_amend_refuses_an_archived_item_the_original_could_not_have_carried(): void
    {
        $order = $this->draftOrder();
        $archived = Item::create(['sku' => 'ITEM_OLD', 'name' => 'Item Old', 'uom' => 'Kgs', 'is_active' => false]);

        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/amend", [
            'lines' => [$this->lineFor($archived)],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.item_id');
    }

    public function test_amend_now_refuses_a_new_unclassified_line_without_a_reason(): void
    {
        // DEC-20260902-023 reaches amend for a NEW line — this item was
        // never on the order before, so it is a fresh choice, not history.
        $order = $this->draftOrder();
        $unclassified = Item::create(['sku' => 'ITEM_NEW', 'name' => 'Item New', 'uom' => 'Kgs']);
        $this->assertNull($unclassified->fresh()->category);

        $this->amend($order, [$this->lineFor($unclassified)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.unclassified_reason');

        // The refused amend touched nothing.
        $this->assertSame([$this->rawMaterial->id], $order->fresh()->lines->pluck('item_id')->all());
    }

    public function test_amend_accepts_a_new_unclassified_line_with_a_reason(): void
    {
        $order = $this->draftOrder();
        $unclassified = Item::create(['sku' => 'ITEM_NEW2', 'name' => 'Item New 2', 'uom' => 'Kgs']);

        $this->amend($order, [$this->lineFor($unclassified, 'Trial sample, not yet classified')])
            ->assertOk();

        $this->assertSame([$unclassified->id], $order->fresh()->lines->pluck('item_id')->all());
        $this->assertSame('Trial sample, not yet classified', $order->fresh()->lines()->firstOrFail()->unclassified_reason);
    }

    public function test_amend_keeps_an_existing_unclassified_line_unchanged_without_a_reason(): void
    {
        // A DRAFT WRITTEN BEFORE THIS RULE EXISTED (simulated by writing the
        // line directly, bypassing StorePurchaseOrderRequest's own
        // reason-required check). Re-submitting it UNCHANGED — same item,
        // quantity, unit_price — during an otherwise-ordinary amend must not
        // suddenly demand a reason nobody was ever asked for when the line
        // was written. That is the carve-out the class docblock describes.
        $order = PurchaseOrder::create([
            'vendor_id' => $this->vendor->id,
            'status' => PurchaseOrderStatus::Draft,
            'order_date' => '2026-08-10',
        ]);
        $unclassified = Item::create(['sku' => 'ITEM_HISTORIC', 'name' => 'Item Historic', 'uom' => 'Kgs']);
        $order->lines()->create(['item_id' => $unclassified->id, 'quantity' => '10', 'unit_price' => '1.00', 'quantity_received' => 0]);

        $this->amend($order, [$this->lineFor($unclassified)])->assertOk();

        $this->assertSame([$unclassified->id], $order->fresh()->lines->pluck('item_id')->all());
        $this->assertNull($order->fresh()->lines()->firstOrFail()->unclassified_reason);
    }

    public function test_amend_now_refuses_a_new_finished_good_line(): void
    {
        // Create refuses a finished good (DEC-20260902-023); amend now does
        // too, for a NEW line — this item was never on the order before.
        $order = $this->draftOrder();
        $bottle = Item::create([
            'sku' => 'BTL-PET-500',
            'name' => 'Bottle PET 500',
            'uom' => 'Nos.',
            'category' => ItemCategory::FinishedGood,
        ]);

        $this->amend($order, [$this->lineFor($bottle)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.item_id');

        $this->assertSame([$this->rawMaterial->id], $order->fresh()->lines->pluck('item_id')->all());
    }

    public function test_amend_is_refused_on_a_tally_mirror_whatever_the_line_names(): void
    {
        // WHY THE AMEND RULE IS UNCONDITIONAL while create's is scoped to
        // ERP-entered orders. amend() refuses a mirror outright
        // (PurchaseOrderService::amend, isTallyMirror), so the item rule has
        // no mirror scope to widen — it cannot be the thing that refuses a
        // mirror amend, because a perfectly live item is refused too. This
        // pins that, so the justification is not a bare comment; if amend
        // ever opens to mirrors, this fails and the scoping question
        // (Q53(c)-shaped) gets asked rather than answered by drift.
        $mirror = PurchaseOrder::findOrFail($this->postOrder([
            'source' => 'tally',
            'tally_order_no' => 'MIRROR-0002',
        ])->assertCreated()->json('data.id'));

        $this->amend($mirror, [$this->lineFor($this->rawMaterial)])->assertStatus(422);
    }

    public function test_amend_accepts_a_consumable_or_spare(): void
    {
        $order = $this->draftOrder();
        $spare = Item::create([
            'sku' => 'SPARE-02',
            'name' => 'Machine Spare',
            'uom' => 'Nos.',
            'category' => ItemCategory::Other,
        ]);

        $this->amend($order, [$this->lineFor($spare)])->assertOk();

        $this->assertSame([$spare->id], $order->fresh()->lines->pluck('item_id')->all());
    }

    public function test_a_draft_whose_item_was_archived_can_only_be_amended_by_re_pointing_the_line(): void
    {
        // THE CONTRACT THE PICKER SHOWS. A draft raised while the item was
        // live keeps its line and stays READABLE — nothing rewrites history.
        // But an amend re-submits the lines, so re-sending the archived item
        // is refused and the only way through is to point the line at a live
        // one. That is exactly what the picker offers: the archived item is
        // shown `(Retired)` and disabled, every active item is choosable.
        $order = $this->draftOrder();
        $this->rawMaterial->update(['is_active' => false]);

        // Reading the order is untouched by the archive.
        $this->getJson("/api/v1/procurement/purchase-orders/{$order->id}")->assertOk();

        // Re-sending the line as it stands is refused.
        $this->amend($order, [$this->lineFor($this->rawMaterial)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.item_id' => 'is archived']);

        // Re-pointing it at a live item goes through.
        // Classified, not testing DEC-20260902-023 here: an unclassified
        // re-point would need its own reason and muddy what this test pins.
        $live = Item::create(['sku' => 'ITEM_LIVE', 'name' => 'Item Live', 'uom' => 'Kgs', 'category' => ItemCategory::RawMaterial]);
        $this->amend($order, [$this->lineFor($live)])->assertOk();

        $this->assertSame([$live->id], $order->fresh()->lines->pluck('item_id')->all());
    }

    // ---- refusals: the store an arrival is booked into ----------------------------

    public function test_a_goods_receipt_refuses_a_retired_store(): void
    {
        $order = $this->sentOrder();
        $retired = Warehouse::create(['code' => 'OLD-STORE', 'name' => 'Old Store', 'is_active' => false]);

        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'rk-retired-store',
            'purchase_order_id' => $order->id,
            'warehouse_id' => $retired->id,
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'quantity' => '10',
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');
    }

    // ---- negative controls: what these guards deliberately do NOT refuse ----------

    public function test_an_unclassified_item_is_still_purchasable_with_a_reason(): void
    {
        // Q59(d), settled by DEC-20260902-023: NULL means "nobody has said
        // yet" and most of the catalogue is NULL — refusing it outright would
        // stop real purchases of real materials. It is purchasable WITH a
        // reason now (PurchaseLineEligibilityTest pins the without-a-reason
        // refusal); this still-purchasable control lives on here with that
        // one change.
        $unclassified = Item::create(['sku' => 'ITEM_NEW', 'name' => 'Item New', 'uom' => 'Kgs']);
        $this->assertNull($unclassified->fresh()->category);

        $this->postOrder(['lines' => [$this->lineFor($unclassified, 'Consumable, not yet classified')]])->assertCreated();
    }

    public function test_a_consumable_or_spare_is_still_purchasable(): void
    {
        $spare = Item::create([
            'sku' => 'SPARE-01',
            'name' => 'Machine Spare',
            'uom' => 'Nos.',
            'category' => ItemCategory::Other,
        ]);

        $this->postOrder(['lines' => [$this->lineFor($spare)]])->assertCreated();
    }

    public function test_a_packing_material_is_still_purchasable(): void
    {
        $carton = Item::create([
            'sku' => 'CARTON-01',
            'name' => 'Carton',
            'uom' => 'Nos.',
            'category' => ItemCategory::PackingMaterial,
        ]);

        $this->postOrder(['lines' => [$this->lineFor($carton)]])->assertCreated();
    }

    public function test_a_tally_mirror_still_accepts_an_archived_item(): void
    {
        // The mirror is a READ-ONLY reflection of an order Tally already
        // holds; an ERP refusal cannot unmake that order, only leave the ERP
        // unable to show it. Same scope the retired-VENDOR rule already has
        // (Q53(c)) — this guard did not widen it.
        $archived = Item::create(['sku' => 'ITEM_OLD', 'name' => 'Item Old', 'uom' => 'Kgs', 'is_active' => false]);

        $this->postOrder([
            'source' => 'tally',
            'tally_order_no' => 'MIRROR-0001',
            'lines' => [$this->lineFor($archived)],
        ])->assertCreated();
    }

    public function test_goods_still_arrive_against_an_order_whose_item_was_archived_afterwards(): void
    {
        // The lorry is at the gate. The order was legitimate when it was
        // raised, and archiving the item afterwards must not strand stock
        // that has physically arrived — a goods receipt names a PO LINE, not
        // a fresh choice of item.
        $order = $this->sentOrder();
        $this->rawMaterial->update(['is_active' => false]);

        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'rk-after-archive',
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->store->id,
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'quantity' => '10',
            ]],
        ])->assertCreated();
    }

    public function test_send_still_works_when_the_vendor_was_retired_after_the_draft(): void
    {
        // TRACED AND DELIBERATELY UNGUARDED. `send()` is a status transition
        // over a choice already made, not a new write, and a draft that could
        // not be sent would have no path forward on the screen — no re-point,
        // no override, only cancel-and-retype. Whether a retired vendor
        // should strand an existing draft is an owner question, not a
        // refusal to add quietly. This test states today's answer so a change
        // to it is deliberate rather than drift.
        $order = $this->draftOrder();
        $this->vendor->update(['is_active' => false]);

        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/send")->assertOk();
    }

    // ---- fixtures -----------------------------------------------------------------

    private function draftOrder(): PurchaseOrder
    {
        return PurchaseOrder::findOrFail($this->postOrder()->assertCreated()->json('data.id'));
    }

    /** @param list<array<string, mixed>> $lines */
    private function amend(PurchaseOrder $order, array $lines)
    {
        return $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/amend", ['lines' => $lines]);
    }

    private function sentOrder(): PurchaseOrder
    {
        $order = $this->draftOrder();
        $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/send")->assertOk();

        return $order->fresh();
    }
}
