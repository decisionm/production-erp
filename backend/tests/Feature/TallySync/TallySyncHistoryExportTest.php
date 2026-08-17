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
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\TallySyncEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * POST /exports/tally_sync_history IS the history of every entry GET
 * /tally-sync/entries lists for the same filters, downloaded: one row per
 * event, entries in the list's order, each entry's events in the order the
 * show endpoint's `history` carries them — the same rows, built through
 * the same resource. Tally's rejection text (details.error_message /
 * previous_error) is in NOBODY's file: it can name the supplier, and this
 * file is the event log — the words live in the entries export, gated
 * there. The vendor's name therefore appears nowhere in this file for any
 * reader, finance included.
 */
class TallySyncHistoryExportTest extends TestCase
{
    use RefreshDatabase;

    private const VENDOR = 'Reliance Industries';

    private const REJECTION = 'Ledger does not exist : Reliance Industries';

    private TallySyncEntry $grn;

    private TallySyncEntry $delivery;

    private TallySyncEntry $spe1;

    private TallySyncEntry $spe2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10 04:00:00');

        $recorder = app(TallySyncEventRecorder::class);

        // A Receipt Note (supplier party — FC-06): enqueued through the real
        // chain (which records voucher.enqueued), then failed with Tally's
        // own words naming the vendor, retried, failed again, dismissed —
        // four events carrying the text keys, one structured.
        $this->grn = $this->enqueueGoodsReceipt();
        $recorder->record(TallySyncEventKind::VoucherFailed, $this->grn, ['error_message' => self::REJECTION, 'attempt' => 1]);
        $recorder->record(TallySyncEventKind::VoucherRetried, $this->grn, ['payload_regenerated' => true, 'previous_error' => self::REJECTION]);
        $recorder->record(TallySyncEventKind::VoucherFailed, $this->grn, ['error_message' => self::REJECTION, 'attempt' => 2]);
        $recorder->record(TallySyncEventKind::VoucherDismissed, $this->grn, ['previous_error' => self::REJECTION]);
        $this->grn->update(['status' => TallySyncStatus::Dismissed, 'attempts' => 2, 'error_message' => self::REJECTION]);

        // A Delivery Note (a customer is not FC-06): enqueued, delivered, synced.
        $this->delivery = $this->enqueueDelivery();
        $recorder->record(TallySyncEventKind::PendingDelivered, $this->delivery, ['voucher_type' => 'Delivery Note', 'voucher_number' => 'DN-3']);
        $recorder->record(TallySyncEventKind::VoucherSynced, $this->delivery, ['voucher_type' => 'Delivery Note', 'voucher_number' => 'DN-3']);
        $this->delivery->update(['status' => TallySyncStatus::Synced, 'synced_at' => now()]);

        // Production vouchers: one failed with a stock-item error (not
        // FC-06 — the text names a product, not a supplier), one pending
        // with no history at all (no events).
        $this->spe1 = $this->voucher(['payload' => ['voucher_number' => 'SPE-1', 'voucher_date' => '2026-08-01'], 'status' => TallySyncStatus::Failed, 'error_message' => 'Stock Item does not exist']);
        $recorder->record(TallySyncEventKind::VoucherEnqueued, $this->spe1, ['voucher_type' => 'Manufacturing Journal', 'voucher_number' => 'SPE-1']);
        $recorder->record(TallySyncEventKind::VoucherFailed, $this->spe1, ['error_message' => 'Stock Item does not exist', 'attempt' => 1]);
        $this->spe2 = $this->voucher(['payload' => ['voucher_number' => 'SPE-2', 'voucher_date' => '2026-08-05'], 'status' => TallySyncStatus::Pending]);
    }

    public function test_the_file_carries_every_event_of_the_listed_entries_in_the_lists_order_then_the_historys(): void
    {
        $this->actAs(['tally-sync.view', 'finance.view']);

        foreach ([
            [],
            ['status' => ['failed', 'dismissed']],
            ['status' => ['failed', 'pending', 'synced', 'dismissed'], 'sort' => 'status_rank'],
            ['from' => '2026-08-02', 'to' => '2026-08-05'],
            ['q' => 'Reliance'],
            ['category' => ['receipt_note', 'delivery_note']],
        ] as $filters) {
            $list = $this->getJson('/api/v1/tally-sync/entries?per_page=1000&'.http_build_query($filters))->assertOk();
            $csv = $this->csv($this->postJson('/api/v1/exports/tally_sync_history', $filters)->assertOk());

            // The expectation, built from the screen: for each listed entry
            // (in the list's order), the show endpoint's history rows in
            // their order.
            $expected = [];
            foreach (array_column($list->json('data'), 'id') as $entryId) {
                $history = $this->getJson("/api/v1/tally-sync/entries/{$entryId}")->assertOk()->json('data.history');
                foreach ($history as $event) {
                    $expected[] = [(string) $entryId, (string) $event['id'], $event['event']];
                }
            }

            $this->assertSame(
                $expected,
                array_map(fn (array $row) => [$row['entry_id'], $row['event_id'], $row['event']], $csv['rows']),
                'entry order, event order and kinds, filters: '.json_encode($filters),
            );
            $this->assertSame(count($expected), count($csv['rows']), 'row count == the events of meta.total entries, filters: '.json_encode($filters));
        }

        // The pending production voucher with no history contributes no row
        // — and the empty list writes an empty file, not an error.
        $csv = $this->csv($this->postJson('/api/v1/exports/tally_sync_history', ['status' => 'pending'])->assertOk());
        $this->assertSame([], $csv['rows']);
        $this->assertSame(0, TallySyncEvent::query()->where('tally_sync_entry_id', $this->spe2->id)->count());
    }

    public function test_the_export_reads_the_same_grammar_as_the_list(): void
    {
        $this->actAs(['tally-sync.view']);

        $this->postJson('/api/v1/exports/tally_sync_history', ['status' => ['bogus']])->assertUnprocessable()->assertJsonValidationErrors('status.0');
        $this->postJson('/api/v1/exports/tally_sync_history', ['from' => '2026-08-05', 'to' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson('/api/v1/exports/tally_sync_history', ['direction' => 'sideways'])->assertUnprocessable()->assertJsonValidationErrors('direction');
    }

    public function test_tallys_rejection_text_is_in_nobodys_file_and_the_vendor_is_named_nowhere(): void
    {
        foreach ([['tally-sync.view', 'tally-sync.manage'], ['tally-sync.view', 'finance.view']] as $permissions) {
            $this->app['auth']->forgetGuards();
            $this->actAs($permissions);

            $csv = $this->csv($this->postJson('/api/v1/exports/tally_sync_history', [])->assertOk());

            $this->assertSame(
                ['entry_id', 'voucher_type', 'voucher_number', 'event_id', 'event', 'direction', 'occurred_at', 'actor_type', 'actor_id', 'actor', 'details', 'backfilled'],
                $csv['headers'],
                'the same columns for every reader',
            );
            $this->assertStringNotContainsString(self::VENDOR, $csv['raw'], 'the vendor name leaked into the file for '.json_encode($permissions));
            $this->assertStringNotContainsString('Ledger does not exist', $csv['raw'], 'Tally\'s rejection text leaked into the file');
            $this->assertStringNotContainsString('error_message', $csv['raw']);
            $this->assertStringNotContainsString('previous_error', $csv['raw']);
            // Not only the supplier's: no rejection text at all — the file
            // is the event log, the words live in the entries export.
            $this->assertStringNotContainsString('Stock Item does not exist', $csv['raw']);

            // The GRN's story is still whole as a story: enqueued, failed,
            // retried, failed, dismissed — with the structured facts kept.
            $grnRows = array_values(array_filter($csv['rows'], fn (array $row) => $row['entry_id'] === (string) $this->grn->id));
            $this->assertSame(
                ['voucher.enqueued', 'voucher.failed', 'voucher.retried', 'voucher.failed', 'voucher.dismissed'],
                array_column($grnRows, 'event'),
            );
            $this->assertSame('Receipt Note', $grnRows[0]['voucher_type']);
            $this->assertSame('GRN-7', $grnRows[0]['voucher_number']);
            $this->assertSame(['attempt' => 1], json_decode($grnRows[1]['details'], true), 'the failure keeps its attempt number, loses the text');
            $this->assertSame(['payload_regenerated' => true], json_decode($grnRows[2]['details'], true));
            $this->assertSame('', $grnRows[4]['details'], 'a dismissal whose only detail was the text has an empty cell, not "[]"');
            $this->assertSame('erp_to_tally', $grnRows[1]['direction']);
            $this->assertSame('system', $grnRows[1]['actor_type']);
            $this->assertSame('false', $grnRows[1]['backfilled']);

            // A customer on a Delivery Note is not FC-06 and reads as on screen.
            $dnRows = array_values(array_filter($csv['rows'], fn (array $row) => $row['entry_id'] === (string) $this->delivery->id));
            $this->assertSame(['voucher.enqueued', 'pending.delivered', 'voucher.synced'], array_column($dnRows, 'event'));
            $this->assertSame('DN-3', $dnRows[0]['voucher_number']);
            $this->assertSame(['voucher_type' => 'Delivery Note', 'voucher_number' => 'DN-3'], json_decode($dnRows[2]['details'], true));

            foreach ($csv['rows'] as $row) {
                $this->assertCount(count($csv['headers']), $row);
            }
        }
    }

    public function test_every_cell_is_the_show_endpoints_own_value_for_that_event(): void
    {
        $this->actAs(['tally-sync.view', 'finance.view']);

        $csv = $this->csv($this->postJson('/api/v1/exports/tally_sync_history', [])->assertOk());
        $screen = [];
        foreach ([$this->grn, $this->delivery, $this->spe1] as $entry) {
            $show = $this->getJson("/api/v1/tally-sync/entries/{$entry->id}")->assertOk()->json('data');
            foreach ($show['history'] as $event) {
                $screen[$event['id']] = $event + ['_entry' => $show];
            }
        }

        $this->assertCount(count($screen), $csv['rows']);
        foreach ($csv['rows'] as $row) {
            $event = $screen[(int) $row['event_id']];
            $this->assertSame((string) $event['_entry']['id'], $row['entry_id']);
            $this->assertSame((string) $event['_entry']['tally_voucher_type'], $row['voucher_type']);
            $this->assertSame((string) $event['_entry']['document_number'], $row['voucher_number']);
            $this->assertSame((string) $event['event'], $row['event']);
            $this->assertSame((string) $event['direction'], $row['direction']);
            $this->assertSame((string) $event['occurred_at'], $row['occurred_at']);
            $this->assertSame((string) $event['actor']['type'], $row['actor_type']);
            $this->assertSame((string) ($event['actor']['id'] ?? ''), $row['actor_id']);
            $this->assertSame((string) ($event['actor']['label'] ?? ''), $row['actor']);
            $this->assertSame($event['backfilled'] ? 'true' : 'false', $row['backfilled']);
            // details: the screen's, minus the two text keys — for finance too.
            $details = array_diff_key((array) ($event['details'] ?? []), ['error_message' => 1, 'previous_error' => 1]);
            $this->assertSame($details === [] ? '' : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['details']);
        }
    }

    public function test_the_kind_is_catalogued_for_tally_sync_readers_only(): void
    {
        $this->actAs(['tally-sync.view']);

        $kind = collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->firstWhere('key', 'tally_sync_history');
        $this->assertNotNull($kind);
        $this->assertSame('tally-sync', $kind['module']);
        $this->assertSame('available', $kind['status']);
        $this->assertNull($kind['blocked_reason']);
        // The list's grammar, described for the form — the same schema the
        // entries kind draws.
        $this->assertSame(
            collect($this->getJson('/api/v1/exports')->json('data'))->firstWhere('key', 'tally_sync_entries')['filters'],
            $kind['filters'],
        );

        $this->app['auth']->forgetGuards();

        $this->actAs(['production.view', 'finance.view']);
        $this->assertNull(collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->firstWhere('key', 'tally_sync_history'));
        $this->postJson('/api/v1/exports/tally_sync_history', [])->assertForbidden();
    }

    // ---- helpers ------------------------------------------------------------

    /**
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

    /** The GRN of TallySyncEntriesExportTest, through the real event → listener → enqueue chain. */
    private function enqueueGoodsReceipt(): TallySyncEntry
    {
        $po = new PurchaseOrder;
        $po->setRelation('vendor', new Vendor(['name' => self::VENDOR, 'gstin' => '27AAACR1234A1Z5']));

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '85.0000']);
        $line->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin']));
        $line->setRelation('scheduleAllocations', collect());

        $grn = $this->existing(new GoodsReceiptNote(['received_date' => '2026-08-04']), 7);
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', new Warehouse(['name' => 'RM Store']));
        $grn->setRelation('purchaseOrder', $po);

        event(new GoodsReceiptNoteReceived($grn));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Receipt Note')->sole();
    }

    private function enqueueDelivery(): TallySyncEntry
    {
        $so = new SalesOrder;
        $so->setRelation('customer', new Customer(['name' => 'Sri Aurobindo Beverages', 'gstin' => '33AAACS1234A1Z9']));

        $bottle = new DeliveryLine(['quantity' => '2000.0000']);
        $bottle->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $delivery = $this->existing(new Delivery(['delivered_date' => '2026-08-03']), 3);
        $delivery->setRelation('lines', collect([$bottle]));
        $delivery->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $delivery->setRelation('salesOrder', $so);

        event(new DeliveryDispatched($delivery));

        return TallySyncEntry::query()->where('tally_voucher_type', 'Delivery Note')->sole();
    }

    private function existing(object $model, int $id): object
    {
        $model->id = $id;
        $model->exists = true;

        return $model;
    }
}
