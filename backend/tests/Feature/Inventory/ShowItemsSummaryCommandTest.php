<?php

namespace Tests\Feature\Inventory;

use App\Console\Commands\ShowItemsSummary;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `items:summary` is pointed at the LIVE database by a manual workflow, so
 * what is worth pinning is not its layout but the three claims somebody will
 * act on: that a local fixture is not reported as a Tally gap, that an
 * unclassified item is never quietly folded into a category, and that
 * "can post" means the same thing here as it does at the readiness gate.
 *
 * A report that disagrees with the code it describes is worse than no report.
 */
class ShowItemsSummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function item(string $sku, array $overrides = []): Item
    {
        return Item::query()->create(array_merge([
            'sku' => $sku,
            'name' => "Item {$sku}",
            'uom' => 'Nos',
            'is_active' => true,
            'tally_stock_item_guid' => 'guid-'.$sku,
        ], $overrides));
    }

    public function test_it_says_so_plainly_when_there_are_no_active_items(): void
    {
        $this->artisan('items:summary')
            ->expectsOutputToContain('No active items in this database.')
            ->assertSuccessful();
    }

    public function test_it_counts_each_category_and_names_the_unclassified_separately(): void
    {
        $this->item('FG-1', ['category' => ItemCategory::FinishedGood->value]);
        $this->item('RM-1', ['category' => ItemCategory::RawMaterial->value]);
        $this->item('RM-2', ['category' => ItemCategory::RawMaterial->value]);
        $this->item('PK-1', ['category' => ItemCategory::PackingMaterial->value]);
        // No category at all — the common live state.
        $this->item('UNK-1');

        $this->artisan('items:summary')
            ->expectsOutputToContain('CATALOGUE — 5 active')
            ->expectsOutputToContain(ShowItemsSummary::UNCLASSIFIED)
            ->assertSuccessful();
    }

    /**
     * THE FALSE POSITIVE THIS COMMAND MUST NOT PRODUCE.
     *
     * A local fixture exists in this database and nowhere in Tally, and its
     * missing GUID is deliberate (Item::isLocalFixture — flagged, or a
     * `LOCAL-` SKU, either alone is enough). Counting it as an item that
     * "cannot post" would put a permanent, unfixable line in front of whoever
     * works this list.
     */
    public function test_a_local_fixture_is_not_reported_as_a_tally_gap(): void
    {
        $this->item('LOCAL-FIXTURE-1', [
            'category' => ItemCategory::RawMaterial->value,
            'tally_stock_item_guid' => null,
            'is_local_fixture' => true,
        ]);

        $this->artisan('items:summary')
            ->expectsOutputToContain('local fixtures (no Tally item BY DESIGN)')
            // The verdict's blocked count must be zero: the only item without
            // a GUID is a fixture.
            ->expectsOutputToContain('Every non-fixture active item carries a Tally identity.')
            ->assertSuccessful();
    }

    /**
     * The other half of the same rule: a real item with no GUID IS a gap, and
     * must be named rather than merely counted — a number tells somebody
     * there is work, the name is what lets them start it.
     */
    public function test_a_real_item_without_a_guid_is_reported_and_named(): void
    {
        $this->item('PK-NOGUID', [
            'name' => 'Master Box 200ML',
            'category' => ItemCategory::PackingMaterial->value,
            'tally_stock_item_guid' => null,
        ]);

        $this->artisan('items:summary')
            ->expectsOutputToContain('packing_material that cannot post (not a fixture)')
            ->expectsOutputToContain('Master Box 200ML')
            ->assertSuccessful();
    }

    public function test_it_separates_a_provisional_sku_from_a_real_one(): void
    {
        $this->item('B.170ml Pet Bottle', ['sku_provisional' => true]);
        $this->item('FG-REAL', ['sku_provisional' => false]);

        $this->artisan('items:summary')
            ->expectsOutputToContain('still provisional (seeded from the Tally name)')
            ->assertSuccessful();
    }

    /**
     * Archived and soft-deleted items are counted, but apart. Folding either
     * into the live totals would inflate every number this command gives —
     * and a retired master is not a gap for anybody to chase.
     */
    public function test_inactive_and_deleted_items_are_counted_apart_from_the_active_ones(): void
    {
        $this->item('ACTIVE-1', ['category' => ItemCategory::RawMaterial->value]);
        $this->item('RETIRED-1', ['is_active' => false]);
        $this->item('GONE-1')->delete();

        $this->artisan('items:summary')
            ->expectsOutputToContain('CATALOGUE — 1 active, 1 inactive, 1 soft-deleted.')
            ->assertSuccessful();
    }

    /**
     * The command writes nothing. Pinned because it is aimed at the LIVE
     * database by a workflow carrying no dry run and no confirmation gate —
     * the justification for having neither is exactly this property.
     */
    public function test_it_writes_nothing(): void
    {
        $this->item('RM-1', ['category' => ItemCategory::RawMaterial->value]);
        $this->item('UNK-1');

        $before = [
            'items' => Item::query()->count(),
            'categories' => Item::query()->whereNotNull('category')->count(),
            'guids' => Item::query()->whereNotNull('tally_stock_item_guid')->count(),
        ];

        $this->artisan('items:summary')->assertSuccessful();

        $this->assertSame($before, [
            'items' => Item::query()->count(),
            'categories' => Item::query()->whereNotNull('category')->count(),
            'guids' => Item::query()->whereNotNull('tally_stock_item_guid')->count(),
        ], 'items:summary must be read-only — the live workflow has no dry run because of it.');
    }
}
