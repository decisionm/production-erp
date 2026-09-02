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
use App\Modules\Quality\Models\Enums\InspectionResult;
use App\Modules\Quality\Models\IncomingInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /quality/incoming-inspections becomes SEARCHABLE, FILTERABLE and
 * honestly PAGED on the server, through a FormRequest-validated query
 * string in the same grammar as every other list here. The screen used to
 * ask for per_page=1000 and render every row with no pager. What must hold:
 *
 *   - `q` finds an inspection by its product (sku or name), by its arrival
 *     (GRN tracking number, "GRN-12" / "grn 12", or the bare id) and by the
 *     Rejections Out reference — and never by notes;
 *   - `result` narrows to exactly that verdict; a verdict that does not
 *     exist is a 422, not an empty list;
 *   - `per_page` is 1..100 (default 20) and the total is the FILTERED set's;
 *   - an EMPTY query string is exactly the list every earlier caller got —
 *     unfiltered, newest first;
 *   - the whole surface is behind quality.view (403 without it).
 *
 * Rows are built directly (no bags, no stock movement) — these are list
 * tests; what an inspection DOES is IncomingInspectionCoverageTest's.
 */
class IncomingInspectionListTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $cap;

    private Warehouse $store;

    private Vendor $vendor;

    /** @var array<string, IncomingInspection> */
    private array $inspections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['quality.view']);

        $this->vendor = Vendor::create(['code' => 'V-QC', 'name' => 'Vendor Alpha']);
        $this->store = Warehouse::create(['code' => 'WH-QC', 'name' => 'QC Store', 'is_active' => true]);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin IV-0.8', 'uom' => 'Kgs']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos']);

        // Three arrivals, three inspections, newest last: resin passed on
        // TRK-RELPET-01, caps failed on TRK-CAPS-07, resin partial with a
        // Rejections Out reference and a note nobody must be able to search.
        $this->inspections['resin_pass'] = $this->inspection($this->line($this->resin, 'TRK-RELPET-01'), InspectionResult::Pass, '100', '100', '0');
        $this->inspections['cap_fail'] = $this->inspection($this->line($this->cap, 'TRK-CAPS-07'), InspectionResult::Fail, '500', '0', '500');
        $this->inspections['resin_partial'] = $this->inspection($this->line($this->resin, null), InspectionResult::Partial, '200', '150', '50', [
            'rejections_out_reference' => 'RJO-BATCH-Z9',
            'notes' => 'zebra crossing',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    private function line(Item $item, ?string $trackingNumber): GoodsReceiptNoteLine
    {
        $order = PurchaseOrder::create(['vendor_id' => $this->vendor->id, 'order_date' => '2026-08-28', 'status' => 'sent']);
        $grn = GoodsReceiptNote::create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-08-28',
            'tracking_number' => $trackingNumber,
        ]);
        $orderLine = PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'item_id' => $item->id, 'quantity' => '1000', 'unit_price' => '10.0000',
        ]);

        return GoodsReceiptNoteLine::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_line_id' => $orderLine->id,
            'item_id' => $item->id,
            'quantity' => '1000',
            'unit_cost' => '10.0000',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function inspection(GoodsReceiptNoteLine $line, InspectionResult $result, string $inspected, string $accepted, string $rejected, array $extra = []): IncomingInspection
    {
        return IncomingInspection::create(array_merge([
            'goods_receipt_note_line_id' => $line->id,
            'item_id' => $line->item_id,
            'inspected_quantity' => $inspected,
            'accepted_quantity' => $accepted,
            'rejected_quantity' => $rejected,
            'result' => $result,
            'inspection_date' => '2026-08-28',
        ], $extra));
    }

    /** @param  array<string, mixed>  $query */
    private function list(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/quality/incoming-inspections?'.http_build_query($query))->assertOk();
    }

    /** @param  list<string>  $expected */
    private function assertRows(array $expected, TestResponse $response, string $because = ''): void
    {
        $expectedIds = array_map(fn (string $key) => $this->inspections[$key]->id, $expected);
        $actualIds = array_map(fn (array $row) => $row['id'], $response->json('data'));
        sort($expectedIds);
        sort($actualIds);

        $this->assertSame($expectedIds, $actualIds, $because);
        $this->assertSame(count($expected), $response->json('meta.total'), 'the total is the filtered set\'s'.($because ? " ({$because})" : ''));
    }

    public function test_the_bare_list_is_every_inspection_newest_first(): void
    {
        $response = $this->list();

        $this->assertSame(
            [$this->inspections['resin_partial']->id, $this->inspections['cap_fail']->id, $this->inspections['resin_pass']->id],
            array_map(fn (array $row) => $row['id'], $response->json('data')),
        );
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(20, $response->json('meta.per_page'));

        // The arrival rides on the row, named the way the receipts register names it.
        $grn = $this->list(['q' => 'TRK-RELPET-01'])->json('data.0.goods_receipt_note');
        $this->assertSame('TRK-RELPET-01', $grn['tracking_number']);
        $this->assertSame('GRN-'.$grn['id'], $grn['document_number']);
    }

    public function test_q_matches_the_product_the_arrival_and_the_reference_but_never_notes(): void
    {
        $this->assertRows(['resin_pass', 'resin_partial'], $this->list(['q' => 'rm-pet']), 'sku, any case');
        $this->assertRows(['resin_pass', 'resin_partial'], $this->list(['q' => 'Resin IV']), 'part of the name');
        $this->assertRows(['cap_fail'], $this->list(['q' => '28mm']), 'the other product');
        $this->assertRows(['cap_fail'], $this->list(['q' => 'trk-caps']), 'GRN tracking number');
        $this->assertRows(['resin_partial'], $this->list(['q' => 'RJO-BATCH']), 'Rejections Out reference');

        $capGrn = $this->inspections['cap_fail']->goodsReceiptNoteLine->goods_receipt_note_id;
        foreach (["GRN-{$capGrn}", "grn {$capGrn}", "grn#{$capGrn}"] as $spelling) {
            $this->assertRows(['cap_fail'], $this->list(['q' => $spelling]), "q={$spelling}");
        }
        $this->assertRows(['resin_pass'], $this->list(['q' => '#'.$this->inspections['resin_pass']->id]), '#id is the inspection');

        // The notes are not an identity and are not searched.
        $this->assertRows([], $this->list(['q' => 'zebra']));
        // `%` and `_` are characters, not wildcards.
        $this->assertRows([], $this->list(['q' => '%%%']));
        $this->assertRows([], $this->list(['q' => 'RM_PET']));
        // Whitespace is trimmed, and an empty term is no filter.
        $this->assertRows(['cap_fail'], $this->list(['q' => '  28mm  ']));
        $this->assertRows(['resin_pass', 'cap_fail', 'resin_partial'], $this->list(['q' => '   ']));
    }

    public function test_result_narrows_to_that_verdict_and_composes_with_q(): void
    {
        $this->assertRows(['resin_pass'], $this->list(['result' => 'pass']));
        $this->assertRows(['cap_fail'], $this->list(['result' => 'fail']));
        $this->assertRows(['resin_partial'], $this->list(['result' => 'partial']));
        $this->assertRows(['resin_partial'], $this->list(['result' => 'partial', 'q' => 'resin']));
        $this->assertRows([], $this->list(['result' => 'fail', 'q' => 'resin']));
    }

    public function test_a_verdict_that_does_not_exist_or_a_bad_page_size_is_refused(): void
    {
        $this->getJson('/api/v1/quality/incoming-inspections?result=rejected')->assertUnprocessable()->assertJsonValidationErrors(['result']);
        $this->getJson('/api/v1/quality/incoming-inspections?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/quality/incoming-inspections?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/quality/incoming-inspections?page=0')->assertUnprocessable()->assertJsonValidationErrors(['page']);
        $this->getJson('/api/v1/quality/incoming-inspections?q='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors(['q']);
        // An unknown key is ignored, not refused — an old tab's query string still loads.
        $this->getJson('/api/v1/quality/incoming-inspections?colour=amber')->assertOk();
    }

    public function test_pages_are_cut_after_the_filter_so_the_total_is_the_matching_set(): void
    {
        $first = $this->list(['q' => 'resin', 'per_page' => 1]);
        $this->assertCount(1, $first->json('data'));
        $this->assertSame(2, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'));
        $this->assertSame(1, $first->json('meta.current_page'));
        $this->assertSame($this->inspections['resin_partial']->id, $first->json('data.0.id'), 'newest first on page 1');

        $second = $this->list(['q' => 'resin', 'per_page' => 1, 'page' => 2]);
        $this->assertSame($this->inspections['resin_pass']->id, $second->json('data.0.id'));
        $this->assertSame(2, $second->json('meta.current_page'));

        $this->assertSame(100, $this->list(['per_page' => 100])->json('meta.per_page'));
    }

    public function test_the_list_needs_quality_view(): void
    {
        $this->actingWith(['production.view']);
        $this->getJson('/api/v1/quality/incoming-inspections')->assertForbidden();

        $this->actingWith(['quality.manage']);
        $this->getJson('/api/v1/quality/incoming-inspections')->assertOk();
    }
}
