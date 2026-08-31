<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Core\Exports\CsvStreamer;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * POST /exports/tally_sync_entries IS GET /tally-sync/entries, downloaded
 * (MASTER-PLAN Phase 4.5): the same filters, the same rows in the same
 * order, the same row count as meta.total — and FC-06 applied to the file
 * exactly as to the screen. A tally-sync-only reader's file has NO party
 * and NO error_message column (absent, not blank: the resource withholds
 * both on a supplier-party voucher for that reader, and a blank would read
 * as "no party" / "no error"); finance's file carries both, exactly as
 * finance's screen does. The vendor's name appears nowhere in the file of
 * a reader who may not see who supplied what.
 */
class TallySyncEntriesExportTest extends TestCase
{
    use RefreshDatabase;

    private const VENDOR = 'Reliance Industries';

    private const VENDOR_GSTIN = '27AAACR1234A1Z5';

    private const REJECTION = 'Ledger does not exist : Reliance Industries';

    private TallySyncEntry $grn;

    private TallySyncEntry $delivery;

    protected function setUp(): void
    {
        parent::setUp();

        // A mixed queue: a Receipt Note (supplier party — FC-06), failed with
        // Tally's own words naming the vendor; a Delivery Note (a customer is
        // not FC-06); production vouchers in three statuses.
        $this->grn = $this->enqueueGoodsReceipt();
        $this->grn->update(['status' => TallySyncStatus::Failed, 'attempts' => 1, 'error_message' => self::REJECTION]);
        $this->delivery = $this->enqueueDelivery();
        $this->voucher(['payload' => ['voucher_number' => 'SPE-1', 'voucher_date' => '2026-08-01'], 'status' => TallySyncStatus::Synced, 'synced_at' => '2026-08-01 10:00:00']);
        $this->voucher(['payload' => ['voucher_number' => 'SPE-2', 'voucher_date' => '2026-08-02'], 'status' => TallySyncStatus::Failed, 'error_message' => 'Stock Item does not exist']);
        $this->voucher(['payload' => ['voucher_number' => 'SPE-3', 'voucher_date' => '2026-08-05'], 'status' => TallySyncStatus::Pending]);
        $this->voucher(['payload' => ['voucher_number' => 'SPE-4', 'voucher_date' => '2026-08-06'], 'status' => TallySyncStatus::Dismissed]);
    }

    public function test_the_file_carries_exactly_the_list_endpoints_rows_in_its_order_for_the_same_filters(): void
    {
        $this->actAs(['tally-sync.view', 'finance.view']);

        foreach ([
            [],
            ['status' => ['failed', 'pending']],
            ['status' => ['failed', 'pending', 'synced', 'dismissed'], 'sort' => 'status_rank'],
            ['from' => '2026-08-02', 'to' => '2026-08-05'],
            ['q' => 'Reliance'],
            ['category' => ['receipt_note', 'delivery_note']],
        ] as $filters) {
            $list = $this->getJson('/api/v1/tally-sync/entries?per_page=1000&'.http_build_query($filters))->assertOk();
            $csv = $this->csv($this->postJson('/api/v1/exports/tally_sync_entries', $filters)->assertOk());

            $this->assertSame(
                array_column($list->json('data'), 'id'),
                array_map(fn (array $row) => (int) $row['id'], $csv['rows']),
                'ids and order, filters: '.json_encode($filters),
            );
            $this->assertSame($list->json('meta.total'), count($csv['rows']), 'row count == meta.total, filters: '.json_encode($filters));
        }
    }

    public function test_the_export_reads_the_same_grammar_as_the_list(): void
    {
        $this->actAs(['tally-sync.view']);

        $this->postJson('/api/v1/exports/tally_sync_entries', ['status' => ['bogus']])->assertUnprocessable()->assertJsonValidationErrors('status.0');
        $this->postJson('/api/v1/exports/tally_sync_entries', ['from' => '2026-08-05', 'to' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson('/api/v1/exports/tally_sync_entries', ['direction' => 'sideways'])->assertUnprocessable()->assertJsonValidationErrors('direction');
        // The list's `?status=failed` spelling is read the same way here.
        $csv = $this->csv($this->postJson('/api/v1/exports/tally_sync_entries', ['status' => 'failed'])->assertOk());
        $this->assertCount(2, $csv['rows']);
    }

    public function test_a_tally_sync_only_readers_file_says_withheld_where_the_screen_withholds_and_names_no_vendor(): void
    {
        $this->actAs(['tally-sync.view', 'tally-sync.manage']);

        $response = $this->postJson('/api/v1/exports/tally_sync_entries', [])->assertOk();
        $csv = $this->csv($response);

        // The columns are the same for every reader — the file is the screen
        // flattened. What differs is the CELL: on a supplier-party voucher the
        // party and Tally's text read "withheld (FC-06)", the screen's own
        // words, never a blank that would read as "no party" / "no error".
        $this->assertSame(
            ['id', 'voucher_type', 'category', 'voucher_number', 'voucher_date', 'status', 'party', 'attempts', 'error_message', 'created_at', 'delivered_at', 'synced_at', 'held'],
            $csv['headers'],
        );
        $this->assertStringNotContainsString(self::VENDOR, $csv['raw'], 'the vendor name leaked into the file');
        $this->assertStringNotContainsString(self::VENDOR_GSTIN, $csv['raw']);
        $this->assertStringNotContainsString('Ledger does not exist', $csv['raw'], 'Tally\'s rejection text leaked into the file');

        // The voucher itself is still on the file, whole as a voucher.
        $grn = collect($csv['rows'])->firstWhere('id', (string) $this->grn->id);
        $this->assertSame('withheld (FC-06)', $grn['party']);
        $this->assertSame('withheld (FC-06)', $grn['error_message']);
        // …and the CUSTOMER on a Delivery Note is on the file, as on screen.
        $delivery = collect($csv['rows'])->firstWhere('id', (string) $this->delivery->id);
        $this->assertSame('Sri Aurobindo Beverages', $delivery['party']);
        $this->assertSame('', $delivery['error_message']);
        $this->assertSame('Receipt Note', $grn['voucher_type']);
        $this->assertSame('Procurement — Receipt Note', $grn['category'], 'the category label the screen shows');
        $this->assertSame('GRN-7', $grn['voucher_number']);
        $this->assertSame('2026-08-04', $grn['voucher_date']);
        $this->assertSame('failed', $grn['status']);
        $this->assertSame('1', $grn['attempts']);
        $this->assertSame('false', $grn['held']);
        // Every row of the file has exactly the header's cells — no row
        // grew a column the header does not name.
        foreach ($csv['rows'] as $row) {
            $this->assertCount(count($csv['headers']), $row);
        }
    }

    public function test_finances_file_carries_party_and_error_message_exactly_as_finances_screen_does(): void
    {
        $this->actAs(['tally-sync.view', 'finance.view']);

        $list = collect($this->getJson('/api/v1/tally-sync/entries?per_page=1000')->assertOk()->json('data'))->keyBy('id');
        $csv = $this->csv($this->postJson('/api/v1/exports/tally_sync_entries', [])->assertOk());

        $this->assertSame(
            ['id', 'voucher_type', 'category', 'voucher_number', 'voucher_date', 'status', 'party', 'attempts', 'error_message', 'created_at', 'delivered_at', 'synced_at', 'held'],
            $csv['headers'],
        );

        $grn = collect($csv['rows'])->firstWhere('id', (string) $this->grn->id);
        $this->assertSame(self::VENDOR, $grn['party']);
        $this->assertSame(self::REJECTION, $grn['error_message']);
        $this->assertSame(self::VENDOR, $list[$this->grn->id]['party'], 'the screen says the same');
        $this->assertSame(self::REJECTION, $list[$this->grn->id]['error_message']);

        // A customer on a Delivery Note; a production voucher with no party.
        $delivery = collect($csv['rows'])->firstWhere('id', (string) $this->delivery->id);
        $this->assertSame('Sri Aurobindo Beverages', $delivery['party']);
        $this->assertSame('', $delivery['error_message']);
        $spe2 = collect($csv['rows'])->firstWhere('voucher_number', 'SPE-2');
        $this->assertSame('', $spe2['party']);
        $this->assertSame('Stock Item does not exist', $spe2['error_message']);

        // Every cell of every row is the resource's own value for that key,
        // as the list emitted it (dates ISO-8601, status as its wire value).
        foreach ($csv['rows'] as $row) {
            $screen = $list[(int) $row['id']];
            $this->assertSame((string) $screen['tally_voucher_type'], $row['voucher_type']);
            $this->assertSame((string) $screen['category']['label'], $row['category']);
            $this->assertSame((string) $screen['document_number'], $row['voucher_number']);
            $this->assertSame((string) $screen['business_date'], $row['voucher_date']);
            $this->assertSame((string) $screen['status'], $row['status']);
            $this->assertSame((string) ($screen['party'] ?? ''), $row['party']);
            $this->assertSame((string) $screen['attempts'], $row['attempts']);
            $this->assertSame((string) ($screen['error_message'] ?? ''), $row['error_message']);
            $this->assertSame((string) $screen['created_at'], $row['created_at']);
            $this->assertSame((string) ($screen['delivered_at'] ?? ''), $row['delivered_at']);
            $this->assertSame((string) ($screen['synced_at'] ?? ''), $row['synced_at']);
            $this->assertSame($screen['hold'] === null ? 'false' : 'true', $row['held']);
        }
    }

    public function test_the_kind_is_catalogued_for_tally_sync_readers_and_named_after_the_factory_clock(): void
    {
        Carbon::setTestNow('2026-08-17 03:25:00'); // 08:55 IST
        $this->actAs(['tally-sync.view']);

        $kind = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->firstWhere('key', 'tally_sync_entries');
        $this->assertNotNull($kind);
        $this->assertSame('tally-sync', $kind['module']);
        $this->assertSame('available', $kind['status']);
        $status = collect($kind['filters'])->firstWhere('name', 'status');
        $this->assertSame(['type' => 'select', 'multiple' => true, 'options' => ['pending', 'synced', 'failed', 'dismissed']], [
            'type' => $status['type'], 'multiple' => $status['multiple'], 'options' => $status['options'],
        ]);

        $this->postJson('/api/v1/exports/tally_sync_entries', [])
            ->assertOk()
            ->assertDownload('tally_sync_entries-20260817-0855.csv');

        $this->app['auth']->forgetGuards();

        // A reader without tally-sync standing is not offered it and may not run it.
        $this->actAs(['production.view']);
        $this->assertNull(collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->firstWhere('key', 'tally_sync_entries'));
        $this->postJson('/api/v1/exports/tally_sync_entries', [])->assertForbidden();
    }

    // ---- helpers ------------------------------------------------------------

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
            $cells = str_getcsv($line, ',', '"', '');
            $rows[] = array_combine($headers, $cells);
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

    /** @param  array<string, mixed>  $attributes */
    private function voucher(array $attributes = []): TallySyncEntry
    {
        return TallySyncEntry::create(array_merge([
            'syncable_type' => 'shift_production_entry',
            'syncable_id' => 1,
            'tally_voucher_type' => 'Manufacturing Journal',
            'payload' => ['voucher_number' => 'SPE-1'],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ], $attributes));
    }

    /** The GRN of SupplierIdentityVisibilityTest, through the real event → listener → enqueue chain. */
    private function enqueueGoodsReceipt(): TallySyncEntry
    {
        $po = new PurchaseOrder;
        $po->setRelation('vendor', new Vendor(['name' => self::VENDOR, 'gstin' => self::VENDOR_GSTIN, 'tally_ledger_name' => self::VENDOR]));

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '85.0000']);
        $line->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin', 'tally_stock_item_guid' => 'itm-resin']));
        $line->setRelation('scheduleAllocations', collect());

        $grn = $this->existing(new GoodsReceiptNote(['received_date' => '2026-08-04']), 7);
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', new Warehouse(['name' => 'RM Store', 'tally_guid' => 'gd-rm']));
        $grn->setRelation('purchaseOrder', $po);

        event(new GoodsReceiptNoteReceived($grn));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Receipt Note')->sole();
    }

    private function enqueueDelivery(): TallySyncEntry
    {
        $so = new SalesOrder;
        // The Delivery Note is staged fail-closed (DEC-20260831-007): it posts
        // against the customer's TALLY ledger name, and refuses without one.
        // This customer is in memory, so the name goes on the object itself.
        $customer = new Customer(['name' => 'Sri Aurobindo Beverages', 'gstin' => '33AAACS1234A1Z9']);
        $customer->forceFill(['tally_ledger_name' => 'Sri Aurobindo Beverages']);
        $so->setRelation('customer', $customer);

        $bottle = new DeliveryLine(['quantity' => '2000.0000']);
        $bottle->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $delivery = $this->existing(new Delivery(['delivered_date' => '2026-08-03']), 3);
        $delivery->setRelation('lines', collect([$bottle]));
        $delivery->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $delivery->setRelation('salesOrder', $so);

        event(new DeliveryDispatched($delivery));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Delivery Note')->sole();
    }

    /** Mark an in-memory model as an existing (persisted) record without a DB write. */
    private function existing(object $model, int $id): object
    {
        $model->id = $id;
        $model->exists = true;

        return $model;
    }
}
