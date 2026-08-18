<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Core\Exports\CsvStreamer;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * POST /exports/{sales_orders|deliveries|invoices} IS the matching
 * GET /sales/{list}, downloaded (MASTER-PLAN Phase 4.5): the same filters
 * (the Phase 3.5 List*Request grammar), the same rows in the same order,
 * the same row count as meta.total — and every cell the resource's own
 * value for that key, as the list emitted it (customer by code and name,
 * the model's totals and counts, the Tally link's public tier). Nothing
 * FC-06 lives on a sales document, so the columns are the same for every
 * sales reader; the surface is behind sales.view/manage exactly as the
 * lists are.
 */
class SalesExportsTest extends TestCase
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

        $this->aqua = Customer::create(['code' => 'CUST-AQ', 'name' => 'Aqua Traders']);
        $this->blue = Customer::create(['code' => 'CUST-BL', 'name' => 'Blue, "Bottlers"']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        // The SalesSearchFilterTest fixture, plus notes (they are the user's
        // own and ride on the file) and a Tally entry on one delivery and
        // one issued invoice, so the link's public tier has something to say.
        $this->orders['aqua_draft'] = $this->order($this->aqua, SalesOrderStatus::Draft, '2026-08-01', null, [$this->bottle], 'urgent zebra shipment');
        $this->orders['aqua_confirmed'] = $this->order($this->aqua, SalesOrderStatus::Confirmed, '2026-08-05', '2026-08-20', [$this->cap]);
        $this->orders['blue_completed'] = $this->order($this->blue, SalesOrderStatus::Completed, '2026-08-10', '2026-08-12', [$this->bottle, $this->cap]);

        $this->deliveries['aqua_late'] = $this->delivery($this->orders['aqua_confirmed'], '2026-08-10 20:00:00', 'TRUCK-A', [$this->cap]);
        $this->deliveries['blue_day'] = $this->delivery($this->orders['blue_completed'], '2026-08-08 09:00:00', 'TRUCK-B', [$this->bottle]);
        $this->tallyEntry($this->deliveries['blue_day'], 'Delivery Note', TallySyncStatus::Synced, '2026-08-08 10:00:00');

        $this->invoices['aqua_draft'] = $this->invoice($this->orders['aqua_confirmed'], InvoiceStatus::Draft, '2026-08-06', [$this->cap]);
        $this->invoices['aqua_issued'] = $this->invoice($this->orders['aqua_confirmed'], InvoiceStatus::Issued, '2026-08-12', [$this->cap]);
        $this->invoices['blue_paid'] = $this->invoice($this->orders['blue_completed'], InvoiceStatus::Paid, '2026-08-11', [$this->bottle]);
        $this->tallyEntry($this->invoices['aqua_issued'], 'Sales', TallySyncStatus::Pending, null);
    }

    // ---- rows == the list ------------------------------------------------------

    public function test_each_file_carries_exactly_its_lists_rows_in_the_lists_order_for_the_same_filters(): void
    {
        $this->actAs(['sales.view']);

        $cases = [
            'sales_orders' => ['sales-orders', [
                [],
                ['status' => 'confirmed'],
                ['customer_id' => $this->aqua->id],
                ['from' => '2026-08-02', 'to' => '2026-08-09'],
                ['item_id' => $this->bottle->id],
                ['q' => 'aqua'],
                ['q' => 'SO-'.$this->orders['blue_completed']->id],
                ['sort' => 'order_date'],
                ['sort' => '-expected_date'],
            ]],
            'deliveries' => ['deliveries', [
                [],
                ['customer_id' => $this->blue->id],
                ['sales_order_id' => $this->orders['aqua_confirmed']->id],
                ['from' => '2026-08-11'],
                ['to' => '2026-08-10'],
                ['item_id' => $this->cap->id],
                ['q' => 'truck-a'],
                ['sort' => 'delivered_date'],
            ]],
            'invoices' => ['invoices', [
                [],
                ['status' => 'issued'],
                ['customer_id' => $this->aqua->id],
                ['from' => '2026-08-11', 'to' => '2026-08-11'],
                ['item_id' => $this->bottle->id],
                ['q' => 'cust-bl'],
                ['sort' => 'invoice_date'],
            ]],
        ];

        foreach ($cases as $kind => [$list, $filterSets]) {
            foreach ($filterSets as $filters) {
                $screen = $this->getJson("/api/v1/sales/{$list}?per_page=100&".http_build_query($filters))->assertOk();
                $csv = $this->csv($this->postJson("/api/v1/exports/{$kind}", $filters)->assertOk());
                $label = "{$kind}, filters: ".json_encode($filters);

                $this->assertSame(
                    array_column($screen->json('data'), 'id'),
                    array_map(fn (array $row) => (int) $row['id'], $csv['rows']),
                    "ids and order — {$label}",
                );
                $this->assertSame($screen->json('meta.total'), count($csv['rows']), "row count == meta.total — {$label}");
                foreach ($csv['rows'] as $row) {
                    $this->assertCount(count($csv['headers']), $row, "every row has exactly the header's cells — {$label}");
                }
            }
        }
    }

    // ---- cells == the resource ---------------------------------------------------

    public function test_a_sales_order_row_is_the_resource_flattened(): void
    {
        $this->actAs(['sales.view']);

        $screen = collect($this->getJson('/api/v1/sales/sales-orders?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $csv = $this->csv($this->postJson('/api/v1/exports/sales_orders', [])->assertOk());

        $this->assertSame(
            ['id', 'document_number', 'status', 'customer_code', 'customer_name', 'order_date', 'expected_date', 'ordered_quantity', 'delivered_quantity', 'invoiced_quantity', 'lines_count', 'deliveries_count', 'invoices_count', 'can_cancel', 'notes', 'created_at'],
            $csv['headers'],
        );

        foreach ($csv['rows'] as $row) {
            $order = $screen[(int) $row['id']];
            $this->assertSame($order['document_number'], $row['document_number']);
            $this->assertSame($order['status'], $row['status']);
            $this->assertSame((string) $order['customer']['code'], $row['customer_code']);
            $this->assertSame((string) $order['customer']['name'], $row['customer_name']);
            $this->assertSame((string) $order['order_date'], $row['order_date']);
            $this->assertSame((string) ($order['expected_date'] ?? ''), $row['expected_date']);
            $this->assertSame((string) $order['totals']['ordered_quantity'], $row['ordered_quantity']);
            $this->assertSame((string) $order['totals']['delivered_quantity'], $row['delivered_quantity']);
            $this->assertSame((string) $order['totals']['invoiced_quantity'], $row['invoiced_quantity']);
            $this->assertSame((string) count($order['lines']), $row['lines_count']);
            $this->assertSame((string) $order['deliveries_count'], $row['deliveries_count']);
            $this->assertSame((string) $order['invoices_count'], $row['invoices_count']);
            $this->assertSame($order['can_cancel'] ? 'true' : 'false', $row['can_cancel']);
            $this->assertSame((string) ($order['notes'] ?? ''), $row['notes']);
            $this->assertSame((string) $order['created_at'], $row['created_at']);
        }

        // Pinned by value too, so a resource that silently changed what a
        // key means would be caught here and not only by the mirror above.
        $blue = collect($csv['rows'])->firstWhere('id', (string) $this->orders['blue_completed']->id);
        $this->assertSame('Blue, "Bottlers"', $blue['customer_name'], 'a comma and quotes survive the CSV round trip');
        $this->assertSame('CUST-BL', $blue['customer_code']);
        $this->assertSame('completed', $blue['status']);
        $this->assertSame('200.0000', $blue['ordered_quantity']);
        $this->assertSame('2', $blue['lines_count']);
        $this->assertSame('1', $blue['deliveries_count']);
        $this->assertSame('1', $blue['invoices_count']);
        $this->assertSame('false', $blue['can_cancel']);
        $draft = collect($csv['rows'])->firstWhere('id', (string) $this->orders['aqua_draft']->id);
        $this->assertSame('urgent zebra shipment', $draft['notes'], 'notes ride on the file');
        $this->assertSame('true', $draft['can_cancel']);
        $this->assertSame('', $draft['expected_date']);
    }

    public function test_a_delivery_row_carries_its_order_customer_warehouse_cartons_and_tally_link_as_the_screen_does(): void
    {
        $this->actAs(['sales.view']);

        $screen = collect($this->getJson('/api/v1/sales/deliveries?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $csv = $this->csv($this->postJson('/api/v1/exports/deliveries', [])->assertOk());

        $this->assertSame(
            ['id', 'document_number', 'sales_order_number', 'sales_order_status', 'customer_code', 'customer_name', 'warehouse_code', 'warehouse_name', 'reference', 'delivered_date', 'lines_count', 'carton_count', 'tally_status', 'tally_voucher_number', 'tally_synced_at', 'notes', 'created_at'],
            $csv['headers'],
        );

        foreach ($csv['rows'] as $row) {
            $delivery = $screen[(int) $row['id']];
            $this->assertSame($delivery['document_number'], $row['document_number']);
            $this->assertSame((string) $delivery['sales_order']['document_number'], $row['sales_order_number']);
            $this->assertSame((string) $delivery['sales_order']['status'], $row['sales_order_status']);
            $this->assertSame((string) $delivery['customer']['code'], $row['customer_code']);
            $this->assertSame((string) $delivery['customer']['name'], $row['customer_name']);
            $this->assertSame((string) $delivery['warehouse']['code'], $row['warehouse_code']);
            $this->assertSame((string) $delivery['warehouse']['name'], $row['warehouse_name']);
            $this->assertSame((string) ($delivery['reference'] ?? ''), $row['reference']);
            $this->assertSame((string) $delivery['delivered_date'], $row['delivered_date']);
            $this->assertSame((string) count($delivery['lines']), $row['lines_count']);
            $this->assertSame((string) $delivery['carton_count'], $row['carton_count']);
            $this->assertSame((string) ($delivery['tally']['status'] ?? ''), $row['tally_status']);
            $this->assertSame((string) ($delivery['tally']['voucher_number'] ?? ''), $row['tally_voucher_number']);
            $this->assertSame((string) ($delivery['tally']['synced_at'] ?? ''), $row['tally_synced_at']);
        }

        $synced = collect($csv['rows'])->firstWhere('id', (string) $this->deliveries['blue_day']->id);
        $this->assertSame('synced', $synced['tally_status']);
        $this->assertSame('DN-'.$this->deliveries['blue_day']->id, $synced['tally_voucher_number']);
        $this->assertSame('2026-08-08T10:00:00+00:00', $synced['tally_synced_at']);
        $this->assertSame('TRUCK-B', $synced['reference']);
        $this->assertSame('0', $synced['carton_count'], 'a typed delivery has no scanned cartons');
        $unlinked = collect($csv['rows'])->firstWhere('id', (string) $this->deliveries['aqua_late']->id);
        $this->assertSame('', $unlinked['tally_status'], 'no entry → no fabricated status');
        $this->assertSame('', $unlinked['tally_voucher_number']);
        $this->assertSame('2026-08-10T20:00:00+00:00', $unlinked['delivered_date'], 'the instant, as the resource emits it (ISO-8601, UTC)');
    }

    public function test_an_invoice_row_carries_its_order_customer_dates_and_tally_link_as_the_screen_does(): void
    {
        $this->actAs(['sales.view']);

        $screen = collect($this->getJson('/api/v1/sales/invoices?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $csv = $this->csv($this->postJson('/api/v1/exports/invoices', [])->assertOk());

        $this->assertSame(
            ['id', 'document_number', 'status', 'sales_order_number', 'customer_code', 'customer_name', 'invoice_date', 'due_date', 'lines_count', 'tally_status', 'tally_voucher_number', 'tally_synced_at', 'notes', 'created_at'],
            $csv['headers'],
        );

        foreach ($csv['rows'] as $row) {
            $invoice = $screen[(int) $row['id']];
            $this->assertSame($invoice['document_number'], $row['document_number']);
            $this->assertSame($invoice['status'], $row['status']);
            $this->assertSame((string) $invoice['sales_order']['document_number'], $row['sales_order_number']);
            $this->assertSame((string) $invoice['customer']['code'], $row['customer_code']);
            $this->assertSame((string) $invoice['customer']['name'], $row['customer_name']);
            $this->assertSame((string) $invoice['invoice_date'], $row['invoice_date']);
            $this->assertSame((string) ($invoice['due_date'] ?? ''), $row['due_date']);
            $this->assertSame((string) count($invoice['lines']), $row['lines_count']);
            $this->assertSame((string) ($invoice['tally']['status'] ?? ''), $row['tally_status']);
            $this->assertSame((string) ($invoice['tally']['voucher_number'] ?? ''), $row['tally_voucher_number']);
        }

        $issued = collect($csv['rows'])->firstWhere('id', (string) $this->invoices['aqua_issued']->id);
        $this->assertSame('pending', $issued['tally_status']);
        $this->assertSame('INV-'.$this->invoices['aqua_issued']->id, $issued['tally_voucher_number']);
        $draft = collect($csv['rows'])->firstWhere('id', (string) $this->invoices['aqua_draft']->id);
        $this->assertSame('', $draft['tally_status'], 'a draft has no entry');
    }

    // ---- grammar and standing --------------------------------------------------

    public function test_the_exports_read_the_lists_grammar_without_its_paging(): void
    {
        $this->actAs(['sales.view']);

        $this->postJson('/api/v1/exports/sales_orders', ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/exports/sales_orders', ['from' => '2026-08-10', 'to' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson('/api/v1/exports/sales_orders', ['sort' => 'delivered_date'])->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->postJson('/api/v1/exports/deliveries', ['sort' => 'order_date'])->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->postJson('/api/v1/exports/deliveries', ['customer_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/v1/exports/invoices', ['status' => 'cancelled'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/exports/invoices', ['from' => '10/08/2026'])->assertUnprocessable()->assertJsonValidationErrors('from');

        // An export is the whole list: `page` and `per_page` are not part of
        // its grammar (nor of the catalogue's form), and a body that carries
        // them is not narrowed by them.
        $csv = $this->csv($this->postJson('/api/v1/exports/sales_orders', ['per_page' => 1, 'page' => 2])->assertOk());
        $this->assertCount(3, $csv['rows']);

        foreach (['sales_orders', 'deliveries', 'invoices'] as $key) {
            $kind = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->firstWhere('key', $key);
            $names = array_column($kind['filters'], 'name');
            $this->assertNotContains('per_page', $names, $key);
            $this->assertNotContains('page', $names, $key);
            $this->assertContains('q', $names, $key);
            $this->assertContains('from', $names, $key);
            $this->assertContains('to', $names, $key);
            $this->assertContains('customer_id', $names, $key);
            $this->assertContains('item_id', $names, $key);
            $this->assertContains('sort', $names, $key);
        }
    }

    public function test_the_kinds_are_catalogued_for_sales_readers_only_and_refused_to_others(): void
    {
        $this->actAs(['sales.view']);

        $catalogue = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'));
        foreach (['sales_orders' => 'Sales orders', 'deliveries' => 'Deliveries', 'invoices' => 'Invoices'] as $key => $label) {
            $kind = $catalogue->firstWhere('key', $key);
            $this->assertNotNull($kind, $key);
            $this->assertSame('sales', $kind['module']);
            $this->assertSame($label, $kind['label']);
            $this->assertSame('available', $kind['status']);
        }
        $status = collect($catalogue->firstWhere('key', 'sales_orders')['filters'])->firstWhere('name', 'status');
        $this->assertSame('select', $status['type']);
        $this->assertSame(['draft', 'confirmed', 'partially_delivered', 'completed', 'cancelled'], $status['options']);

        $this->app['auth']->forgetGuards();

        // sales.manage reads too (the module middleware's rule).
        $this->actAs(['sales.manage']);
        $this->postJson('/api/v1/exports/deliveries', [])->assertOk();

        $this->app['auth']->forgetGuards();

        // A reader without sales standing is not offered them and may not run them.
        $this->actAs(['procurement.view', 'production.view']);
        $catalogue = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'));
        foreach (['sales_orders', 'deliveries', 'invoices'] as $key) {
            $this->assertNull($catalogue->firstWhere('key', $key), $key);
            $this->postJson("/api/v1/exports/{$key}", [])->assertForbidden();
        }
    }

    // ---- helpers ------------------------------------------------------------------

    /**
     * The streamed file, parsed: BOM off, CRLF rows, cells by header.
     *
     * @return array{raw: string, headers: list<string>, rows: list<array<string, string>>}
     */
    private function csv(TestResponse $response): array
    {
        $raw = $response->streamedContent();
        $this->assertStringStartsWith(CsvStreamer::BOM, $raw);
        $body = substr($raw, strlen(CsvStreamer::BOM));
        $this->assertStringEndsWith("\r\n", $body);

        $lines = explode("\r\n", rtrim($body, "\r\n"));
        $headers = str_getcsv(array_shift($lines), ',', '"', '');
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = array_combine($headers, str_getcsv($line, ',', '"', ''));
        }

        return ['raw' => $raw, 'headers' => $headers, 'rows' => $rows];
    }

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param  list<Item>  $items */
    private function order(Customer $customer, SalesOrderStatus $status, string $orderDate, ?string $expected, array $items, ?string $notes = null): SalesOrder
    {
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => $status,
            'order_date' => $orderDate,
            'expected_date' => $expected,
            'notes' => $notes,
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
            $line = $order->lines()->where('item_id', $item->id)->first();
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

    /** The queue row TallySyncLinkService links a document to — written directly; no Tally is touched. */
    private function tallyEntry(Delivery|Invoice $document, string $voucherType, TallySyncStatus $status, ?string $syncedAt): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => $document->getMorphClass(),
            'syncable_id' => $document->id,
            'tally_voucher_type' => $voucherType,
            'payload' => ['voucher_number' => $document->documentNumber()],
            'status' => $status,
            'attempts' => 0,
            'synced_at' => $syncedAt,
        ]);
    }
}
