<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Product Standards workspace: one screen that answers "can this product
 * run, and if not, what EXACTLY is missing and where do I go to fix it?"
 *
 * Three things this file holds down.
 *
 *  1. EVERY incomplete row states numbered gaps in the readiness gate's own
 *     words. Not "incomplete", not "check the master" — the same sentence the
 *     supervisor will be refused with at Start Batch, plus the screen that
 *     closes it. A gap list that paraphrases the gate is a second vocabulary,
 *     and the person reading it then has to translate.
 *  2. The gaps are SEVERITY-BLIND. assess() downgrades everything to a
 *     warning when the gate's master switch is off — right for a gate being
 *     watched before it bites, and catastrophic here: the Incomplete view
 *     would empty and a factory of half-configured products would report
 *     itself ready.
 *  3. Pagination is real and the counts are honest, because this page is how
 *     the office works through eighty products.
 */
class ProductStandardsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor();
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** An item with nothing missing: colour, unit, and a Tally identity. */
    private function item(string $name, array $overrides = []): Item
    {
        return Item::create($overrides + [
            'sku' => $name,
            'name' => $name,
            'uom' => 'Nos.',
            'is_active' => true,
            'colour' => 'Amber',
            'tally_stock_item_guid' => 'itm-'.md5($name),
        ]);
    }

    /** A standard carrying all four run figures and one packing option. */
    private function standard(string $product, ?Item $item, array $overrides = [], ?int $nosPerBox = 500): ProductionStandard
    {
        $standard = ProductionStandard::create($overrides + [
            'item_id' => $item?->id,
            'source_product_name' => $product,
            'cavities' => 8,
            'unit_weight_grams' => '12.5',
            'cycle_time' => '11.5',
            'status' => 'approved',
            'source' => 'IMPORT',
        ]);

        if ($nosPerBox !== null) {
            ProductionStandardPackaging::create([
                'production_standard_id' => $standard->id,
                'mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 100,
                'trays_per_box' => 5,
                'nos_per_box' => $nosPerBox,
                'is_default' => true,
            ]);
        }

        return $standard;
    }

    private function workspace(array $params = [])
    {
        return $this->getJson('/api/v1/production/standards?'.http_build_query($params));
    }

    // ------------------------------------------------------------- the views

    public function test_the_default_view_is_production_ready_and_the_chips_count_all_three(): void
    {
        $this->standard('90ML RIB', $this->item('B.90ml Rib Pet Bottle Clear'));
        // No colour on the item — one gap, so this product cannot run.
        $this->standard('60ML ROUND', $this->item('A.60ml Round Pet Bottle', ['colour' => null]));
        // No item at all — the import's orphan.
        $this->standard('400ML HEXAGON', null);

        $body = $this->workspace()->assertOk()->json();

        $this->assertSame(['90ML RIB'], array_column($body['data'], 'source_product_name'));

        // The chips describe the whole filtered master, NOT the view being
        // shown — otherwise clicking Incomplete rewrites the number that told
        // you to click it.
        $this->assertSame(['ready' => 1, 'incomplete' => 2, 'all' => 3], $body['summary']);
        $this->assertSame(1, $body['total'], 'The pager counts the view, not the master.');
    }

    public function test_the_incomplete_and_all_views_are_one_parameter_away(): void
    {
        $this->standard('90ML RIB', $this->item('B.90ml Rib Pet Bottle Clear'));
        $this->standard('60ML ROUND', $this->item('A.60ml Round Pet Bottle', ['colour' => null]));

        $incomplete = $this->workspace(['view' => 'incomplete'])->assertOk()->json();
        $this->assertSame(['60ML ROUND'], array_column($incomplete['data'], 'source_product_name'));
        $this->assertSame(['ready' => 1, 'incomplete' => 1, 'all' => 2], $incomplete['summary']);

        $all = $this->workspace(['view' => 'all'])->assertOk()->json();
        $this->assertSame(['60ML ROUND', '90ML RIB'], array_column($all['data'], 'source_product_name'));
    }

    public function test_an_unknown_view_is_refused_rather_than_silently_reinterpreted(): void
    {
        $this->workspace(['view' => 'nearly'])->assertStatus(422)->assertJsonValidationErrors('view');
    }

    // -------------------------------------------------------------- the gaps

    public function test_every_gap_is_numbered_and_speaks_the_gates_own_words(): void
    {
        // A standard with nothing on it and an item with nothing on it: all
        // six checks fail at once, which is the row the office most needs to
        // be able to read.
        $bare = $this->item('D.Unknown Bottle', ['colour' => null, 'tally_stock_item_guid' => null]);
        ProductionStandard::create([
            'item_id' => $bare->id, 'source_product_name' => 'MYSTERY', 'status' => 'draft', 'source' => 'MANUAL',
        ]);

        $row = $this->workspace(['view' => 'incomplete'])->assertOk()->json('data.0');

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($row['gaps'], 'number'));
        $this->assertSame(
            ['weight', 'cycle_time', 'cavities', 'packing', 'colour', 'tally_item'],
            array_column($row['gaps'], 'key'),
            'Read in the order a supervisor reads them: what it runs to, how it is packed, what it is, whether Tally knows it.',
        );

        // Verbatim, because this is what Start Batch will refuse with.
        $this->assertSame(
            'No product weight — pieces cannot be converted to kg, so rejection and reconciliation cannot be calculated.',
            $row['gaps'][0]['sentence'],
        );
        $this->assertSame('Product weight (grams)', $row['gaps'][0]['label']);

        // Every gap names the screen that closes it. Four destinations for
        // six gaps — the three run figures are one edit.
        $this->assertSame(
            ['standard_edit', 'standard_edit', 'standard_edit', 'packing_edit', 'item_colour', 'attach_item'],
            array_column($row['gaps'], 'fix_target'),
        );
        $this->assertFalse($row['ready']);
    }

    public function test_a_figure_on_the_standard_closes_the_gap_the_item_leaves_open(): void
    {
        // The precedence every consumer uses: the factory standard outranks
        // the item master. An item with no cycle time is not a gap when the
        // standard carries one, because that is the figure the run will use.
        $item = $this->item('E.Bottle', ['standard_cycle_time' => null, 'nos_per_box' => null]);
        $this->standard('E PRODUCT', $item);

        $row = $this->workspace()->assertOk()->json('data.0');

        $this->assertSame([], $row['gaps']);
        $this->assertTrue($row['ready']);
    }

    public function test_gaps_are_reported_even_when_the_gate_is_not_enforcing(): void
    {
        // The master switch exists so a factory can watch the gate for a week
        // before letting it refuse anything. It must not blank this screen:
        // a missing master is a missing master whether or not Start Batch is
        // currently willing to overlook it.
        config()->set('production.readiness.enforced', false);
        config()->set('production.readiness.checks.colour', 'warn');

        $this->standard('60ML ROUND', $this->item('A.60ml Round', ['colour' => null]));

        $body = $this->workspace(['view' => 'incomplete'])->assertOk()->json();

        $this->assertSame(1, $body['summary']['incomplete']);
        $this->assertSame(['colour'], array_column($body['data'][0]['gaps'], 'key'));
    }

    // ------------------------------------------------------------ the tally

    public function test_an_unattached_standard_states_the_voucher_consequence_and_offers_the_fix(): void
    {
        $this->standard('400ML HEXAGON', null);

        $row = $this->workspace(['view' => 'incomplete'])->assertOk()->json('data.0');

        $this->assertFalse($row['tally']['attached']);
        $this->assertFalse($row['tally']['guid_present']);
        $this->assertSame(
            'Production can run; the voucher will not sync until this product is attached to a Tally stock item.',
            $row['tally']['sentence'],
            'Production is not blocked by this and the sentence must say so.',
        );

        $tallyGap = collect($row['gaps'])->firstWhere('key', 'tally_item');
        $this->assertSame('attach_item', $tallyGap['fix_target']);
    }

    public function test_a_local_fixture_raises_no_tally_gap_but_still_states_the_truth(): void
    {
        // 73 of 79 items are LOCAL- fixtures, fabricated precisely BECAUSE
        // Tally does not carry them. Reporting that as a gap on nearly the
        // whole catalogue is how a gap list stops being read — so it is not
        // a gap. It is still a fact, and the fact is still stated.
        $local = $this->item('LOCAL-90ML', ['sku' => 'LOCAL-90ML', 'tally_stock_item_guid' => null]);
        $this->standard('90ML RIB', $local);

        $row = $this->workspace()->assertOk()->json('data.0');

        $this->assertTrue($row['ready'], 'A local fixture is runnable; only its voucher waits.');
        $this->assertSame([], $row['gaps']);
        $this->assertTrue($row['tally']['attached']);
        $this->assertFalse($row['tally']['guid_present']);
        $this->assertSame(
            'Production can run; the voucher will not sync until this product exists in Tally.',
            $row['tally']['sentence'],
        );
    }

    // ------------------------------------------------------------ the joins

    public function test_a_row_carries_its_machine_exceptions_and_its_active_recipe(): void
    {
        $item = $this->item('B.90ml Rib Pet Bottle Clear');
        $standard = $this->standard('90ML RIB', $item);

        $machine = WorkCenter::create(['code' => 'MC-04', 'name' => 'Machine 4', 'is_active' => true, 'display_sequence' => 4]);
        $configuration = app(ProductionConfigurationService::class)->create([
            'work_center_id' => $machine->id, 'item_id' => $item->id,
            'unit_weight_grams' => '12.5', 'default_cycle_time' => '13', 'default_cavities' => 6,
            'colour' => 'Amber',
        ], null);
        app(ProductionConfigurationService::class)->approve($configuration, null);

        $resin = Item::create(['sku' => 'RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true]);
        $bom = app(BomService::class)->create([
            'item_id' => $item->id, 'name' => '90ML RIB recipe',
            'lines' => [['component_item_id' => $resin->id, 'quantity_per' => '0.0125']],
        ]);

        $row = $this->workspace()->assertOk()->json('data.0');

        $exception = $row['machine_exceptions'][0];
        $this->assertSame($configuration->id, $exception['id']);
        // Code AND name: the floor says MC-04, the office says Machine 4.
        $this->assertSame('MC-04', $exception['work_center']['code']);
        $this->assertSame('Machine 4', $exception['work_center']['name']);
        $this->assertSame('approved', $exception['status']);
        $this->assertSame('Amber', $exception['colour']);
        $this->assertSame(6, $exception['default_cavities']);
        $this->assertSame(now()->toDateString(), $exception['effective_from']);
        $this->assertNull($exception['effective_to']);

        // R4: the recipe is shown by reference, never copied and never a
        // readiness finding.
        $this->assertSame(['id' => $bom->id, 'name' => '90ML RIB recipe', 'version' => '1'], $row['active_recipe']);
        $this->assertNotContains('recipe', array_column($row['gaps'], 'key'));

        // And the expanded row can fetch the same exceptions on their own.
        $this->getJson("/api/v1/production/standards/{$standard->id}/machine-exceptions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $configuration->id)
            ->assertJsonPath('data.0.work_center.code', 'MC-04');
    }

    public function test_both_views_of_the_exceptions_list_agree_on_machine_order(): void
    {
        // The same configurations are shown twice on one screen: inside the
        // collapsed row, and again when the expanded row fetches them. If the
        // two order differently, the list appears to reshuffle when it is
        // opened — and MC-10 above MC-02 is the exact reshuffle the machine
        // ordering rule exists to prevent.
        //
        // MC-10 is created FIRST so its id is lower: ordering by id and
        // ordering naturally now disagree, which is the only way this test
        // can fail when it should.
        $item = $this->item('B.90ml Rib Pet Bottle Clear');
        $standard = $this->standard('90ML RIB', $item);

        $ten = WorkCenter::create(['code' => 'MC-10', 'name' => 'Machine 10', 'is_active' => true]);
        $two = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'is_active' => true]);
        $this->assertLessThan($two->id, $ten->id, 'The fixture only bites while MC-10 has the lower id.');

        foreach ([$ten, $two] as $machine) {
            app(ProductionConfigurationService::class)->create([
                'work_center_id' => $machine->id, 'item_id' => $item->id,
                'unit_weight_grams' => '12.5', 'default_cycle_time' => '13', 'default_cavities' => 6,
            ], null);
        }

        $inRow = array_column(
            array_column($this->workspace()->assertOk()->json('data.0.machine_exceptions'), 'work_center'),
            'code',
        );
        $expanded = array_column(
            array_column($this->getJson("/api/v1/production/standards/{$standard->id}/machine-exceptions")
                ->assertOk()->json('data'), 'work_center'),
            'code',
        );

        $this->assertSame(['MC-02', 'MC-10'], $inRow);
        $this->assertSame($inRow, $expanded);
    }

    public function test_the_recipe_named_here_is_the_one_the_run_will_consume(): void
    {
        // Only one active recipe per item is supposed to exist —
        // BomService::create() supersedes the previous one — but the importer
        // and any direct write can leave a second. This screen must then name
        // the recipe BomService::activeFor() hands the run, not a different
        // one: a workspace quoting recipe B while the batch consumes recipe A
        // is worse than showing nothing.
        $item = $this->item('B.90ml Rib Pet Bottle Clear');
        $this->standard('90ML RIB', $item);
        $resin = Item::create(['sku' => 'RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true]);

        $first = app(BomService::class)->create([
            'item_id' => $item->id, 'name' => 'First recipe', 'version' => '1',
            'lines' => [['component_item_id' => $resin->id, 'quantity_per' => '0.0125']],
        ]);
        app(BomService::class)->create([
            'item_id' => $item->id, 'name' => 'Second recipe', 'version' => '2',
            'lines' => [['component_item_id' => $resin->id, 'quantity_per' => '0.0130']],
        ]);

        // Force the state create() is designed to prevent — two active
        // recipes on one item, which only a direct write can leave behind.
        Bom::where('id', $first->id)->update(['is_active' => true]);
        $this->assertSame(2, Bom::where('item_id', $item->id)->where('is_active', true)->count());

        $shown = $this->workspace()->assertOk()->json('data.0.active_recipe');

        $this->assertSame(app(BomService::class)->activeFor($item->id)->id, $shown['id']);
    }

    public function test_a_product_with_no_exceptions_says_so_without_erroring(): void
    {
        $standard = $this->standard('90ML RIB', $this->item('B.90ml Rib'));
        $orphan = $this->standard('400ML HEXAGON', null);

        $this->assertSame([], $this->workspace()->assertOk()->json('data.0.machine_exceptions'));
        $this->assertNull($this->workspace()->assertOk()->json('data.0.active_recipe'));

        $this->getJson("/api/v1/production/standards/{$standard->id}/machine-exceptions")
            ->assertOk()->assertJsonCount(0, 'data');

        // A standard with no item has no exceptions by construction — an
        // empty list, not a 404 the page would have to special-case.
        $this->getJson("/api/v1/production/standards/{$orphan->id}/machine-exceptions")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // ----------------------------------------------------------- the filters

    public function test_search_finds_a_product_by_either_of_its_two_names(): void
    {
        $this->standard('90ML RIB', $this->item('B.90ml Rib Pet Bottle Clear'));
        $this->standard('60ML ROUND', $this->item('A.60ml Round Pet Bottle'));

        // The factory's name...
        $this->assertSame(
            ['90ML RIB'],
            array_column($this->workspace(['view' => 'all', 'search' => 'RIB'])->json('data'), 'source_product_name'),
        );

        // ...and Tally's, which looks nothing like it.
        $this->assertSame(
            ['60ML ROUND'],
            array_column($this->workspace(['view' => 'all', 'search' => 'A.60ml'])->json('data'), 'source_product_name'),
        );
    }

    public function test_the_missing_tally_filter_catches_both_kinds_of_missing(): void
    {
        $this->standard('90ML RIB', $this->item('B.90ml Rib'));
        $this->standard('60ML ROUND', $this->item('A.60ml Round', ['tally_stock_item_guid' => null]));
        $this->standard('400ML HEXAGON', null);

        $names = array_column($this->workspace(['view' => 'all', 'missing_tally' => 1])->json('data'), 'source_product_name');

        // No item at all, and an item Tally has never heard of — one job.
        $this->assertSame(['400ML HEXAGON', '60ML ROUND'], $names);
    }

    public function test_the_packing_mode_filter_selects_by_how_the_product_is_packed(): void
    {
        $tray = $this->standard('90ML RIB', $this->item('B.90ml Rib'));
        $pouched = $this->standard('60ML ROUND', $this->item('A.60ml Round'), [], null);
        ProductionStandardPackaging::create([
            'production_standard_id' => $pouched->id,
            'mode' => ProductionStandardPackaging::MODE_POUCH,
            'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225, 'is_default' => true,
        ]);

        $this->assertSame(
            [$pouched->id],
            array_column($this->workspace(['view' => 'all', 'packing_mode' => 'pouch'])->json('data'), 'id'),
        );
        $this->assertSame(
            [$tray->id],
            array_column($this->workspace(['view' => 'all', 'packing_mode' => 'tray'])->json('data'), 'id'),
        );
    }

    public function test_the_machine_filter_excludes_nothing_when_the_machine_has_no_stated_limits(): void
    {
        // Every machine today. A filter that answered "nothing fits Machine
        // 4" because Machine 4's capabilities were never measured would read
        // as a broken machine.
        $unmeasured = WorkCenter::create(['code' => 'MC-04', 'name' => 'Machine 4', 'is_active' => true]);
        $this->standard('90ML RIB', $this->item('B.90ml Rib'), ['cavities' => 8]);
        $this->standard('60ML ROUND', $this->item('A.60ml Round'), ['cavities' => 4]);

        $this->assertCount(2, $this->workspace(['view' => 'all', 'work_center_id' => $unmeasured->id])->json('data'));
    }

    public function test_the_machine_filter_honours_a_stated_cavity_capability(): void
    {
        $big = WorkCenter::create([
            'code' => 'MC-10', 'name' => 'Machine 10', 'is_active' => true, 'permitted_cavities' => [6, 8],
        ]);
        $this->standard('90ML RIB', $this->item('B.90ml Rib'), ['cavities' => 8]);
        $this->standard('60ML ROUND', $this->item('A.60ml Round'), ['cavities' => 4]);
        // Cavities unknown — a gap to fill, not a reason to hide the product
        // from the machine it will run on.
        $this->standard('MYSTERY', $this->item('C.Mystery'), ['cavities' => null]);

        $names = array_column($this->workspace(['view' => 'all', 'work_center_id' => $big->id])->json('data'), 'source_product_name');

        $this->assertSame(['90ML RIB', 'MYSTERY'], $names);
    }

    public function test_a_min_max_machine_bounds_the_same_way(): void
    {
        $machine = WorkCenter::create([
            'code' => 'MC-05', 'name' => 'Machine 5', 'is_active' => true, 'min_cavities' => 5, 'max_cavities' => 8,
        ]);
        $this->standard('90ML RIB', $this->item('B.90ml Rib'), ['cavities' => 8]);
        $this->standard('60ML ROUND', $this->item('A.60ml Round'), ['cavities' => 4]);

        $this->assertSame(
            ['90ML RIB'],
            array_column($this->workspace(['view' => 'all', 'work_center_id' => $machine->id])->json('data'), 'source_product_name'),
        );
    }

    public function test_the_start_batch_return_link_filters_still_work(): void
    {
        // startBatchResume sends a supervisor here to configure the product
        // they could not start — carrying item_id. That row is INCOMPLETE by
        // definition, so the link must be able to reach it.
        $item = $this->item('A.60ml Round', ['colour' => null]);
        $this->standard('60ML ROUND', $item, ['status' => 'draft']);
        $this->standard('90ML RIB', $this->item('B.90ml Rib'));
        $this->standard('400ML HEXAGON', null);

        $body = $this->workspace(['view' => 'all', 'item_id' => $item->id])->assertOk()->json();
        $this->assertSame(['60ML ROUND'], array_column($body['data'], 'source_product_name'));

        // The other two the old index answered, unchanged.
        $this->assertSame(
            ['60ML ROUND'],
            array_column($this->workspace(['view' => 'all', 'status' => 'draft'])->json('data'), 'source_product_name'),
        );
        $this->assertSame(
            ['60ML ROUND', '90ML RIB'],
            array_column($this->workspace(['view' => 'all', 'matched_only' => 1])->json('data'), 'source_product_name'),
        );
    }

    // -------------------------------------------------------- the pagination

    public function test_pagination_is_real_and_a_stale_page_size_is_clamped_not_refused(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->standard(sprintf('PRODUCT %03d', $i), $this->item("Item {$i}"));
        }

        $first = $this->workspace(['per_page' => 25])->assertOk()->json();
        $this->assertCount(25, $first['data']);
        $this->assertSame(60, $first['total']);
        $this->assertSame(3, $first['last_page']);
        $this->assertSame('PRODUCT 001', $first['data'][0]['source_product_name']);

        $third = $this->workspace(['per_page' => 25, 'page' => 3])->assertOk()->json();
        $this->assertCount(10, $third['data']);
        $this->assertSame('PRODUCT 060', $third['data'][9]['source_product_name']);

        // The old page asked for 200 rows. A stale tab must get a working
        // screen, not a 422 where the product master should be.
        $clamped = $this->workspace(['per_page' => 200])->assertOk()->json();
        $this->assertSame(100, $clamped['per_page']);
        $this->assertCount(60, $clamped['data']);

        // And a size below the smallest offered comes back as the smallest.
        $this->assertSame(25, $this->workspace(['per_page' => 10])->json('per_page'));
    }

    public function test_a_fifty_row_page_costs_a_fixed_handful_of_queries(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $item = $this->item("Item {$i}");
            $this->standard(sprintf('PRODUCT %03d', $i), $item);
        }

        $machine = WorkCenter::create(['code' => 'MC-04', 'name' => 'Machine 4', 'is_active' => true]);
        foreach (Item::query()->where('sku', 'like', 'Item %')->limit(50)->get() as $item) {
            app(ProductionConfigurationService::class)->create([
                'work_center_id' => $machine->id, 'item_id' => $item->id,
                'unit_weight_grams' => '12.5', 'default_cycle_time' => '13', 'default_cavities' => 6,
            ], null);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $body = $this->workspace(['per_page' => 50])->assertOk()->json();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(50, $body['data']);
        $this->assertNotEmpty($body['data'][0]['machine_exceptions']);

        // Seven at the time of writing — the auth/permission lookups, then
        // standards, items, packagings, configurations, machines and recipes,
        // one query each. The number must not scale with the row count: one
        // query per row for exceptions, or per row for the recipe, is how a
        // fifty-product page becomes a three-second page. The headroom is for
        // framework-level lookups, not for a new N+1.
        $this->assertLessThanOrEqual(
            12,
            $queries,
            "A 50-row workspace page ran {$queries} queries — something is loading per row.",
        );
    }

    public function test_every_key_the_page_already_read_is_still_on_the_row(): void
    {
        $item = $this->item('B.90ml Rib Pet Bottle Clear');
        $standard = $this->standard('90ML RIB', $item);

        $row = $this->workspace()->assertOk()->json('data.0');

        // The workspace fields are ADDITIONS. A row that dropped its item or
        // its packagings would break the attach flow and the packing editor
        // in the same deploy.
        $this->assertSame($standard->id, $row['id']);
        $this->assertSame('90ML RIB', $row['source_product_name']);
        $this->assertSame(8, $row['cavities']);
        $this->assertSame($item->id, $row['item']['id']);
        $this->assertSame('B.90ml Rib Pet Bottle Clear', $row['item']['name']);
        $this->assertSame(500, $row['packagings'][0]['nos_per_box']);
        $this->assertSame($row['packagings'][0]['id'], $row['resolved_packaging_id']);
    }

    // ------------------------------- finished goods no standard covers (WS)

    /**
     * THE DEFECT THIS CLOSES, in the shape the factory reported it.
     *
     * Five 100ML Emcure Amber bottles sit in the item master; two have
     * standards. Searching the Tally name of one of the other three returned
     * nothing at all — not an incomplete row, not an archived one, nothing —
     * so the screen could not distinguish "this product is fine" from "this
     * product has never been set up", and the answer to "why is it not in
     * product config?" was invisible.
     */
    public function test_a_searched_finished_good_with_no_standard_is_reported_beside_the_rows(): void
    {
        $configured = $this->item('B.100 ML Emcure Amber Pet Bottle-12.9gms WR', ['category' => 'finished_good']);
        $this->standard('100ML EMCURE - RING', $configured);

        $uncovered = $this->item('B.100 Ml Emcure Amber WOR Sangam', [
            'category' => 'finished_good',
            'nominal_weight_grams' => '12.9',
        ]);

        $body = $this->workspace(['view' => 'all', 'search' => 'emcure'])->assertOk()->json();

        // The standard still answers as it always did...
        $this->assertSame(['100ML EMCURE - RING'], array_column($body['data'], 'source_product_name'));

        // ...and the product with no standard is now ADMITTED rather than
        // silently absent.
        $this->assertSame(1, $body['unconfigured_items']['total']);
        $this->assertSame(
            ['B.100 Ml Emcure Amber WOR Sangam'],
            array_column($body['unconfigured_items']['data'], 'name'),
        );
        $this->assertSame($uncovered->id, $body['unconfigured_items']['data'][0]['item_id']);
        // What the item master already knows, so the person can see how much
        // of the new standard is actually left to fill in.
        $this->assertSame('12.9000', $body['unconfigured_items']['data'][0]['nominal_weight_grams']);
    }

    /**
     * Scope, not an optimisation. 371 finished goods against ~106 standards
     * on the live master: listing every uncovered one unconditionally buries
     * the workspace the floor reads.
     */
    public function test_nothing_is_listed_until_a_search_names_a_product(): void
    {
        $this->item('B.100 Ml Emcure Amber WOR Sangam', ['category' => 'finished_good']);

        $body = $this->workspace(['view' => 'all'])->assertOk()->json();

        $this->assertSame(['data' => [], 'total' => 0], $body['unconfigured_items']);
    }

    /**
     * An ARCHIVED standard still covers its item. Listing it as never
     * configured would send someone to create a duplicate of the row they
     * deliberately archived.
     */
    public function test_an_archived_standard_still_counts_as_covering_its_item(): void
    {
        $item = $this->item('B.100 Ml Emcure Amber WOR Sangam', ['category' => 'finished_good']);
        $this->standard('100ML EMCURE - SANGAM', $item)->delete();

        $body = $this->workspace(['view' => 'all', 'search' => 'emcure'])->assertOk()->json();

        $this->assertSame(0, $body['unconfigured_items']['total']);
    }

    /**
     * CATEGORY DECIDES. Raw material, packing material and — deliberately —
     * an item nobody has classified stay off this list. `category` is
     * assigned by a person and never inferred, so the honest cost is that an
     * unclassified bottle does not appear; the alternative is guessing a
     * factory value out of a name to put products on the list that the
     * factory does not make.
     */
    public function test_only_finished_goods_are_offered_and_never_an_unclassified_item(): void
    {
        $this->item('100ml Emcure Master Carton', ['category' => 'packing_material']);
        $this->item('Emcure Resin Amber', ['category' => 'raw_material']);
        $this->item('B.100 Ml Emcure Amber Unclassified', ['category' => null]);
        $this->item('B.100 Ml Emcure Amber Retired', ['category' => 'finished_good', 'is_active' => false]);
        $listed = $this->item('B.100 Ml Emcure Amber WOR Sangam', ['category' => 'finished_good']);

        $body = $this->workspace(['view' => 'all', 'search' => 'emcure'])->assertOk()->json();

        $this->assertSame([$listed->id], array_column($body['unconfigured_items']['data'], 'item_id'));
    }

    /**
     * A two-letter search matching two hundred items is a person who has not
     * finished typing, not a work queue: the count is reported in full and
     * the rows are not.
     */
    public function test_the_list_is_capped_and_the_count_stays_honest(): void
    {
        $limit = \App\Modules\Production\Services\ProductStandardsWorkspaceService::UNCONFIGURED_ITEM_LIMIT;

        foreach (range(1, $limit + 6) as $n) {
            $this->item(sprintf('B.100 Ml Emcure Amber Variant %02d', $n), ['category' => 'finished_good']);
        }

        $body = $this->workspace(['view' => 'all', 'search' => 'emcure'])->assertOk()->json();

        $this->assertCount($limit, $body['unconfigured_items']['data']);
        $this->assertSame($limit + 6, $body['unconfigured_items']['total']);
    }
}
