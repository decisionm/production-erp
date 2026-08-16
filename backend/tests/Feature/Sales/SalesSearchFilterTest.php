<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 3.5 — the three Sales lists become SEARCHABLE and FILTERABLE, server-
 * side, through FormRequest-validated query strings (the contract in
 * phase35-contract.md, "List filters"). What must hold, per document:
 *
 *   - every documented filter narrows the list to exactly the rows it names;
 *   - `q` finds a document by its number in any spelling ("SO-12", "so 12",
 *     "12"), a delivery by its reference, and any document by its customer's
 *     name or code — and never by notes;
 *   - `sort` accepts the documented columns (bare or "-" prefixed), defaults
 *     to newest id first, and refuses anything else with a 422;
 *   - `per_page` is 1..100 (default 20); out of range is a 422, not a clamp;
 *   - a malformed value (unknown status, reversed date range, non-date) is a
 *     422 rather than a silently-empty or silently-full list;
 *   - an unknown query key is ignored;
 *   - the whole surface is behind sales.view (403 without it).
 *
 * The rows are built directly (no stock, no Tally) — these are list tests;
 * the trace and Tally links are SalesDocumentShowTest's business.
 */
class SalesSearchFilterTest extends TestCase
{
    use RefreshDatabase;

    private Customer $aqua;

    private Customer $blue;

    private Item $bottle;

    private Item $cap;

    private Warehouse $fg;

    /** @var array<string, SalesOrder> */
    private array $orders = [];

    /** @var array<string, Delivery> */
    private array $deliveries = [];

    /** @var array<string, Invoice> */
    private array $invoices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['sales.view']);

        $this->aqua = Customer::create(['code' => 'CUST-AQ', 'name' => 'Aqua Traders']);
        $this->blue = Customer::create(['code' => 'CUST-BL', 'name' => 'Blue Bottlers']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        // Three orders: Aqua/draft/bottle on 01-Aug, Aqua/confirmed/cap on
        // 05-Aug (expected 20-Aug), Blue/completed/bottle+cap on 10-Aug
        // (expected 12-Aug).
        $this->orders['aqua_draft'] = $this->order($this->aqua, SalesOrderStatus::Draft, '2026-08-01', null, [$this->bottle]);
        $this->orders['aqua_confirmed'] = $this->order($this->aqua, SalesOrderStatus::Confirmed, '2026-08-05', '2026-08-20', [$this->cap]);
        $this->orders['blue_completed'] = $this->order($this->blue, SalesOrderStatus::Completed, '2026-08-10', '2026-08-12', [$this->bottle, $this->cap]);

        // Deliveries: one for Aqua's confirmed order (cap) stamped 2026-08-10
        // 20:00 UTC — which is 11-Aug 01:30 in the factory (IST) — and one for
        // Blue's order (bottle) at 2026-08-08 09:00 UTC (08-Aug IST).
        $this->deliveries['aqua_late'] = $this->delivery($this->orders['aqua_confirmed'], '2026-08-10 20:00:00', 'TRUCK-A', [$this->cap]);
        $this->deliveries['blue_day'] = $this->delivery($this->orders['blue_completed'], '2026-08-08 09:00:00', 'TRUCK-B', [$this->bottle]);

        // Invoices: Aqua draft (cap, 06-Aug), Aqua issued (cap, 12-Aug),
        // Blue paid (bottle, 11-Aug).
        $this->invoices['aqua_draft'] = $this->invoice($this->orders['aqua_confirmed'], InvoiceStatus::Draft, '2026-08-06', [$this->cap]);
        $this->invoices['aqua_issued'] = $this->invoice($this->orders['aqua_confirmed'], InvoiceStatus::Issued, '2026-08-12', [$this->cap]);
        $this->invoices['blue_paid'] = $this->invoice($this->orders['blue_completed'], InvoiceStatus::Paid, '2026-08-11', [$this->bottle]);
    }

    // ---- sales orders -----------------------------------------------------

    public function test_sales_orders_filter_by_customer_status_dates_and_item(): void
    {
        $this->assertIds(['aqua_draft', 'aqua_confirmed'], $this->orders, $this->list('sales-orders', ['customer_id' => $this->aqua->id]));
        $this->assertIds(['aqua_confirmed'], $this->orders, $this->list('sales-orders', ['status' => 'confirmed']));
        $this->assertIds(['aqua_confirmed', 'blue_completed'], $this->orders, $this->list('sales-orders', ['from' => '2026-08-05']));
        $this->assertIds(['aqua_draft', 'aqua_confirmed'], $this->orders, $this->list('sales-orders', ['to' => '2026-08-05']));
        $this->assertIds(['aqua_confirmed'], $this->orders, $this->list('sales-orders', ['from' => '2026-08-02', 'to' => '2026-08-09']));
        $this->assertIds(['aqua_draft', 'blue_completed'], $this->orders, $this->list('sales-orders', ['item_id' => $this->bottle->id]));
        $this->assertIds(['blue_completed'], $this->orders, $this->list('sales-orders', ['item_id' => $this->cap->id, 'customer_id' => $this->blue->id]));
    }

    public function test_sales_orders_q_matches_the_number_in_any_spelling_and_the_customer_but_never_notes(): void
    {
        $id = $this->orders['aqua_confirmed']->id;
        $this->orders['aqua_confirmed']->update(['notes' => 'urgent zebra shipment']);

        foreach (["SO-{$id}", "so {$id}", "so-{$id}", "SO{$id}", (string) $id, " so  {$id} "] as $spelling) {
            $this->assertIds(['aqua_confirmed'], $this->orders, $this->list('sales-orders', ['q' => $spelling]), "q={$spelling}");
        }

        $this->assertIds(['aqua_draft', 'aqua_confirmed'], $this->orders, $this->list('sales-orders', ['q' => 'aqua']));
        $this->assertIds(['blue_completed'], $this->orders, $this->list('sales-orders', ['q' => 'cust-bl']));
        $this->assertIds([], $this->orders, $this->list('sales-orders', ['q' => 'zebra']));
        $this->assertIds([], $this->orders, $this->list('sales-orders', ['q' => 'nobody-by-this-name']));
    }

    public function test_sales_orders_sort_defaults_to_newest_first_and_honours_the_documented_columns(): void
    {
        $this->assertOrder(['blue_completed', 'aqua_confirmed', 'aqua_draft'], $this->orders, $this->list('sales-orders'));
        $this->assertOrder(['aqua_draft', 'aqua_confirmed', 'blue_completed'], $this->orders, $this->list('sales-orders', ['sort' => 'id']));
        $this->assertOrder(['blue_completed', 'aqua_confirmed', 'aqua_draft'], $this->orders, $this->list('sales-orders', ['sort' => '-id']));
        $this->assertOrder(['aqua_draft', 'aqua_confirmed', 'blue_completed'], $this->orders, $this->list('sales-orders', ['sort' => 'order_date']));
        $this->assertOrder(['blue_completed', 'aqua_confirmed', 'aqua_draft'], $this->orders, $this->list('sales-orders', ['sort' => '-order_date']));
        // expected_date: 12-Aug, 20-Aug, then the undated order — undated
        // LAST in either direction (a promise-date sort that opened with
        // the orders that have no promise would be nobody's sort).
        $this->assertOrder(['blue_completed', 'aqua_confirmed', 'aqua_draft'], $this->orders, $this->list('sales-orders', ['sort' => 'expected_date']));
        $this->assertOrder(['aqua_confirmed', 'blue_completed', 'aqua_draft'], $this->orders, $this->list('sales-orders', ['sort' => '-expected_date']));

        $this->getJson('/api/v1/sales/sales-orders?sort=customer_name')->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->getJson('/api/v1/sales/sales-orders?sort=delivered_date')->assertStatus(422)->assertJsonValidationErrors(['sort']);
    }

    public function test_sales_orders_refuse_malformed_filters_and_ignore_unknown_keys(): void
    {
        $this->getJson('/api/v1/sales/sales-orders?status=shipped')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/sales/sales-orders?from=2026-08-10&to=2026-08-01')->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/sales/sales-orders?from=10/08/2026')->assertStatus(422)->assertJsonValidationErrors(['from']);
        $this->getJson('/api/v1/sales/sales-orders?customer_id=abc')->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
        $this->getJson('/api/v1/sales/sales-orders?item_id=0')->assertStatus(422)->assertJsonValidationErrors(['item_id']);

        // Unknown keys are not an error — a stale tab's query string still loads.
        $this->getJson('/api/v1/sales/sales-orders?foo=bar&warehouse=3')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_per_page_is_bounded_one_to_one_hundred_and_defaults_to_twenty(): void
    {
        $this->assertSame(20, $this->getJson('/api/v1/sales/sales-orders')->assertOk()->json('meta.per_page'));
        $this->assertSame(1, $this->getJson('/api/v1/sales/sales-orders?per_page=1')->assertOk()->json('meta.per_page'));
        $this->assertSame(100, $this->getJson('/api/v1/sales/sales-orders?per_page=100')->assertOk()->json('meta.per_page'));

        $page = $this->getJson('/api/v1/sales/sales-orders?per_page=2&page=2')->assertOk();
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.current_page'));
        $this->assertCount(1, $page->json('data'));

        $this->getJson('/api/v1/sales/sales-orders?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/sales/sales-orders?per_page=101')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/sales/deliveries?per_page=101')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/sales/invoices?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    // ---- deliveries -------------------------------------------------------

    public function test_deliveries_filter_by_customer_order_dates_in_factory_time_and_item(): void
    {
        $this->assertIds(['aqua_late'], $this->deliveries, $this->list('deliveries', ['customer_id' => $this->aqua->id]));
        $this->assertIds(['blue_day'], $this->deliveries, $this->list('deliveries', ['sales_order_id' => $this->orders['blue_completed']->id]));
        $this->assertIds(['aqua_late'], $this->deliveries, $this->list('deliveries', ['item_id' => $this->cap->id]));

        // 2026-08-10 20:00 UTC is 11-Aug 01:30 IST: the factory delivered it
        // on the 11th, so a range ending on the 10th must NOT include it and
        // one starting on the 11th must.
        $this->assertIds(['blue_day'], $this->deliveries, $this->list('deliveries', ['to' => '2026-08-10']));
        $this->assertIds(['aqua_late'], $this->deliveries, $this->list('deliveries', ['from' => '2026-08-11']));
        $this->assertIds(['aqua_late'], $this->deliveries, $this->list('deliveries', ['from' => '2026-08-11', 'to' => '2026-08-11']));
        $this->assertIds(['blue_day'], $this->deliveries, $this->list('deliveries', ['from' => '2026-08-08', 'to' => '2026-08-08']));
        $this->assertIds([], $this->deliveries, $this->list('deliveries', ['from' => '2026-08-09', 'to' => '2026-08-10']));
    }

    public function test_deliveries_q_matches_number_reference_and_customer_and_sorts_by_delivered_date(): void
    {
        $id = $this->deliveries['blue_day']->id;

        foreach (["DN-{$id}", "dn {$id}", "DN{$id}", (string) $id] as $spelling) {
            $this->assertIds(['blue_day'], $this->deliveries, $this->list('deliveries', ['q' => $spelling]), "q={$spelling}");
        }
        $this->assertIds(['aqua_late'], $this->deliveries, $this->list('deliveries', ['q' => 'truck-a']));
        $this->assertIds(['blue_day'], $this->deliveries, $this->list('deliveries', ['q' => 'Blue Bott']));
        $this->assertIds(['aqua_late'], $this->deliveries, $this->list('deliveries', ['q' => 'CUST-AQ']));

        $this->assertOrder(['blue_day', 'aqua_late'], $this->deliveries, $this->list('deliveries'));
        $this->assertOrder(['blue_day', 'aqua_late'], $this->deliveries, $this->list('deliveries', ['sort' => 'delivered_date']));
        $this->assertOrder(['aqua_late', 'blue_day'], $this->deliveries, $this->list('deliveries', ['sort' => '-delivered_date']));
        $this->assertOrder(['aqua_late', 'blue_day'], $this->deliveries, $this->list('deliveries', ['sort' => 'id']));

        $this->getJson('/api/v1/sales/deliveries?sort=order_date')->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->getJson('/api/v1/sales/deliveries?status=draft')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/sales/deliveries?from=2026-08-12&to=2026-08-01')->assertStatus(422)->assertJsonValidationErrors(['to']);
    }

    // ---- invoices ---------------------------------------------------------

    public function test_invoices_filter_by_customer_order_status_dates_and_item(): void
    {
        $this->assertIds(['aqua_draft', 'aqua_issued'], $this->invoices, $this->list('invoices', ['customer_id' => $this->aqua->id]));
        $this->assertIds(['blue_paid'], $this->invoices, $this->list('invoices', ['sales_order_id' => $this->orders['blue_completed']->id]));
        $this->assertIds(['aqua_issued'], $this->invoices, $this->list('invoices', ['status' => 'issued']));
        $this->assertIds(['blue_paid'], $this->invoices, $this->list('invoices', ['status' => 'paid']));
        $this->assertIds(['aqua_issued', 'blue_paid'], $this->invoices, $this->list('invoices', ['from' => '2026-08-11']));
        $this->assertIds(['aqua_draft', 'blue_paid'], $this->invoices, $this->list('invoices', ['to' => '2026-08-11']));
        $this->assertIds(['blue_paid'], $this->invoices, $this->list('invoices', ['from' => '2026-08-11', 'to' => '2026-08-11']));
        $this->assertIds(['blue_paid'], $this->invoices, $this->list('invoices', ['item_id' => $this->bottle->id]));

        $this->getJson('/api/v1/sales/invoices?status=cancelled')->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_invoices_q_matches_number_and_customer_and_sorts_by_invoice_date(): void
    {
        $id = $this->invoices['aqua_issued']->id;

        foreach (["INV-{$id}", "inv {$id}", "Inv{$id}", (string) $id] as $spelling) {
            $this->assertIds(['aqua_issued'], $this->invoices, $this->list('invoices', ['q' => $spelling]), "q={$spelling}");
        }
        $this->assertIds(['blue_paid'], $this->invoices, $this->list('invoices', ['q' => 'blue']));
        $this->assertIds(['aqua_draft', 'aqua_issued'], $this->invoices, $this->list('invoices', ['q' => 'cust-aq']));

        $this->assertOrder(['blue_paid', 'aqua_issued', 'aqua_draft'], $this->invoices, $this->list('invoices'));
        $this->assertOrder(['aqua_draft', 'blue_paid', 'aqua_issued'], $this->invoices, $this->list('invoices', ['sort' => 'invoice_date']));
        $this->assertOrder(['aqua_issued', 'blue_paid', 'aqua_draft'], $this->invoices, $this->list('invoices', ['sort' => '-invoice_date']));

        $this->getJson('/api/v1/sales/invoices?sort=expected_date')->assertStatus(422)->assertJsonValidationErrors(['sort']);
    }

    // ---- permission -------------------------------------------------------

    public function test_the_lists_are_behind_sales_view(): void
    {
        $this->actingWith(['production.view']);

        foreach (['sales-orders', 'deliveries', 'invoices'] as $list) {
            $this->getJson("/api/v1/sales/{$list}")->assertForbidden();
            $this->getJson("/api/v1/sales/{$list}?q=aqua")->assertForbidden();
        }

        // sales.manage reads too (the module middleware's rule).
        $this->actingWith(['sales.manage']);
        $this->getJson('/api/v1/sales/sales-orders?customer_id='.$this->aqua->id)->assertOk()->assertJsonCount(2, 'data');
    }

    // ---- fixtures ---------------------------------------------------------

    /** @param  list<Item>  $items */
    private function order(Customer $customer, SalesOrderStatus $status, string $orderDate, ?string $expected, array $items): SalesOrder
    {
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => $status,
            'order_date' => $orderDate,
            'expected_date' => $expected,
        ]);
        foreach ($items as $item) {
            $order->lines()->create(['item_id' => $item->id, 'quantity' => '100', 'unit_price' => '4.50', 'quantity_delivered' => 0]);
        }

        return $order;
    }

    /** @param  list<Item>  $items */
    private function delivery(SalesOrder $order, string $deliveredAtUtc, string $reference, array $items): Delivery
    {
        $delivery = Delivery::create([
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'reference' => $reference,
            'delivered_date' => $deliveredAtUtc,
        ]);
        foreach ($items as $item) {
            $line = $order->lines->firstWhere('item_id', $item->id) ?? $order->lines()->where('item_id', $item->id)->first();
            $delivery->lines()->create(['sales_order_line_id' => $line->id, 'item_id' => $item->id, 'quantity' => '10']);
        }

        return $delivery;
    }

    /** @param  list<Item>  $items */
    private function invoice(SalesOrder $order, InvoiceStatus $status, string $date, array $items): Invoice
    {
        $invoice = Invoice::create([
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'status' => $status,
            'invoice_date' => $date,
        ]);
        foreach ($items as $item) {
            $line = $order->lines()->where('item_id', $item->id)->first();
            $invoice->lines()->create(['sales_order_line_id' => $line->id, 'item_id' => $item->id, 'quantity' => '10', 'unit_price' => '4.50']);
        }

        return $invoice;
    }

    /** @param  array<string, mixed>  $query */
    private function list(string $path, array $query = []): TestResponse
    {
        return $this->getJson("/api/v1/sales/{$path}".($query === [] ? '' : '?'.http_build_query($query)))->assertOk();
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
    private function assertOrder(array $expectedKeys, array $fixtures, TestResponse $response): void
    {
        $expected = collect($expectedKeys)->map(fn ($key) => $fixtures[$key]->id)->values()->all();

        $this->assertSame($expected, collect($response->json('data'))->pluck('id')->values()->all());
    }

    private function actingWith(array $permissions): static
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $this;
    }
}
