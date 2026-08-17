<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 4.5 — the two Procurement lists become SEARCHABLE and FILTERABLE,
 * server-side, through FormRequest-validated query strings, in the SAME
 * grammar as the Phase 3.5 Sales lists (SalesSearchFilterTest is the
 * mirror of this file). What must hold, per document:
 *
 *   - every documented filter narrows the list to exactly the rows it names;
 *   - `q` finds an order by its number in any spelling ("PO-12", "po 12",
 *     "12"), a receipt by its number or its reference, and either by the
 *     vendor's name or code — and never by notes;
 *   - `sort` accepts the documented columns (bare or "-" prefixed), defaults
 *     to newest id first, and refuses anything else with a 422;
 *   - `per_page` is 1..1000 (default 20) — 1000, not Sales' 100, because
 *     the `?po=` / `?grn=` deep links have always relied on it;
 *   - a malformed value is a 422 rather than a silently-empty or -full list;
 *   - an unknown query key is ignored;
 *   - an EMPTY query string is exactly the list every earlier caller got —
 *     unfiltered, newest first;
 *   - the whole surface is behind procurement.view (403 without it).
 *
 * The rows are built directly (no stock, no Tally) — these are list tests.
 */
class ProcurementListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $relpet;

    private Vendor $capMasters;

    private Item $resin;

    private Item $cap;

    private Warehouse $rm;

    /** @var array<string, PurchaseOrder> */
    private array $orders = [];

    /** @var array<string, GoodsReceiptNote> */
    private array $receipts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['procurement.view']);

        $this->relpet = Vendor::create(['code' => 'SUP-RL', 'name' => 'Relpet Resins']);
        $this->capMasters = Vendor::create(['code' => 'SUP-CM', 'name' => 'Cap Masters']);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);

        // Three orders: Relpet/draft/resin on 01-Aug, Relpet/sent/resin on
        // 05-Aug (expected 20-Aug), Cap Masters/closed/resin+cap on 10-Aug
        // (expected 12-Aug).
        $this->orders['relpet_draft'] = $this->order($this->relpet, PurchaseOrderStatus::Draft, '2026-08-01', null, [$this->resin]);
        $this->orders['relpet_sent'] = $this->order($this->relpet, PurchaseOrderStatus::Sent, '2026-08-05', '2026-08-20', [$this->resin]);
        $this->orders['cm_closed'] = $this->order($this->capMasters, PurchaseOrderStatus::Closed, '2026-08-10', '2026-08-12', [$this->resin, $this->cap]);

        // Receipts: one against Relpet's sent order (resin) stamped
        // 2026-08-10 20:00 UTC — 11-Aug 01:30 in the factory (IST) — and
        // one against Cap Masters' order (cap) at 2026-08-08 09:00 UTC.
        $this->receipts['relpet_late'] = $this->receipt($this->orders['relpet_sent'], '2026-08-10 20:00:00', 'DC-RL-1', [$this->resin]);
        $this->receipts['cm_day'] = $this->receipt($this->orders['cm_closed'], '2026-08-08 09:00:00', 'DC-CM-7', [$this->cap]);
    }

    // ---- purchase orders ------------------------------------------------------

    public function test_purchase_orders_filter_by_vendor_status_dates_and_item(): void
    {
        $this->assertIds(['relpet_draft', 'relpet_sent'], $this->orders, $this->list('purchase-orders', ['vendor_id' => $this->relpet->id]));
        $this->assertIds(['relpet_sent'], $this->orders, $this->list('purchase-orders', ['status' => 'sent']));
        $this->assertIds(['cm_closed'], $this->orders, $this->list('purchase-orders', ['status' => 'closed']));
        $this->assertIds(['relpet_sent', 'cm_closed'], $this->orders, $this->list('purchase-orders', ['from' => '2026-08-05']));
        $this->assertIds(['relpet_draft', 'relpet_sent'], $this->orders, $this->list('purchase-orders', ['to' => '2026-08-05']));
        $this->assertIds(['relpet_sent'], $this->orders, $this->list('purchase-orders', ['from' => '2026-08-02', 'to' => '2026-08-09']));
        $this->assertIds(['cm_closed'], $this->orders, $this->list('purchase-orders', ['item_id' => $this->cap->id]));
        $this->assertIds(['relpet_draft', 'relpet_sent', 'cm_closed'], $this->orders, $this->list('purchase-orders', ['item_id' => $this->resin->id]));
        $this->assertIds(['relpet_sent'], $this->orders, $this->list('purchase-orders', ['item_id' => $this->resin->id, 'vendor_id' => $this->relpet->id, 'status' => 'sent']));
    }

    public function test_purchase_orders_q_matches_the_number_in_any_spelling_and_the_vendor_but_never_notes(): void
    {
        $id = $this->orders['relpet_sent']->id;
        $this->orders['relpet_sent']->update(['notes' => 'urgent zebra consignment']);

        foreach (["PO-{$id}", "po {$id}", "po-{$id}", "PO{$id}", "PO#{$id}", (string) $id, " po  {$id} "] as $spelling) {
            $this->assertIds(['relpet_sent'], $this->orders, $this->list('purchase-orders', ['q' => $spelling]), "q={$spelling}");
        }

        $this->assertIds(['relpet_draft', 'relpet_sent'], $this->orders, $this->list('purchase-orders', ['q' => 'relpet']));
        $this->assertIds(['cm_closed'], $this->orders, $this->list('purchase-orders', ['q' => 'sup-cm']));
        $this->assertIds(['cm_closed'], $this->orders, $this->list('purchase-orders', ['q' => 'Cap Mast']));
        $this->assertIds([], $this->orders, $this->list('purchase-orders', ['q' => 'zebra']));
        $this->assertIds([], $this->orders, $this->list('purchase-orders', ['q' => "-{$id}"]), 'a bare dash is not a number');
        $this->assertIds([], $this->orders, $this->list('purchase-orders', ['q' => 'nobody-by-this-name']));
        // The typed % is a character, not a wildcard.
        $this->assertIds([], $this->orders, $this->list('purchase-orders', ['q' => '%%%']));
    }

    public function test_purchase_orders_sort_defaults_to_newest_first_and_honours_the_documented_columns(): void
    {
        $this->assertOrder(['cm_closed', 'relpet_sent', 'relpet_draft'], $this->orders, $this->list('purchase-orders'));
        $this->assertOrder(['relpet_draft', 'relpet_sent', 'cm_closed'], $this->orders, $this->list('purchase-orders', ['sort' => 'id']));
        $this->assertOrder(['relpet_draft', 'relpet_sent', 'cm_closed'], $this->orders, $this->list('purchase-orders', ['sort' => 'order_date']));
        $this->assertOrder(['cm_closed', 'relpet_sent', 'relpet_draft'], $this->orders, $this->list('purchase-orders', ['sort' => '-order_date']));
        // expected_date: 12-Aug, 20-Aug, then the undated order — undated
        // LAST in either direction, as on the sales lists.
        $this->assertOrder(['cm_closed', 'relpet_sent', 'relpet_draft'], $this->orders, $this->list('purchase-orders', ['sort' => 'expected_date']));
        $this->assertOrder(['relpet_sent', 'cm_closed', 'relpet_draft'], $this->orders, $this->list('purchase-orders', ['sort' => '-expected_date']));

        $this->getJson('/api/v1/procurement/purchase-orders?sort=vendor_name')->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->getJson('/api/v1/procurement/purchase-orders?sort=received_date')->assertStatus(422)->assertJsonValidationErrors(['sort']);
    }

    public function test_purchase_orders_refuse_malformed_filters_and_ignore_unknown_keys(): void
    {
        $this->getJson('/api/v1/procurement/purchase-orders?status=shipped')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/procurement/purchase-orders?from=2026-08-10&to=2026-08-01')->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/procurement/purchase-orders?from=10/08/2026')->assertStatus(422)->assertJsonValidationErrors(['from']);
        $this->getJson('/api/v1/procurement/purchase-orders?vendor_id=abc')->assertStatus(422)->assertJsonValidationErrors(['vendor_id']);
        $this->getJson('/api/v1/procurement/purchase-orders?item_id=0')->assertStatus(422)->assertJsonValidationErrors(['item_id']);

        // Unknown keys are not an error — a stale tab's query string still loads.
        $this->getJson('/api/v1/procurement/purchase-orders?foo=bar&warehouse=3')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_per_page_is_bounded_one_to_one_thousand_defaults_to_twenty_and_the_links_carry_the_filters(): void
    {
        $this->assertSame(20, $this->getJson('/api/v1/procurement/purchase-orders')->assertOk()->json('meta.per_page'));
        $this->assertSame(1, $this->getJson('/api/v1/procurement/purchase-orders?per_page=1')->assertOk()->json('meta.per_page'));
        // The `?po=` deep link's page size — kept.
        $this->assertSame(1000, $this->getJson('/api/v1/procurement/purchase-orders?per_page=1000')->assertOk()->json('meta.per_page'));
        $this->assertSame(1000, $this->getJson('/api/v1/procurement/goods-receipts?per_page=1000')->assertOk()->json('meta.per_page'));

        $page = $this->getJson('/api/v1/procurement/purchase-orders?per_page=2&page=2')->assertOk();
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.current_page'));
        $this->assertCount(1, $page->json('data'));

        $this->getJson('/api/v1/procurement/purchase-orders?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/procurement/purchase-orders?per_page=1001')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/procurement/goods-receipts?per_page=1001')->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $next = $this->list('purchase-orders', ['per_page' => 1, 'vendor_id' => $this->relpet->id, 'q' => 'relpet'])->json('links.next');
        $this->assertNotNull($next);
        $this->assertStringContainsString('vendor_id='.$this->relpet->id, $next);
        $this->assertStringContainsString('q=relpet', $next);
        $this->assertStringContainsString('page=2', $next);
    }

    // ---- goods receipts ----------------------------------------------------------

    public function test_goods_receipts_filter_by_vendor_order_dates_in_factory_time_and_item(): void
    {
        $this->assertIds(['relpet_late'], $this->receipts, $this->list('goods-receipts', ['vendor_id' => $this->relpet->id]));
        $this->assertIds(['cm_day'], $this->receipts, $this->list('goods-receipts', ['purchase_order_id' => $this->orders['cm_closed']->id]));
        $this->assertIds(['cm_day'], $this->receipts, $this->list('goods-receipts', ['item_id' => $this->cap->id]));
        $this->assertIds(['relpet_late'], $this->receipts, $this->list('goods-receipts', ['item_id' => $this->resin->id]));

        // 2026-08-10 20:00 UTC is 11-Aug 01:30 IST: the factory received it
        // on the 11th, so a range ending on the 10th must NOT include it and
        // one starting on the 11th must — exactly as a delivery is filed.
        $this->assertIds(['cm_day'], $this->receipts, $this->list('goods-receipts', ['to' => '2026-08-10']));
        $this->assertIds(['relpet_late'], $this->receipts, $this->list('goods-receipts', ['from' => '2026-08-11']));
        $this->assertIds(['relpet_late'], $this->receipts, $this->list('goods-receipts', ['from' => '2026-08-11', 'to' => '2026-08-11']));
        $this->assertIds(['cm_day'], $this->receipts, $this->list('goods-receipts', ['from' => '2026-08-08', 'to' => '2026-08-08']));
        $this->assertIds([], $this->receipts, $this->list('goods-receipts', ['from' => '2026-08-09', 'to' => '2026-08-10']));

        // The boundary instant: 2026-08-11 18:30:00 UTC IS 12-Aug 00:00 IST.
        $this->receipts['midnight'] = $this->receipt($this->orders['cm_closed'], '2026-08-11 18:30:00', 'DC-CM-8', [$this->cap]);
        $this->assertIds(['relpet_late'], $this->receipts, $this->list('goods-receipts', ['from' => '2026-08-11', 'to' => '2026-08-11']), 'to=11th excludes the 12th 00:00 IST');
        $this->assertIds(['midnight'], $this->receipts, $this->list('goods-receipts', ['from' => '2026-08-12', 'to' => '2026-08-12']), 'from=12th includes 00:00 IST');
    }

    public function test_goods_receipts_q_matches_number_reference_and_vendor_and_sorts_by_received_date(): void
    {
        $id = $this->receipts['cm_day']->id;
        $this->receipts['cm_day']->update(['notes' => 'zebra pallet']);

        foreach (["GRN-{$id}", "grn {$id}", "GRN{$id}", (string) $id] as $spelling) {
            $this->assertIds(['cm_day'], $this->receipts, $this->list('goods-receipts', ['q' => $spelling]), "q={$spelling}");
        }
        $this->assertIds(['relpet_late'], $this->receipts, $this->list('goods-receipts', ['q' => 'dc-rl']));
        $this->assertIds(['relpet_late'], $this->receipts, $this->list('goods-receipts', ['q' => 'Relpet']));
        $this->assertIds(['cm_day'], $this->receipts, $this->list('goods-receipts', ['q' => 'SUP-CM']));
        $this->assertIds([], $this->receipts, $this->list('goods-receipts', ['q' => 'zebra']));

        $this->assertOrder(['cm_day', 'relpet_late'], $this->receipts, $this->list('goods-receipts'));
        $this->assertOrder(['cm_day', 'relpet_late'], $this->receipts, $this->list('goods-receipts', ['sort' => 'received_date']));
        $this->assertOrder(['relpet_late', 'cm_day'], $this->receipts, $this->list('goods-receipts', ['sort' => '-received_date']));
        $this->assertOrder(['relpet_late', 'cm_day'], $this->receipts, $this->list('goods-receipts', ['sort' => 'id']));

        $this->getJson('/api/v1/procurement/goods-receipts?sort=order_date')->assertStatus(422)->assertJsonValidationErrors(['sort']);
        // A receipt has no status of its own: the key is unknown here, and ignored.
        $this->getJson('/api/v1/procurement/goods-receipts?status=draft')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/procurement/goods-receipts?from=2026-08-12&to=2026-08-01')->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/procurement/goods-receipts?purchase_order_id=abc')->assertStatus(422)->assertJsonValidationErrors(['purchase_order_id']);
    }

    // ---- backward compatibility and standing -------------------------------------

    public function test_an_empty_query_string_is_the_old_unfiltered_newest_first_list_with_the_old_payload(): void
    {
        $orders = $this->getJson('/api/v1/procurement/purchase-orders')->assertOk();
        $this->assertOrder(['cm_closed', 'relpet_sent', 'relpet_draft'], $this->orders, $orders);
        $this->assertSame(['id', 'code', 'name'], array_slice(array_keys($orders->json('data.0.vendor')), 0, 3), 'the vendor still rides on every order');
        $this->assertArrayHasKey('lines', $orders->json('data.0'));
        $this->assertArrayHasKey('schedules', $orders->json('data.0.lines.0'));

        $receipts = $this->getJson('/api/v1/procurement/goods-receipts')->assertOk();
        $this->assertOrder(['cm_day', 'relpet_late'], $this->receipts, $receipts);
        $this->assertArrayHasKey('warehouse', $receipts->json('data.0'));
        $this->assertArrayHasKey('lines', $receipts->json('data.0'));
        $this->assertArrayHasKey('material_lots', $receipts->json('data.0'));
    }

    public function test_the_lists_are_behind_procurement_view(): void
    {
        $this->actingWith(['sales.view', 'production.view']);

        foreach (['purchase-orders', 'goods-receipts'] as $list) {
            $this->getJson("/api/v1/procurement/{$list}")->assertForbidden();
            $this->getJson("/api/v1/procurement/{$list}?q=relpet")->assertForbidden();
        }

        // procurement.manage reads too (the module middleware's rule).
        $this->actingWith(['procurement.manage']);
        $this->getJson('/api/v1/procurement/purchase-orders?vendor_id='.$this->relpet->id)->assertOk()->assertJsonCount(2, 'data');
    }

    // ---- fixtures ------------------------------------------------------------------

    /** @param  list<Item>  $items */
    private function order(Vendor $vendor, PurchaseOrderStatus $status, string $orderDate, ?string $expected, array $items): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'status' => $status,
            'order_date' => $orderDate,
            'expected_date' => $expected,
        ]);
        foreach ($items as $item) {
            $order->lines()->create(['item_id' => $item->id, 'quantity' => '12000', 'unit_price' => '96.5000', 'quantity_received' => 0]);
        }

        return $order;
    }

    /** @param  list<Item>  $items */
    private function receipt(PurchaseOrder $order, string $receivedAtUtc, string $reference, array $items): GoodsReceiptNote
    {
        $grn = GoodsReceiptNote::create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->rm->id,
            'reference' => $reference,
            'received_date' => $receivedAtUtc,
        ]);
        foreach ($items as $item) {
            $line = $order->lines()->where('item_id', $item->id)->first();
            $grn->lines()->create(['purchase_order_line_id' => $line->id, 'item_id' => $item->id, 'quantity' => '1000', 'unit_cost' => '96.5000']);
        }

        return $grn;
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $query */
    private function list(string $path, array $query = []): TestResponse
    {
        return $this->getJson("/api/v1/procurement/{$path}".($query === [] ? '' : '?'.http_build_query($query)))->assertOk();
    }

    /**
     * @param  list<string>  $expectedKeys
     * @param  array<string, Model>  $fixtures
     */
    private function assertIds(array $expectedKeys, array $fixtures, TestResponse $response, string $message = ''): void
    {
        $expected = collect($expectedKeys)->map(fn ($key) => $fixtures[$key]->id)->sort()->values()->all();
        $actual = collect($response->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $actual, $message);
        $this->assertSame(count($expected), $response->json('meta.total'), $message);
    }

    /**
     * @param  list<string>  $expectedKeys
     * @param  array<string, Model>  $fixtures
     */
    private function assertOrder(array $expectedKeys, array $fixtures, TestResponse $response, string $message = ''): void
    {
        $expected = collect($expectedKeys)->map(fn ($key) => $fixtures[$key]->id)->all();
        $actual = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame($expected, $actual, $message);
    }
}
