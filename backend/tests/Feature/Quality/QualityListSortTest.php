<?php

namespace Tests\Feature\Quality;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Enums\InspectionResult;
use App\Modules\Quality\Models\IncomingInspection;
use App\Modules\Quality\Models\MeasuringInstrument;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Models\SpcCharacteristic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * EVERY QUALITY LIST SORTS AND PAGES ON THE SERVER (03-Sep-2026), through
 * the one `sort` spelling the ListSort helper defines: bare column
 * ascending, "-" descending, absent = the list's own default. Six
 * endpoints, one contract each:
 *
 *   - `?sort=nonsense` is refused with a 422 — never silently ignored;
 *   - `?sort=-<col>` orders descending with `id desc` as the tie-break, so
 *     two rows sharing a value keep one order between two reads;
 *   - `?per_page=2` cuts the page AFTER the filter and the total is the
 *     whole set's, so a screen drawing the page can say what it is not
 *     showing (four of these lists used to render page one with no pager).
 *
 * Plus the two things that must not have moved: a nullable date column
 * sorts its empties last in BOTH directions, and the filters each list
 * already had (`due`, `item_id`) still narrow.
 *
 * Fixtures are digit-free where the value is text (a MySQL CI job keeps
 * auto-increment across tests, so ids are read back, never assumed) and
 * synthetic throughout — no real vendor, ledger or product name.
 */
class QualityListSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // quality.view for the five registers; production.view as well for
        // the batch queue, which is served by Production's own service.
        $user = User::factory()->create(['is_active' => true]);
        foreach (['quality.view', 'production.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['quality.view', 'production.view']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    /**
     * The three-part contract for one endpoint. `$expectedDesc` is the id
     * order `?sort=-$column` must answer with, over exactly three rows.
     *
     * @param  list<int>  $expectedDesc
     */
    private function assertSortContract(string $path, string $column, array $expectedDesc): void
    {
        $this->getJson("{$path}?sort=nonsense")->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson("{$path}?sort=--{$column}")->assertUnprocessable();

        [$highNewer, $highOlder, $low] = $expectedDesc;
        $this->assertSame($expectedDesc, $this->ids("{$path}?sort=-{$column}"), "-{$column}: descending, newest id first among equals");
        // Ascending flips the column, never the tie-break: equals stay newest first.
        $this->assertSame([$low, $highNewer, $highOlder], $this->ids("{$path}?sort={$column}"), "{$column}: ascending, equals still newest first");

        $page = $this->getJson("{$path}?per_page=2")->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(3, $page->json('meta.total'), 'the total is the whole set, not the page');
        $this->assertSame(2, $page->json('meta.last_page'));
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return array_map(fn (array $row) => $row['id'], $this->getJson($url)->assertOk()->json('data'));
    }

    private function item(string $sku, string $name): Item
    {
        return Item::create(['sku' => $sku, 'name' => $name, 'uom' => 'Nos', 'is_active' => true]);
    }

    // ---------------------------------------------------------------- lists

    public function test_incoming_inspections_sort_on_the_quantities_the_result_and_the_date(): void
    {
        $vendor = Vendor::create(['code' => 'V-SORT', 'name' => 'Vendor Sort']);
        $store = Warehouse::create(['code' => 'WH-SORT', 'name' => 'Sort Store', 'is_active' => true]);
        $item = $this->item('RM-SORT', 'Sort Resin');
        $order = PurchaseOrder::create(['vendor_id' => $vendor->id, 'order_date' => '2026-09-01', 'status' => 'sent']);
        $grn = GoodsReceiptNote::create(['purchase_order_id' => $order->id, 'warehouse_id' => $store->id, 'received_date' => '2026-09-01']);
        $orderLine = PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'item_id' => $item->id, 'quantity' => '3000', 'unit_price' => '10.0000']);

        $inspection = function (string $rejected) use ($grn, $orderLine, $item): IncomingInspection {
            $line = GoodsReceiptNoteLine::create([
                'goods_receipt_note_id' => $grn->id, 'purchase_order_line_id' => $orderLine->id,
                'item_id' => $item->id, 'quantity' => '1000', 'unit_cost' => '10.0000',
            ]);

            return IncomingInspection::create([
                'goods_receipt_note_line_id' => $line->id, 'item_id' => $item->id,
                'inspected_quantity' => '1000', 'accepted_quantity' => bcsub('1000', $rejected, 4), 'rejected_quantity' => $rejected,
                'result' => bccomp($rejected, '0', 4) === 0 ? InspectionResult::Pass : InspectionResult::Partial,
                'inspection_date' => '2026-09-01',
            ]);
        };

        $low = $inspection('5');
        $highOlder = $inspection('50');
        $highNewer = $inspection('50');

        $this->assertSortContract('/api/v1/quality/incoming-inspections', 'rejected_quantity', [$highNewer->id, $highOlder->id, $low->id]);

        // Every column the request admits is accepted; the bare list is still newest first.
        foreach (['inspected_quantity', 'accepted_quantity', 'result', 'inspection_date'] as $column) {
            $this->getJson("/api/v1/quality/incoming-inspections?sort={$column}")->assertOk();
        }
        $this->assertSame([$highNewer->id, $highOlder->id, $low->id], $this->ids('/api/v1/quality/incoming-inspections'));
    }

    public function test_non_conformance_reports_sort_on_severity_status_and_raised_date(): void
    {
        $item = $this->item('NCR-SORT', 'Sort Cap');
        $report = fn (string $raisedDate) => NonConformanceReport::create([
            'item_id' => $item->id, 'description' => 'sort fixture', 'severity' => 'minor', 'status' => 'open', 'raised_date' => $raisedDate,
        ]);

        $early = $report('2026-08-01');
        $lateOlder = $report('2026-08-20');
        $lateNewer = $report('2026-08-20');

        $this->assertSortContract('/api/v1/quality/ncrs', 'raised_date', [$lateNewer->id, $lateOlder->id, $early->id]);

        foreach (['severity', 'status'] as $column) {
            $this->getJson("/api/v1/quality/ncrs?sort={$column}")->assertOk();
        }
        $this->assertSame([$lateNewer->id, $lateOlder->id, $early->id], $this->ids('/api/v1/quality/ncrs'), 'bare: newest first, as before');
    }

    public function test_capas_sort_on_title_status_and_due_date_with_undated_ones_last_either_way(): void
    {
        $capa = fn (string $title, ?string $due) => Capa::create([
            'title' => $title, 'problem_statement' => 'sort fixture', 'status' => 'open', 'due_date' => $due,
        ]);

        $soon = $capa('Alpha', '2026-09-10');
        $laterOlder = $capa('Bravo', '2026-09-20');
        $laterNewer = $capa('Charlie', '2026-09-20');

        $this->assertSortContract('/api/v1/quality/capas', 'due_date', [$laterNewer->id, $laterOlder->id, $soon->id]);

        // An undated CAPA is not "earliest": it sorts last ascending AND descending.
        $undated = $capa('Delta', null);
        $this->assertSame([$soon->id, $laterNewer->id, $laterOlder->id, $undated->id], $this->ids('/api/v1/quality/capas?sort=due_date'));
        $this->assertSame([$laterNewer->id, $laterOlder->id, $soon->id, $undated->id], $this->ids('/api/v1/quality/capas?sort=-due_date'));

        $this->assertSame([$undated->id, $laterNewer->id, $laterOlder->id, $soon->id], $this->ids('/api/v1/quality/capas?sort=-title'));
        $this->getJson('/api/v1/quality/capas?sort=status')->assertOk();
    }

    public function test_measuring_instruments_sort_on_their_columns_and_the_due_switch_still_narrows(): void
    {
        $gauge = fn (string $code, string $nextDue, ?string $lastCalibrated = null) => MeasuringInstrument::create([
            'code' => $code, 'name' => "Gauge {$code}", 'location' => 'Lab', 'calibration_frequency_days' => 30,
            'next_calibration_due' => $nextDue, 'last_calibrated_date' => $lastCalibrated, 'status' => 'active',
        ]);

        $soon = $gauge('G-A', '2020-01-10', '2019-12-10');
        $laterOlder = $gauge('G-B', '2030-01-20');
        $laterNewer = $gauge('G-C', '2030-01-20', '2029-12-20');

        $this->assertSortContract('/api/v1/quality/instruments', 'next_calibration_due', [$laterNewer->id, $laterOlder->id, $soon->id]);

        foreach (['code', 'name', 'location', 'status'] as $column) {
            $this->getJson("/api/v1/quality/instruments?sort={$column}")->assertOk();
        }
        $this->assertSame([$soon->id, $laterNewer->id, $laterOlder->id], $this->ids('/api/v1/quality/instruments'), 'bare: next due first, as before');

        // Never calibrated sorts last in both directions.
        $this->assertSame([$soon->id, $laterNewer->id, $laterOlder->id], $this->ids('/api/v1/quality/instruments?sort=last_calibrated_date'));
        $this->assertSame([$laterNewer->id, $soon->id, $laterOlder->id], $this->ids('/api/v1/quality/instruments?sort=-last_calibrated_date'));

        // The switch the page already had: only the overdue gauge, and it sorts within that set.
        $this->assertSame([$soon->id], $this->ids('/api/v1/quality/instruments?due=1'));
        $this->assertSame([$soon->id], $this->ids('/api/v1/quality/instruments?due=1&sort=-code'));
        $this->getJson('/api/v1/quality/instruments?due=maybe')->assertUnprocessable();
    }

    public function test_spc_characteristics_sort_on_name_unit_and_target_and_item_id_still_narrows(): void
    {
        $bottle = $this->item('SPC-BTL', 'Sort Bottle');
        $cap = $this->item('SPC-CAP', 'Sort Cap');
        $characteristic = fn (Item $item, string $name, string $target) => SpcCharacteristic::create([
            'item_id' => $item->id, 'name' => $name, 'unit_of_measure' => 'mm', 'target_value' => $target, 'is_active' => true,
        ]);

        $small = $characteristic($bottle, 'Neck', '1.5');
        $largeOlder = $characteristic($bottle, 'Height', '9.5');
        $largeNewer = $characteristic($cap, 'Width', '9.5');

        $this->assertSortContract('/api/v1/quality/spc-characteristics', 'target_value', [$largeNewer->id, $largeOlder->id, $small->id]);

        $this->getJson('/api/v1/quality/spc-characteristics?sort=unit_of_measure')->assertOk();
        $this->assertSame([$largeOlder->id, $small->id, $largeNewer->id], $this->ids('/api/v1/quality/spc-characteristics'), 'bare: by name, as before');

        $this->assertSame([$largeNewer->id], $this->ids("/api/v1/quality/spc-characteristics?item_id={$cap->id}"));
        $this->assertSame([$small->id, $largeOlder->id], $this->ids("/api/v1/quality/spc-characteristics?item_id={$bottle->id}&sort=target_value"));
        $this->getJson('/api/v1/quality/spc-characteristics?item_id=abc')->assertUnprocessable();
    }

    public function test_the_batch_quality_queue_sorts_on_batch_number_produced_count_and_production_date(): void
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-SORT', 'name' => 'Sort Machine', 'is_active' => true]);
        $fg = Warehouse::create(['code' => 'FG-SORT', 'name' => 'Sort FG', 'is_active' => true]);
        $item = $this->item('BTL-SORT', 'Sort Bottle');
        $entry = fn (string $batch, string $date, string $produced) => ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $item->id, 'warehouse_id' => $fg->id,
            'production_date' => $date, 'batch_number' => $batch, 'batch_status' => BatchStatus::Completed,
            'quantity_produced' => $produced, 'quantity_scrap' => '0', 'status' => ShiftProductionEntryStatus::Pending,
        ]);

        $few = $entry('B-FEW', '2026-08-08', '100');
        $manyOlder = $entry('B-MANY-A', '2026-08-09', '900');
        $manyNewer = $entry('B-MANY-B', '2026-08-09', '900');

        $this->assertSortContract('/api/v1/quality/batch-quality-queue', 'quantity_produced', [$manyNewer->id, $manyOlder->id, $few->id]);

        $this->assertSame([$manyNewer->id, $manyOlder->id, $few->id], $this->ids('/api/v1/quality/batch-quality-queue?sort=-batch_number'));
        $this->assertSame([$manyNewer->id, $manyOlder->id, $few->id], $this->ids('/api/v1/quality/batch-quality-queue?sort=-production_date'));

        // Bare, the queue is still worked front to back: oldest first, id ASCENDING among a day's batches.
        $this->assertSame([$few->id, $manyOlder->id, $manyNewer->id], $this->ids('/api/v1/quality/batch-quality-queue'));
    }
}
