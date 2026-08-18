<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Core\Exports\CsvStreamer;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * POST /exports/{purchase_orders|purchase_order_lines|goods_receipts|
 * goods_receipt_lines} IS the matching GET /procurement/{list}, downloaded
 * (MASTER-PLAN Phase 4.5): the same filters (ListPurchaseOrdersRequest /
 * ListGoodsReceiptsRequest), the same documents in the same order, the same
 * document count as meta.total (the line kinds: every line of those
 * documents, in that order) — and FC-06 applied to the file exactly as to
 * the screen.
 *
 * A procurement-only reader's line file has NO unit_price / unit_cost and
 * NO amount column (absent, not blank — a blank would read as "this resin
 * cost nothing"), and the rate appears NOWHERE in any of their four files;
 * finance's line files carry the rate and the amount, cell for cell what
 * finance's screen shows. Supplier identity follows the resource: a
 * purchase order names its vendor to every procurement reader on screen,
 * so its file does; a receipt's resource names only its order, so its file
 * does too. The header files carry no rate for anyone — the header
 * resources have none.
 */
class ProcurementExportsTest extends TestCase
{
    use RefreshDatabase;

    private const RESIN_RATE = '96.5000';

    private const CAP_RATE = '1.2550';

    private const RECEIPT_RATE = '102.7500';

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

        $this->relpet = Vendor::create(['code' => 'SUP-RL', 'name' => 'Relpet Resins']);
        $this->capMasters = Vendor::create(['code' => 'SUP-CM', 'name' => 'Cap, "Masters"']);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);

        $this->orders['relpet_draft'] = $this->order($this->relpet, PurchaseOrderStatus::Draft, '2026-08-01', null, [$this->resin], 'urgent zebra consignment');
        $this->orders['relpet_sent'] = $this->order($this->relpet, PurchaseOrderStatus::Sent, '2026-08-05', '2026-08-20', [$this->resin]);
        $this->orders['cm_closed'] = $this->order($this->capMasters, PurchaseOrderStatus::Closed, '2026-08-10', '2026-08-12', [$this->resin, $this->cap]);

        $this->receipts['relpet_late'] = $this->receipt($this->orders['relpet_sent'], '2026-08-10 20:00:00', 'DC-RL-1', [$this->resin]);
        $this->receipts['cm_day'] = $this->receipt($this->orders['cm_closed'], '2026-08-08 09:00:00', 'DC-CM-7', [$this->resin, $this->cap]);
    }

    // ---- rows == the list ------------------------------------------------------

    public function test_each_file_carries_exactly_its_lists_documents_in_the_lists_order_for_the_same_filters(): void
    {
        $this->actAs(['procurement.view', 'finance.view']);

        $cases = [
            'purchase-orders' => [
                [],
                ['status' => 'sent'],
                ['vendor_id' => $this->relpet->id],
                ['from' => '2026-08-02', 'to' => '2026-08-09'],
                ['item_id' => $this->cap->id],
                ['q' => 'relpet'],
                ['q' => 'PO-'.$this->orders['cm_closed']->id],
                ['sort' => 'order_date'],
                ['sort' => '-expected_date'],
            ],
            'goods-receipts' => [
                [],
                ['vendor_id' => $this->relpet->id],
                ['purchase_order_id' => $this->orders['cm_closed']->id],
                ['from' => '2026-08-11'],
                ['to' => '2026-08-10'],
                ['item_id' => $this->cap->id],
                ['q' => 'dc-cm'],
                ['q' => 'GRN-'.$this->receipts['relpet_late']->id],
                ['sort' => 'received_date'],
            ],
        ];

        foreach ($cases as $list => $filterSets) {
            [$headerKind, $lineKind, $lineParent] = $list === 'purchase-orders'
                ? ['purchase_orders', 'purchase_order_lines', 'purchase_order_id']
                : ['goods_receipts', 'goods_receipt_lines', 'goods_receipt_note_id'];

            foreach ($filterSets as $filters) {
                $screen = $this->getJson("/api/v1/procurement/{$list}?per_page=1000&".http_build_query($filters))->assertOk();
                $label = "{$list}, filters: ".json_encode($filters);

                // The header file: one row per document, the list's ids in the list's order.
                $headers = $this->csv($this->postJson("/api/v1/exports/{$headerKind}", $filters)->assertOk());
                $this->assertSame(
                    array_column($screen->json('data'), 'id'),
                    array_map(fn (array $row) => (int) $row['id'], $headers['rows']),
                    "document ids and order — {$label}",
                );
                $this->assertSame($screen->json('meta.total'), count($headers['rows']), "row count == meta.total — {$label}");

                // The line file: every line of those documents, document by
                // document in the list's order, then the document's own line
                // order — exactly the list's `lines` flattened.
                $expectedLines = [];
                foreach ($screen->json('data') as $document) {
                    foreach ($document['lines'] as $line) {
                        $expectedLines[] = [$document['id'], $line['id']];
                    }
                }
                $lines = $this->csv($this->postJson("/api/v1/exports/{$lineKind}", $filters)->assertOk());
                $this->assertSame(
                    $expectedLines,
                    array_map(fn (array $row) => [(int) $row[$lineParent], (int) $row['line_id']], $lines['rows']),
                    "line ids and order — {$label}",
                );

                foreach ([$headers, $lines] as $csv) {
                    foreach ($csv['rows'] as $row) {
                        $this->assertCount(count($csv['headers']), $row, "every row has exactly the header's cells — {$label}");
                    }
                }
            }
        }
    }

    // ---- FC-06 on the file --------------------------------------------------------

    public function test_a_procurement_only_readers_files_carry_no_rate_column_and_no_rate_anywhere_but_do_name_the_vendor_the_screen_names(): void
    {
        $this->actAs(['procurement.view', 'procurement.manage']);

        $files = [];
        foreach (['purchase_orders', 'purchase_order_lines', 'goods_receipts', 'goods_receipt_lines'] as $kind) {
            $files[$kind] = $this->csv($this->postJson("/api/v1/exports/{$kind}", [])->assertOk());
        }

        // The columns, exactly — no rate, no amount, ABSENT not blank.
        $this->assertSame(
            ['id', 'status', 'source', 'tally_order_no', 'vendor_code', 'vendor_name', 'purchase_requisition_id', 'order_date', 'expected_date', 'lines_count', 'notes', 'created_at'],
            $files['purchase_orders']['headers'],
        );
        $this->assertSame(
            ['purchase_order_id', 'purchase_order_status', 'order_date', 'expected_date', 'vendor_code', 'vendor_name', 'line_id', 'item_sku', 'item_name', 'uom', 'quantity', 'quantity_received', 'schedules_count'],
            $files['purchase_order_lines']['headers'],
        );
        $this->assertSame(
            ['id', 'purchase_order_id', 'warehouse_code', 'warehouse_name', 'reference', 'receipt_note_reference', 'tracking_number', 'received_date', 'lines_count', 'material_lots_count', 'notes', 'created_at'],
            $files['goods_receipts']['headers'],
        );
        $this->assertSame(
            ['goods_receipt_note_id', 'purchase_order_id', 'received_date', 'warehouse_code', 'warehouse_name', 'reference', 'line_id', 'purchase_order_line_id', 'item_sku', 'item_name', 'uom', 'quantity', 'material_lots_count'],
            $files['goods_receipt_lines']['headers'],
        );

        // The rate appears NOWHERE — not as a header, not as a value.
        foreach ($files as $kind => $csv) {
            foreach (['unit_price', 'unit_cost', 'amount'] as $header) {
                $this->assertNotContains($header, $csv['headers'], "{$kind} grew a {$header} column");
            }
            foreach ([self::RESIN_RATE, self::CAP_RATE, self::RECEIPT_RATE, '1158000.00', '102.75', '96.5'] as $rate) {
                $this->assertStringNotContainsString($rate, $csv['raw'], "a purchase rate leaked into {$kind}");
            }
        }

        // …and every row is exactly as wide as the header (no row grew a cell
        // the header does not name).
        foreach ($files as $kind => $csv) {
            foreach ($csv['rows'] as $row) {
                $this->assertCount(count($csv['headers']), $row, $kind);
            }
        }

        // The vendor: on the purchase-order files, as on the screen of every
        // procurement reader; on the receipt files only the order is named,
        // as GoodsReceiptNoteResource names it (the screen's "PO #n").
        $sent = collect($files['purchase_orders']['rows'])->firstWhere('id', (string) $this->orders['relpet_sent']->id);
        $this->assertSame('SUP-RL', $sent['vendor_code']);
        $this->assertSame('Relpet Resins', $sent['vendor_name']);
        $this->assertSame('sent', $sent['status']);
        $this->assertSame('erp', $sent['source']);
        $this->assertSame('2026-08-05', $sent['order_date']);
        $this->assertSame('2026-08-20', $sent['expected_date']);
        $this->assertSame('1', $sent['lines_count']);
        $closed = collect($files['purchase_orders']['rows'])->firstWhere('id', (string) $this->orders['cm_closed']->id);
        $this->assertSame('Cap, "Masters"', $closed['vendor_name'], 'a comma and quotes survive the round trip');
        $this->assertSame('2', $closed['lines_count']);
        $draft = collect($files['purchase_orders']['rows'])->firstWhere('id', (string) $this->orders['relpet_draft']->id);
        $this->assertSame('urgent zebra consignment', $draft['notes'], 'notes ride on the file');
        $this->assertSame('', $draft['expected_date']);

        $late = collect($files['goods_receipts']['rows'])->firstWhere('id', (string) $this->receipts['relpet_late']->id);
        $this->assertSame((string) $this->orders['relpet_sent']->id, $late['purchase_order_id']);
        $this->assertSame('RM', $late['warehouse_code']);
        $this->assertSame('RM Store', $late['warehouse_name']);
        $this->assertSame('DC-RL-1', $late['reference']);
        $this->assertSame('2026-08-10T20:00:00+00:00', $late['received_date'], 'the instant, as the resource emits it');
        $this->assertSame('1', $late['lines_count']);
        $this->assertSame('0', $late['material_lots_count']);
        $this->assertArrayNotHasKey('vendor_name', $late);
    }

    public function test_finances_line_files_carry_the_rate_and_the_amount_exactly_as_finances_screen_does(): void
    {
        $this->actAs(['procurement.view', 'finance.view']);

        $orders = collect($this->getJson('/api/v1/procurement/purchase-orders?per_page=1000')->assertOk()->json('data'))->keyBy('id');
        $poLines = $this->csv($this->postJson('/api/v1/exports/purchase_order_lines', [])->assertOk());

        $this->assertSame(
            ['purchase_order_id', 'purchase_order_status', 'order_date', 'expected_date', 'vendor_code', 'vendor_name', 'line_id', 'item_sku', 'item_name', 'uom', 'quantity', 'quantity_received', 'unit_price', 'amount', 'schedules_count'],
            $poLines['headers'],
        );
        foreach ($poLines['rows'] as $row) {
            $order = $orders[(int) $row['purchase_order_id']];
            $line = collect($order['lines'])->firstWhere('id', (int) $row['line_id']);
            $this->assertSame((string) $order['status'], $row['purchase_order_status']);
            $this->assertSame((string) $order['order_date'], $row['order_date']);
            $this->assertSame((string) $order['vendor']['code'], $row['vendor_code']);
            $this->assertSame((string) $order['vendor']['name'], $row['vendor_name']);
            $this->assertSame((string) $line['item']['sku'], $row['item_sku']);
            $this->assertSame((string) $line['item']['name'], $row['item_name']);
            $this->assertSame((string) $line['item']['uom'], $row['uom']);
            $this->assertSame((string) $line['quantity'], $row['quantity']);
            $this->assertSame((string) $line['quantity_received'], $row['quantity_received']);
            $this->assertSame((string) $line['unit_price'], $row['unit_price'], 'the rate is the screen\'s rate');
            $this->assertSame((string) count($line['schedules']), $row['schedules_count']);
        }
        // The amount, pinned: 12000.0000 kg × 96.5000 and 5000.0000 × 1.2550,
        // as the purchase-order screen shows a line's amount (two places).
        $resin = collect($poLines['rows'])->first(fn (array $row) => $row['item_sku'] === 'RM-PET');
        $this->assertSame('96.5000', $resin['unit_price']);
        $this->assertSame('1158000.00', $resin['amount']);
        $capLine = collect($poLines['rows'])->first(fn (array $row) => $row['item_sku'] === 'CAP-28');
        $this->assertSame('1.2550', $capLine['unit_price']);
        $this->assertSame('6275.00', $capLine['amount']);

        $receipts = collect($this->getJson('/api/v1/procurement/goods-receipts?per_page=1000')->assertOk()->json('data'))->keyBy('id');
        $grnLines = $this->csv($this->postJson('/api/v1/exports/goods_receipt_lines', [])->assertOk());

        $this->assertSame(
            ['goods_receipt_note_id', 'purchase_order_id', 'received_date', 'warehouse_code', 'warehouse_name', 'reference', 'line_id', 'purchase_order_line_id', 'item_sku', 'item_name', 'uom', 'quantity', 'unit_cost', 'amount', 'material_lots_count'],
            $grnLines['headers'],
        );
        foreach ($grnLines['rows'] as $row) {
            $receipt = $receipts[(int) $row['goods_receipt_note_id']];
            $line = collect($receipt['lines'])->firstWhere('id', (int) $row['line_id']);
            $this->assertSame((string) $receipt['purchase_order_id'], $row['purchase_order_id']);
            $this->assertSame((string) $receipt['received_date'], $row['received_date']);
            $this->assertSame((string) $receipt['warehouse']['code'], $row['warehouse_code']);
            $this->assertSame((string) ($receipt['reference'] ?? ''), $row['reference']);
            $this->assertSame((string) $line['purchase_order_line_id'], $row['purchase_order_line_id']);
            $this->assertSame((string) $line['item']['sku'], $row['item_sku']);
            $this->assertSame((string) $line['quantity'], $row['quantity']);
            $this->assertSame((string) $line['unit_cost'], $row['unit_cost'], 'the rate is the screen\'s rate');
            $this->assertSame((string) count($line['material_lots']), $row['material_lots_count']);
        }
        $received = collect($grnLines['rows'])->first(fn (array $row) => (int) $row['goods_receipt_note_id'] === $this->receipts['relpet_late']->id);
        $this->assertSame('102.7500', $received['unit_cost']);
        $this->assertSame('102750.00', $received['amount'], '1000.0000 kg × 102.7500');

        // The header files still carry no rate for finance either — the
        // header resources have none, and the file follows the resource.
        foreach (['purchase_orders', 'goods_receipts'] as $kind) {
            $csv = $this->csv($this->postJson("/api/v1/exports/{$kind}", [])->assertOk());
            foreach (['unit_price', 'unit_cost', 'amount'] as $header) {
                $this->assertNotContains($header, $csv['headers'], $kind);
            }
        }
    }

    public function test_the_rate_columns_exist_for_exactly_the_readers_the_line_resource_serves_the_rate_to(): void
    {
        // finance.manage alone opens the gate too, procurement.manage alone
        // does not — the resource's own predicate, and the file agrees with
        // the screen for each.
        foreach ([
            [['procurement.view'], false],
            [['procurement.view', 'procurement.manage'], false],
            [['procurement.view', 'finance.view'], true],
            [['procurement.view', 'finance.manage'], true],
        ] as [$permissions, $sees]) {
            $this->app['auth']->forgetGuards();
            $this->actAs($permissions);

            $screenLine = $this->getJson('/api/v1/procurement/purchase-orders?per_page=1000')->assertOk()->json('data.0.lines.0');
            $this->assertSame($sees, array_key_exists('unit_price', $screenLine), 'screen: '.implode(',', $permissions));

            $file = $this->csv($this->postJson('/api/v1/exports/purchase_order_lines', [])->assertOk());
            $this->assertSame($sees, in_array('unit_price', $file['headers'], true), 'file: '.implode(',', $permissions));
            $this->assertSame($sees, in_array('amount', $file['headers'], true), 'file amount: '.implode(',', $permissions));

            $grnLine = $this->getJson('/api/v1/procurement/goods-receipts?per_page=1000')->assertOk()->json('data.0.lines.0');
            $this->assertSame($sees, array_key_exists('unit_cost', $grnLine), 'grn screen: '.implode(',', $permissions));
            $grnFile = $this->csv($this->postJson('/api/v1/exports/goods_receipt_lines', [])->assertOk());
            $this->assertSame($sees, in_array('unit_cost', $grnFile['headers'], true), 'grn file: '.implode(',', $permissions));
        }
    }

    // ---- grammar and standing --------------------------------------------------

    public function test_the_exports_read_the_lists_grammar_without_its_paging(): void
    {
        $this->actAs(['procurement.view']);

        $this->postJson('/api/v1/exports/purchase_orders', ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/exports/purchase_order_lines', ['from' => '2026-08-10', 'to' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson('/api/v1/exports/purchase_orders', ['sort' => 'received_date'])->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->postJson('/api/v1/exports/goods_receipts', ['sort' => 'order_date'])->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->postJson('/api/v1/exports/goods_receipt_lines', ['vendor_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
        $this->postJson('/api/v1/exports/goods_receipts', ['from' => '10/08/2026'])->assertUnprocessable()->assertJsonValidationErrors('from');

        // An export is the whole list: `page` and `per_page` are not part of
        // its grammar (nor of the catalogue's form), and a body that carries
        // them is not narrowed by them.
        $csv = $this->csv($this->postJson('/api/v1/exports/purchase_orders', ['per_page' => 1, 'page' => 2])->assertOk());
        $this->assertCount(3, $csv['rows']);

        $catalogue = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'));
        foreach (['purchase_orders', 'purchase_order_lines'] as $key) {
            $names = array_column($catalogue->firstWhere('key', $key)['filters'], 'name');
            $this->assertSame(['vendor_id', 'item_id', 'from', 'to', 'q', 'sort', 'status'], $names, $key);
        }
        foreach (['goods_receipts', 'goods_receipt_lines'] as $key) {
            $names = array_column($catalogue->firstWhere('key', $key)['filters'], 'name');
            $this->assertSame(['vendor_id', 'item_id', 'from', 'to', 'q', 'sort', 'purchase_order_id'], $names, $key);
        }
        $status = collect($catalogue->firstWhere('key', 'purchase_orders')['filters'])->firstWhere('name', 'status');
        $this->assertSame('select', $status['type']);
        $this->assertSame(['draft', 'sent', 'partially_received', 'closed', 'cancelled'], $status['options']);
    }

    public function test_the_kinds_are_catalogued_for_procurement_readers_only_and_refused_to_others(): void
    {
        $this->actAs(['procurement.view']);

        $catalogue = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'));
        foreach ([
            'purchase_orders' => 'Purchase orders',
            'purchase_order_lines' => 'Purchase order lines',
            'goods_receipts' => 'Goods receipts',
            'goods_receipt_lines' => 'Goods receipt lines',
        ] as $key => $label) {
            $kind = $catalogue->firstWhere('key', $key);
            $this->assertNotNull($kind, $key);
            $this->assertSame('procurement', $kind['module']);
            $this->assertSame($label, $kind['label']);
            $this->assertSame('available', $kind['status']);
        }

        $this->app['auth']->forgetGuards();

        // procurement.manage reads too (the module middleware's rule).
        $this->actAs(['procurement.manage']);
        $this->postJson('/api/v1/exports/goods_receipts', [])->assertOk();

        $this->app['auth']->forgetGuards();

        // A reader without procurement standing — finance included: the rate
        // gate opens a column, never the door — is not offered them and may
        // not run them.
        $this->actAs(['sales.view', 'finance.view', 'production.view']);
        $catalogue = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'));
        foreach (['purchase_orders', 'purchase_order_lines', 'goods_receipts', 'goods_receipt_lines'] as $key) {
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
    private function order(Vendor $vendor, PurchaseOrderStatus $status, string $orderDate, ?string $expected, array $items, ?string $notes = null): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'status' => $status,
            'order_date' => $orderDate,
            'expected_date' => $expected,
            'notes' => $notes,
        ]);
        foreach ($items as $item) {
            $order->lines()->create([
                'item_id' => $item->id,
                'quantity' => $item->is($this->resin) ? '12000' : '5000',
                'unit_price' => $item->is($this->resin) ? self::RESIN_RATE : self::CAP_RATE,
                'quantity_received' => 0,
            ]);
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
            $grn->lines()->create([
                'purchase_order_line_id' => $line->id,
                'item_id' => $item->id,
                'quantity' => '1000',
                'unit_cost' => $item->is($this->resin) ? self::RECEIPT_RATE : self::CAP_RATE,
            ]);
        }

        return $grn;
    }
}
