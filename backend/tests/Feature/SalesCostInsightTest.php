<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\BatchResinAllocation;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\MasterbatchDosing;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Production\Services\ResinPoolService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * HONEST COST VISIBILITY FOR SALES — what a salesperson is allowed to
 * believe about a number, and what they must be told instead.
 *
 * WHAT MUST HOLD, and each one is a scene below:
 *
 *  1. THE ESTIMATE IS PRICED AT THE COMMON RESIN POOL'S WEIGHTED AVERAGE —
 *     the same basis a real batch is allocated at, so the estimate and the
 *     actual are two readings of one thing. It used to quote the next bag by
 *     FIFO; the owner's correction (2-Aug) ended that basis along with the
 *     rest of the bag-to-batch claim, because with ONE common resin input
 *     point there is no "next bag this batch will draw from". Loading dearer
 *     resin on top re-averages the pool, with nobody re-entering anything.
 *  2. THE FALLBACK ADMITS ITSELF. With nothing priced in the pool, the
 *     estimate falls back to the stock moving average AND SAYS SO in words,
 *     because the two figures mean different things to whoever is quoting a
 *     price.
 *  3. THE WHOLE PIECE IS COSTED — resin, the masterbatch its colour doses,
 *     and the packing materials its standard states, each divided down to
 *     one piece, and the parts add up exactly to the total shown.
 *  4. AN ACTUAL IS A FACT OR IT IS ABSENT. No approved batch means
 *     actual_unit_cost null and 'no production batch yet — estimate only'.
 *     Never the estimate wearing an actual's label.
 *  5. AN AMENDED BATCH STOPS BEING AN ACTUAL. Reversing the allocation run
 *     takes the actual away again — a figure whose basis was withdrawn is
 *     not a figure.
 *  6. NO ORDER-LEVEL ACTUAL UNLESS EVERY LINE HAS ONE, and the order block
 *     says out loud that it is batch actuals added up, not an order-level
 *     cost allocation — because no such allocation exists in this system.
 *  7. RATES ARE FINANCE'S, AND BAG IDENTITIES ARE NOBODY'S. A sales login
 *     sees costs and margins; the rate anatomy behind them is ABSENT from its
 *     payload, not nulled. Bag barcodes and supplier lots are absent from
 *     BOTH payloads — the pool has no single bag behind it to name.
 *  8. THE ENDPOINT WRITES NOTHING, proved by watching every statement it
 *     fires — and a sales order still never touches stock.
 *
 * THE OPEN QUESTIONS STAY OPEN. Tape's unit is unanswered in this factory,
 * so PackingMaterialSuggestionService marks it not-postable and this module
 * leaves it out of the money rather than pricing a length against a rate per
 * No. Scene 3 asserts that it is EXCLUDED and named, not silently dropped and
 * not allowed to null every carton-packed product's estimate.
 */
class SalesCostInsightTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private Item $bottle;

    private Item $resin;

    private Item $masterbatch;

    private Item $carton;

    private Customer $customer;

    private ProductionStandard $standard;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('production.readiness.enforced', false);

        $this->store = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store', 'is_active' => true]);

        // One factory, one place — named explicitly so consumptionSource()
        // resolves the same warehouse for every material and the arithmetic
        // below is about prices rather than about warehouse resolution.
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);

        $this->bottle = Item::create([
            'sku' => 'BTL-170', 'name' => 'Bottle 170ML', 'uom' => 'Nos.',
            'colour' => 'Amber', 'is_active' => true,
        ]);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs.', 'is_active' => true]);
        $this->masterbatch = Item::create([
            'sku' => 'MB-AMB', 'name' => 'Master Batch Amber', 'uom' => 'Kgs.',
            'colour' => 'Amber', 'is_active' => true,
        ]);
        $this->carton = Item::create(['sku' => 'PK-BOX', 'name' => '170 Ml Master Box', 'uom' => 'Nos.', 'is_active' => true]);

        // 5 g per piece, packed 200 to a carton. carton_spec set and tray/film
        // absent, so this product's packing list is exactly a carton and the
        // tape that seals it.
        $this->standard = ProductionStandard::create([
            'item_id' => $this->bottle->id,
            'source_product_name' => 'Bottle 170ML',
            'cavities' => 4,
            'unit_weight_grams' => '5.0000',
            'cycle_time' => '12.00',
            'carton_spec' => '170ML',
            'status' => 'approved',
        ]);
        $this->standard->packagings()->create([
            'mode' => 'direct_box',
            'nos_per_box' => 200,
            'is_default' => true,
        ]);

        // 0.25 g of colourant per bottle — the factory's own figure.
        MasterbatchDosing::create([
            'masterbatch_item_id' => $this->masterbatch->id,
            'product_item_id' => $this->bottle->id,
            'grams_per_bottle' => '0.2500',
        ]);

        PackingMaterialMapping::create([
            'spec_kind' => PackingMaterialMapping::KIND_CARTON,
            'spec_value' => '170ML',
            'item_id' => $this->carton->id,
        ]);

        // Stock averages for the two materials priced off the ledger. Resin is
        // deliberately NOT given one in most scenes, so a resin figure that
        // came from the average instead of from a bag is visible on sight.
        $this->receive($this->masterbatch->id, '100.0000', '300.0000');
        $this->receive($this->carton->id, '500.0000', '40.0000');

        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Acme Beverages', 'is_active' => true]);
    }

    // ------------------------------------------------------------------
    // 1 + 3. The estimate, and the bag it is priced off
    // ------------------------------------------------------------------

    public function test_the_estimate_prices_every_part_of_a_piece_and_the_parts_add_up(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');

        // resin        5 g       @ 90.0000/kg   = 0.450000
        // masterbatch  0.25 g    @ 300.0000/kg  = 0.075000
        // carton       1 per 200 @ 40.0000 each = 0.200000
        // tape         excluded — its unit is still an open question
        $this->assertSame('0.725000', $line['estimate']['estimated_unit_cost']);
        $this->assertSame('estimate', $line['estimate']['label']);
        $this->assertNotNull($line['estimate']['as_of']);
        $this->assertNull($line['estimate']['reason']);

        $components = collect($line['estimate']['components'])->keyBy('kind');

        $this->assertSame('0.450000', $components['resin']['per_unit_cost']);
        $this->assertSame('0.075000', $components['masterbatch']['per_unit_cost']);
        $this->assertSame('0.200000', $components['carton']['per_unit_cost']);

        // THE OPEN QUESTION STAYS OPEN — named, excluded, and not allowed to
        // null the total of every carton-packed product in the factory.
        $this->assertSame('excluded', $components['tape']['status']);
        $this->assertNull($components['tape']['per_unit_cost']);

        // The parts an auditor is shown add up exactly to the total.
        $sum = collect($components)
            ->pluck('per_unit_cost')
            ->filter()
            ->reduce(fn (string $carry, string $each) => bcadd($carry, $each, 6), '0.000000');
        $this->assertSame($line['estimate']['estimated_unit_cost'], $sum);

        // (1.5000 - 0.725000) / 1.5000 = 51.66%
        $this->assertSame('51.66', $line['estimate']['estimated_margin_pct']);
    }

    public function test_the_estimate_moves_with_the_pool_when_dearer_resin_is_loaded_on_top(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');

        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        // 25 kg standing in the common input at 90.0000/kg, so 0.450000 of
        // the total.
        $before = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0.estimate');
        $this->assertSame('0.725000', $before['estimated_unit_cost']);
        $this->assertSame('0.450000', collect($before['components'])->firstWhere('kind', 'resin')['per_unit_cost']);

        // A dearer bag is loaded on top. THE ESTIMATE DOES NOT JUMP TO THE
        // NEW BAG'S PRICE — there is no "next bag this batch will draw from"
        // any more, because every bag goes into the same input. The pool
        // re-averages instead: (25×90 + 25×100) ÷ 50 = 95.
        $this->loadResin('BAG-B', '25.0000', '2026-07-25', '100.0000');

        $payload = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');

        // resin now 5 g @ 95.0000/kg = 0.475000, so 0.475 + 0.075 + 0.2
        $this->assertSame('0.750000', $payload['estimate']['estimated_unit_cost']);

        $resin = collect($payload['estimate']['components'])->firstWhere('kind', 'resin');
        $this->assertSame('95.0000', $resin['rate']);
        $this->assertSame('resin_pool_weighted_average', $resin['rate_source']);

        // AND NO BAG IS NAMED, even for finance — the pool has no single bag
        // behind it, and naming one would be the dead bag-to-batch claim
        // wearing a different hat.
        $this->assertArrayNotHasKey('bag_barcode', $resin);
        $this->assertArrayNotHasKey('material_bag_id', $resin);
    }

    // ------------------------------------------------------------------
    // 2. The fallback admits itself
    // ------------------------------------------------------------------

    public function test_with_no_bag_in_store_the_estimate_falls_back_to_the_average_and_labels_it(): void
    {
        // No resin bags at all — only a ledger balance to fall back on.
        $this->receive($this->resin->id, '100.0000', '80.0000');

        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);
        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');

        // resin 5 g @ 80.0000/kg = 0.400000
        $this->assertSame('0.675000', $line['estimate']['estimated_unit_cost']);

        $resin = collect($line['estimate']['components'])->firstWhere('kind', 'resin');
        $this->assertSame('store_average', $resin['rate_source']);
        $this->assertStringContainsString('store moving average', $resin['source']);

        // The identity-free words say it too, for a reader who never sees the
        // anatomy.
        $this->assertStringContainsString('store moving average', $line['estimate']['sources']['resin']);
    }

    public function test_a_material_with_no_recorded_price_nulls_the_estimate_and_says_which(): void
    {
        // A bag whose lot has no rate at all — opening stock, the commonest
        // case on day one — and no stock average behind it either.
        $this->bag('BAG-OPENING', '25.0000', '2026-07-20', null);

        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);
        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');

        $this->assertNull($line['estimate']['estimated_unit_cost']);
        $this->assertNull($line['estimate']['estimated_margin_pct']);
        $this->assertStringContainsString('resin', (string) $line['estimate']['reason']);

        // Never a partial order total either.
        $this->assertNull($line['estimate']['estimated_unit_cost']);
    }

    public function test_a_clear_bottle_doses_no_masterbatch_and_that_is_not_a_missing_price(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $this->bottle->update(['colour' => 'Clear']);

        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);
        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');

        // 0.450000 resin + 0.200000 carton, and no masterbatch to add.
        $this->assertSame('0.650000', $line['estimate']['estimated_unit_cost']);

        $masterbatch = collect($line['estimate']['components'])->firstWhere('kind', 'masterbatch');
        $this->assertSame('excluded', $masterbatch['status']);
        $this->assertNull($line['estimate']['reason']);
    }

    // ------------------------------------------------------------------
    // 4 + 5. An actual is a fact or it is absent
    // ------------------------------------------------------------------

    public function test_without_an_approved_batch_there_is_no_actual_only_a_reason(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');

        $this->assertNull($line['actual']['actual_unit_cost']);
        $this->assertNull($line['actual']['actual_margin_pct']);
        $this->assertSame('no production batch yet — estimate only', $line['actual']['reason']);
        $this->assertNull($line['actual']['source']);

        // The estimate is unaffected — it is the answer that always exists.
        $this->assertSame('0.725000', $line['estimate']['estimated_unit_cost']);
    }

    public function test_an_actual_appears_only_once_a_batch_is_approved_and_disappears_if_it_is_amended(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        // A completed but NOT yet approved batch: real production, not yet a
        // figure anybody may quote.
        $entry = $this->batch(ShiftProductionEntryStatus::Pending);
        $allocation = $this->allocate($entry, '50.0000', '90.0000');

        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');
        $this->assertNull($line['actual']['actual_unit_cost']);
        $this->assertSame('no production batch yet — estimate only', $line['actual']['reason']);

        // The accountant signs it off. Now it is a fact.
        $entry->update(['status' => ShiftProductionEntryStatus::Approved, 'approved_at' => now()]);

        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');

        // 50 kg @ 90.0000 = 4500.0000 across 10,000 accepted pieces = 0.4500
        $this->assertSame('0.4500', $line['actual']['actual_unit_cost']);
        $this->assertSame('actual', $line['actual']['label']);
        $this->assertSame('BATCH-1', $line['actual']['source']['batch_number']);
        $this->assertSame('2026-07-28', $line['actual']['source']['production_date']);
        $this->assertNotNull($line['actual']['source']['approved_at']);

        // (1.5000 - 0.4500) / 1.5000 = 70.00%
        $this->assertSame('70.00', $line['actual']['actual_margin_pct']);

        // A correction reverses the run. The batch is still approved, but the
        // basis of its cost has been withdrawn, so the actual goes with it.
        $allocation->update(['reversed_at' => now()]);

        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');
        $this->assertNull($line['actual']['actual_unit_cost']);

        // AND IT SAYS THE RIGHT SILENCE. There IS a batch — approved,
        // completed, sitting in the table — so telling the accountant "no
        // production batch yet" about one they signed off yesterday would be
        // a confidently wrong sentence about a batch they can see.
        $this->assertStringContainsString('withdrawn by a correction', (string) $line['actual']['reason']);
        $this->assertStringNotContainsString('no production batch yet', (string) $line['actual']['reason']);
    }

    public function test_a_synced_batch_is_still_an_approved_batch(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        // Synced is downstream of the accountant's approval, never a step back
        // from it — an actual that vanished the moment its batch reached Tally
        // would be a bug nobody sees until the day after a deploy.
        $entry = $this->batch(ShiftProductionEntryStatus::Synced);
        $this->allocate($entry, '50.0000', '90.0000');

        $line = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.lines.0');
        $this->assertSame('0.4500', $line['actual']['actual_unit_cost']);
    }

    // ------------------------------------------------------------------
    // 6. The order-level block
    // ------------------------------------------------------------------

    public function test_the_order_actual_is_withheld_until_every_line_has_one_and_never_claims_an_allocation(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');

        $other = Item::create(['sku' => 'BTL-500', 'name' => 'Bottle 500ML', 'uom' => 'Nos.', 'colour' => 'Clear', 'is_active' => true]);
        ProductionStandard::create([
            'item_id' => $other->id, 'source_product_name' => 'Bottle 500ML',
            'cavities' => 2, 'unit_weight_grams' => '10.0000',
            'cycle_time' => '15.00', 'carton_spec' => '170ML', 'status' => 'approved',
        ])->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 100, 'is_default' => true]);

        $order = $this->order([
            ['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000'],
            ['item' => $other, 'quantity' => '5000', 'unit_price' => '2.0000'],
        ]);

        // Only the first product has been made.
        $entry = $this->batch(ShiftProductionEntryStatus::Approved);
        $this->allocate($entry, '50.0000', '90.0000');

        $data = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data');

        $this->assertNotNull($data['lines'][0]['actual']['actual_unit_cost']);
        $this->assertNull($data['lines'][1]['actual']['actual_unit_cost']);

        // ONE line short is no order actual at all — never the made third
        // averaged with the estimate of the rest.
        $this->assertNull($data['order_actual']['cost_total']);
        $this->assertNull($data['order_actual']['margin_pct']);
        $this->assertStringContainsString('no approved batch', (string) $data['order_actual']['reason']);

        // And the block says what it would be even when it is empty, so no
        // reader can infer a finished-goods allocation that does not exist.
        $this->assertSame('from batch actuals, not order-allocated', $data['order_actual']['basis']);

        // Revenue is still real and still reported: 10000 × 1.5 + 5000 × 2.0
        $this->assertSame('25000.0000', $data['order_actual']['revenue_total']);

        // The order ESTIMATE is complete, because every line has one.
        // 10000 × 0.725000 = 7250.0000, plus the Clear 500ML at
        // 10 g @ 90.0000 = 0.900000 resin + 40.0000/100 = 0.400000 carton
        // and no masterbatch: 5000 × 1.300000 = 6500.0000.
        $this->assertSame('13750.0000', $data['order_estimate']['cost_total']);
        $this->assertSame('45.00', $data['order_estimate']['margin_pct']);
    }

    public function test_the_order_actual_lands_once_every_line_is_backed_by_a_batch(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        $entry = $this->batch(ShiftProductionEntryStatus::Approved);
        $this->allocate($entry, '50.0000', '90.0000');

        $block = $this->actingAsFinance()->getJson($this->url($order))->assertOk()->json('data.order_actual');

        $this->assertSame('4500.0000', $block['cost_total']);
        $this->assertSame('15000.0000', $block['revenue_total']);
        $this->assertSame('70.00', $block['margin_pct']);
        $this->assertSame('from batch actuals, not order-allocated', $block['basis']);
    }

    // ------------------------------------------------------------------
    // 7. Rates and identities are finance's
    // ------------------------------------------------------------------

    public function test_a_sales_login_sees_the_money_but_never_the_bags_behind_it(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $lot = MaterialLot::query()->firstOrFail();
        $lot->update(['supplier_lot_no' => 'SUP-LOT-77']);

        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        $sales = $this->actingAsSales()->getJson($this->url($order))->assertOk();
        $line = $sales->json('data.lines.0');

        // The money is the whole point of the feature and is NOT gated.
        $this->assertSame('0.725000', $line['estimate']['estimated_unit_cost']);
        $this->assertSame('51.66', $line['estimate']['estimated_margin_pct']);

        // ABSENT, not nulled — null is a real state in this payload (a lot
        // with no recorded rate genuinely has none), so a nulled breakdown
        // would be indistinguishable from a factory with no rates at all.
        $this->assertArrayNotHasKey('components', $line['estimate']);

        // Explanations still reach them — in words, with no identity in them.
        $this->assertArrayHasKey('resin', $line['estimate']['sources']);
        $this->assertStringNotContainsString('BAG-A', json_encode($sales->json()));
        $this->assertStringNotContainsString('SUP-LOT-77', json_encode($sales->json()));
        $this->assertStringNotContainsString('90.0000', json_encode($sales->json()));

        // Finance, on the same order, gets the anatomy.
        $finance = $this->actingAsFinance()->getJson($this->url($order))->assertOk();
        $resin = collect($finance->json('data.lines.0.estimate.components'))->firstWhere('kind', 'resin');

        // The RATE, yes — that is the anatomy finance is entitled to.
        $this->assertSame('90.0000', $resin['rate']);
        $this->assertSame('per_kg', $resin['rate_unit']);
        $this->assertSame('resin_pool_weighted_average', $resin['rate_source']);

        // THE BAG AND THE SUPPLIER LOT, NO — not withheld from sales and
        // handed to finance, but GONE. The estimate is the common pool's
        // weighted average across every bag ever loaded; there is no single
        // bag it could name without lying about where the number came from.
        $this->assertArrayNotHasKey('bag_barcode', $resin);
        $this->assertArrayNotHasKey('supplier_lot_no', $resin);
        $this->assertStringNotContainsString('BAG-A', json_encode($finance->json()));
        $this->assertStringNotContainsString('SUP-LOT-77', json_encode($finance->json()));
    }

    public function test_the_endpoint_is_closed_to_a_login_without_sales_access(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        $this->getJson($this->url($order))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // 8. It writes nothing
    // ------------------------------------------------------------------

    public function test_reading_the_cost_insight_changes_nothing_in_the_world(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');
        $order = $this->order([['item' => $this->bottle, 'quantity' => '10000', 'unit_price' => '1.5000']]);

        $entry = $this->batch(ShiftProductionEntryStatus::Approved);
        $this->allocate($entry, '50.0000', '90.0000');

        // Authenticated BEFORE the listener goes on, so the fixture's own user
        // and permission inserts are not mistaken for the read's.
        $this->actingAsFinance();

        $before = $this->worldSnapshot();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->getJson($this->url($order))->assertOk();

        // The check that cannot be fooled: row counts would miss an in-place
        // update, and an average_cost comparison would miss a write that
        // happened to restate the same figure.
        $this->assertSame([], $writes, 'The cost-insight read fired a write statement.');
        $this->assertSame($before, $this->worldSnapshot());

        // THE OTHER BRANCH, under the same listener. With the POOL drawn dry
        // the resin rate falls through to the moving average, which resolves
        // a warehouse through the day bin — a configured bin, so the
        // holds-stock query really runs rather than short-circuiting on a
        // null setting. Proving the happy path read-only proves very little.
        $dayBin = Warehouse::create(['code' => 'WH-DAYBIN', 'name' => 'Factory Day Bin', 'is_active' => true]);
        app(FactoryDayBinService::class)->setWarehouseId($dayBin->id);
        app(ResinPoolService::class)->draw($this->resin->id, '25.0000');
        MaterialBag::query()->update(['remaining_kg' => '0.0000', 'status' => MaterialBagStatus::Consumed]);
        $this->receive($this->resin->id, '100.0000', '80.0000');

        $before = $this->worldSnapshot();
        $writes = [];

        $response = $this->getJson($this->url($order))->assertOk();

        // The scene only means something if the fallback actually priced —
        // an estimate that quietly went unknown would skip the very lookups
        // this pass exists to watch.
        $this->assertSame('0.675000', $response->json('data.lines.0.estimate.estimated_unit_cost'));
        $this->assertSame([], $writes, 'The average-cost fallback fired a write statement.');
        $this->assertSame($before, $this->worldSnapshot());
    }

    public function test_a_sales_order_still_never_touches_stock(): void
    {
        $this->loadResin('BAG-A', '25.0000', '2026-07-20', '90.0000');

        // Writing an order needs sales.manage — EnsureModulePermission's rule.
        $this->actingWith(['sales.manage']);

        $before = $this->worldSnapshot();

        $created = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $this->customer->id,
            'order_date' => '2026-07-30',
            'lines' => [
                ['item_id' => $this->bottle->id, 'quantity' => '10000', 'unit_price' => '1.5000'],
            ],
        ])->assertSuccessful();

        $this->postJson("/api/v1/sales/sales-orders/{$created->json('data.id')}/confirm")->assertOk();

        $this->assertSame($before, $this->worldSnapshot());
    }

    // ------------------------------------------------------------------
    // Fixtures and helpers
    // ------------------------------------------------------------------

    /**
     * Everything a cost read could conceivably disturb — counts AND the
     * valuation figures themselves, because a count alone cannot see an
     * in-place rewrite of a moving average.
     *
     * @return array<string, mixed>
     */
    private function worldSnapshot(): array
    {
        return [
            'stock_movements' => DB::table('stock_movements')->count(),
            'stock_balances' => DB::table('stock_balances')->count(),
            'batch_resin_allocations' => DB::table('batch_resin_allocations')->count(),
            'material_bags' => DB::table('material_bags')->count(),
            'material_lots' => DB::table('material_lots')->count(),
            'material_cost_versions' => DB::table('material_cost_versions')->count(),
            'day_bin_movements' => DB::table('day_bin_movements')->count(),
            'shift_production_entries' => DB::table('shift_production_entries')->count(),
            // The Accounts-approved valuation, byte for byte.
            'average_costs' => StockBalance::query()
                ->orderBy('id')
                ->pluck('average_cost', 'id')
                ->map(fn ($cost) => (string) $cost)
                ->all(),
            'bag_remaining' => MaterialBag::query()
                ->orderBy('id')
                ->pluck('remaining_kg', 'id')
                ->map(fn ($kg) => (string) $kg)
                ->all(),
        ];
    }

    /** A registered bag of resin sitting in the store, at a known rate. */
    private function bag(string $barcode, string $kg, string $receivedDate, ?string $rate): MaterialBag
    {
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => $receivedDate,
            'bag_count' => 1,
            'total_received_kg' => $kg,
            'receipt_rate_per_kg' => $rate,
            'rate_source' => $rate === null ? null : 'grn',
        ]);

        return $lot->bags()->create([
            'barcode' => $barcode,
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ]);
    }

    /**
     * A bag REGISTERED AND THEN LOADED at the common resin input — which is
     * the only way a rate reaches the pool, and therefore the only way the
     * estimate can quote one.
     *
     * The fold is called directly rather than through the scan endpoint
     * because what these scenes are about is what SALES does with a priced
     * pool, not how one comes to exist (BagCostTraceabilityTest covers the
     * scan end to end, gate and all).
     */
    private function loadResin(string $barcode, string $kg, string $receivedDate, ?string $rate): MaterialBag
    {
        $bag = $this->bag($barcode, $kg, $receivedDate, $rate);

        app(ResinPoolService::class)->fold($this->resin->id, $kg, $rate);

        return $bag;
    }

    private function receive(int $itemId, string $quantity, string $unitCost): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $itemId,
            warehouseId: $this->store->id,
            quantity: $quantity,
            unitCost: $unitCost,
            reference: 'FIXTURE',
            movementDate: '2026-07-20',
        );
    }

    /**
     * A completed batch of the bottle, 10,000 accepted pieces, consuming 50 kg
     * of resin — built directly rather than through the floor endpoints,
     * because what these scenes are about is what SALES does with a costed
     * batch, not how one comes to exist (BagCostTraceabilityTest covers that
     * end to end).
     */
    private function batch(ShiftProductionEntryStatus $status): ShiftProductionEntry
    {
        $shift = Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-07-28',
            'batch_status' => BatchStatus::Completed,
            'batch_number' => 'BATCH-1',
            'quantity_produced' => '10000',
            'status' => $status,
            'approved_at' => in_array($status, [ShiftProductionEntryStatus::Approved, ShiftProductionEntryStatus::Synced], true)
                ? now()
                : null,
        ]);

        ShiftMaterialConsumption::create([
            'shift_production_entry_id' => $entry->id,
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity_issued_kg' => '50.0000',
        ]);

        return $entry;
    }

    /** The batch's live bag-cost allocation run. */
    private function allocate(ShiftProductionEntry $entry, string $kg, string $rate): BatchResinAllocation
    {
        return BatchResinAllocation::create([
            'shift_production_entry_id' => $entry->id,
            'allocation_run' => 1,
            'item_id' => $this->resin->id,
            'quantity_kg' => $kg,
            'rate_per_kg' => $rate,
            'amount' => bcmul($kg, $rate, 4),
            'rate_source' => 'bag_receipt',
        ]);
    }

    /** @param list<array{item: Item, quantity: string, unit_price: string}> $lines */
    private function order(array $lines): SalesOrder
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'order_date' => '2026-07-30',
        ]);

        foreach ($lines as $line) {
            $order->lines()->create([
                'item_id' => $line['item']->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'quantity_delivered' => 0,
            ]);
        }

        return $order;
    }

    private function url(SalesOrder $order): string
    {
        return "/api/v1/sales/sales-orders/{$order->id}/cost-insight";
    }

    private function actingAsSales(): static
    {
        return $this->actingWith(['sales.view']);
    }

    private function actingAsFinance(): static
    {
        return $this->actingWith(['sales.view', 'finance.view']);
    }

    /** @param list<string> $permissions */
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
