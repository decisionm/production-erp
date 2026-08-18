<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Production\Models\ProductionStandard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET production/configuration/review — the list of what a person still has
 * to settle before every packing posts as ONE known Tally item (Phase 5,
 * P5-03): packagings and standards without a Tally identity, packagings
 * whose Tally name is shared by more than one item, and items still carrying
 * the SKU the masters pull seeded. Every row offers the existing Tally items
 * a person could LINK (exact/normalised name match, Tally-pulled and never a
 * fixture) — the ERP never creates a Tally-less item for real production.
 */
class ConfigurationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);
    }

    private function review(): array
    {
        return $this->getJson('/api/v1/production/configuration/review')->assertOk()->json('data');
    }

    private function tallyItem(string $sku, string $name, string $guid): Item
    {
        return Item::create(['sku' => $sku, 'name' => $name, 'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => $guid]);
    }

    private function standard(string $product, ?Item $item, array $extra = []): ProductionStandard
    {
        return ProductionStandard::create([
            'source_product_name' => $product, 'item_id' => $item?->id,
            'cavities' => 8, 'unit_weight_grams' => 18, 'cycle_time' => 12, 'status' => 'approved',
        ] + $extra);
    }

    public function test_a_fully_configured_catalogue_has_nothing_to_review(): void
    {
        $bottle = $this->tallyItem('BTL-1', 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos', 'itm-1');
        $standard = $this->standard('200ML RA', $bottle);
        $standard->packagings()->create(['mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520]);

        $this->assertSame(['rows' => []], $this->review());
    }

    public function test_a_packaging_inheriting_a_fixture_identity_is_listed_with_the_tally_items_it_could_link(): void
    {
        // The product is a LOCAL- fixture (Tally does not carry it yet); a
        // Tally item of exactly the workbook's product name has since been
        // pulled — that is the candidate, and it is the only one.
        $fixture = Item::create(['sku' => 'LOCAL-200ML-RA', 'name' => '200ML RA (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $real = $this->tallyItem('BTL-200', '200ml  ra', 'itm-200'); // spacing and case differ: normalised match
        $this->tallyItem('BTL-OTHER', '200ML RA CLEAR', 'itm-201');    // not an exact match: not offered
        Item::create(['sku' => 'LOCAL-TWIN', 'name' => '200ML RA', 'uom' => 'Nos', 'is_local_fixture' => true]); // a fixture is never a candidate

        $standard = $this->standard('200ML RA', $fixture);
        $packaging = $standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490]);

        $rows = $this->review()['rows'];

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('packaging_no_identity', $row['kind']);
        $this->assertSame(['id' => $standard->id, 'product' => '200ML RA'], $row['standard']);
        $this->assertSame([
            'id' => $packaging->id, 'mode' => 'tray',
            'counts' => ['nos_per_pouch' => null, 'pouches_per_box' => null, 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490],
        ], $row['packaging']);
        // The identity it resolves to today — the fixture — shown, not hidden.
        $this->assertSame(['id' => $fixture->id, 'sku' => 'LOCAL-200ML-RA', 'name' => '200ML RA (LOCAL FIXTURE)'], $row['item']);
        $this->assertSame(['tally_identity'], $row['missing']);
        $this->assertSame([[
            'id' => $real->id, 'sku' => 'BTL-200', 'name' => '200ml  ra', 'guid' => 'itm-200',
        ]], $row['candidates']);
        // Where the fix goes: the existing PUT standards/{standard}/packagings/{packaging}.
        $this->assertSame('packaging_item', $row['fix_target']);
    }

    public function test_a_standard_with_no_item_and_no_packaging_is_one_standard_level_row(): void
    {
        $standard = $this->standard('90ML RIB', null);
        $candidate = $this->tallyItem('BTL-90', '90ML RIB', 'itm-90');

        $rows = $this->review()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('packaging_no_identity', $rows[0]['kind']);
        $this->assertSame(['id' => $standard->id, 'product' => '90ML RIB'], $rows[0]['standard']);
        $this->assertNull($rows[0]['packaging']);
        $this->assertNull($rows[0]['item']);
        $this->assertSame(['tally_identity'], $rows[0]['missing']);
        $this->assertSame([$candidate->id], array_column($rows[0]['candidates'], 'id'));
        // A standard-level gap is closed by attaching the product's item.
        $this->assertSame('attach_item', $rows[0]['fix_target']);
    }

    public function test_a_packaging_with_its_own_good_identity_is_not_listed_even_when_the_product_lacks_one(): void
    {
        $fixture = Item::create(['sku' => 'LOCAL-X', 'name' => 'X (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $real = $this->tallyItem('BTL-X', 'X Bottle', 'itm-x');
        $standard = $this->standard('X', $fixture);
        $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 100, 'item_id' => $real->id]);

        $rows = $this->review()['rows'];

        // Nothing on the standard inherits the fixture, so the standard's
        // own gap is what is left — one row, at standard level.
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['packaging']);
        $this->assertSame('attach_item', $rows[0]['fix_target']);
    }

    public function test_a_shared_tally_name_is_listed_as_ambiguous_with_the_rows_that_share_it_as_candidates(): void
    {
        $bottle = $this->tallyItem('BTL-1', 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos', 'itm-1');
        $a = $this->tallyItem('BTL-A', 'B.200 Ml Round Pet Bottle Amber 18gms', 'itm-a');
        $b = $this->tallyItem('BTL-B', 'B.200 Ml Round Pet Bottle Amber 18gms', 'itm-b');
        // A fixture sharing the name is what LineMappingResolver counts too,
        // but it is never OFFERED — linking it would be linking nothing.
        Item::create(['sku' => 'LOCAL-C', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms', 'uom' => 'Nos', 'is_local_fixture' => true]);

        $standard = $this->standard('200ML RA', $bottle);
        $packaging = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490, 'item_id' => $a->id,
        ]);

        $rows = $this->review()['rows'];

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('packaging_ambiguous', $row['kind']);
        $this->assertSame($packaging->id, $row['packaging']['id']);
        $this->assertSame($a->id, $row['item']['id']);
        $this->assertSame([], $row['missing']);
        $this->assertSame(['shared_name_count' => 3], $row['ambiguity']);
        $this->assertSame([$a->id, $b->id], array_column($row['candidates'], 'id'));
        // ADVISORY: linking either row does not clear the ambiguity — Tally
        // matches a voucher line by NAME, and both rows carry it. The panel
        // offers no Link here; the duplicate is a catalogue question (Q43).
        $this->assertSame('name_ambiguity', $row['fix_target']);
    }

    public function test_an_inactive_item_is_never_offered_as_a_candidate(): void
    {
        // Two kinds of row, one rule: a retired item cannot become a packing's
        // identity (the identity requests refuse it), so offering it would
        // offer a refusal.
        $fixture = Item::create(['sku' => 'LOCAL-200ML-RA', 'name' => '200ML RA (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $active = $this->tallyItem('BTL-200', '200ML RA', 'itm-200');
        Item::create(['sku' => 'BTL-200-OLD', 'name' => '200ML RA', 'uom' => 'NOS', 'is_active' => false, 'tally_stock_item_guid' => 'itm-200-old']);
        $this->standard('200ML RA', $fixture)->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 100]);

        $bottle = $this->tallyItem('BTL-1', 'Bottle One - 520 Nos', 'itm-1');
        $a = $this->tallyItem('BTL-A', 'Bottle One', 'itm-a');
        $this->tallyItem('BTL-B', 'Bottle One', 'itm-b');
        Item::create(['sku' => 'BTL-C', 'name' => 'Bottle One', 'uom' => 'NOS', 'is_active' => false, 'tally_stock_item_guid' => 'itm-c']);
        $this->standard('ONE', $bottle)->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 10, 'item_id' => $a->id]);

        $rows = $this->review()['rows'];

        $this->assertCount(2, $rows);
        $identityRow = $rows[0];
        $this->assertSame('packaging_no_identity', $identityRow['kind']);
        $this->assertSame([$active->id], array_column($identityRow['candidates'], 'id'));

        $ambiguousRow = $rows[1];
        $this->assertSame('packaging_ambiguous', $ambiguousRow['kind']);
        $this->assertNotContains('BTL-C', array_column($ambiguousRow['candidates'], 'sku'));
        $this->assertSame(['BTL-A', 'BTL-B'], array_column($ambiguousRow['candidates'], 'sku'));
    }

    public function test_an_item_still_carrying_its_seeded_sku_is_listed(): void
    {
        $item = app(ItemService::class)->upsertFromTally(['guid' => 'itm-new', 'name' => 'B.170ml Pet Bottle', 'base_unit' => 'Nos'])['item'];
        // A same-named Tally item is the one candidate worth showing beside
        // it — the duplicate a real SKU would have to distinguish from.
        $twin = $this->tallyItem('BTL-170-OLD', 'b.170ml pet bottle', 'itm-old');

        $rows = $this->review()['rows'];

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('item_provisional_sku', $row['kind']);
        $this->assertNull($row['standard']);
        $this->assertNull($row['packaging']);
        $this->assertSame(['id' => $item->id, 'sku' => 'B.170ml Pet Bottle', 'name' => 'B.170ml Pet Bottle'], $row['item']);
        $this->assertSame([], $row['missing']);
        $this->assertSame([$twin->id], array_column($row['candidates'], 'id'));
        $this->assertSame('item_sku', $row['fix_target']);

        // A person setting a SKU takes it off the list.
        Item::query()->whereKey($item->id)->update(['sku' => 'BTL-170', 'sku_provisional' => false]);
        $this->assertSame([], $this->review()['rows']);
    }

    public function test_rows_come_in_a_stable_order_identity_gaps_then_ambiguity_then_provisional_skus(): void
    {
        $fixture = Item::create(['sku' => 'LOCAL-Z', 'name' => 'Z (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $this->standard('Z', $fixture)->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 10]);

        $bottle = $this->tallyItem('BTL-1', 'Bottle One', 'itm-1');
        $this->tallyItem('BTL-1B', 'Bottle One', 'itm-1b');
        $this->standard('ONE', $bottle)->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 10]);

        app(ItemService::class)->upsertFromTally(['guid' => 'itm-p', 'name' => 'Provisional Bottle', 'base_unit' => 'Nos']);

        $kinds = array_column($this->review()['rows'], 'kind');

        $this->assertSame(['packaging_no_identity', 'packaging_ambiguous', 'item_provisional_sku'], $kinds);
    }

    public function test_the_read_needs_production_view(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->getJson('/api/v1/production/configuration/review')->assertForbidden();
    }
}
