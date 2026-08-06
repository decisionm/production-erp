<?php

namespace Tests\Feature\Production;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Services\PackingMaterialSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The final carton and the polymer cover over it — one of each per batch.
 *
 * Asked for four times across two days: "one big box carton, one more big carton
 * has to come" (05-Aug), "one final box for all the batches completion need to be
 * add in consumption", "still final 1 carton box and polymer cover mising"
 * (06-Aug).
 *
 * They took a long time to place because they are NOT specs on a product. The
 * workbook has no column for either and the 38 Tally Stock Journals never name
 * one. They are standing facts about how this factory ships, so they are held once
 * for every product under STANDING_SPEC rather than per bottle.
 *
 * PER BATCH, not per carton, and that is the figure that matters: a run packing 40
 * master boxes still ships as one consignment in one outer box. Dosing either off
 * the carton count would issue forty outer boxes and post them.
 */
class StandingPackingLinesTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create([
            'sku' => 'B15', 'name' => 'A.15ml Round Pet Bottle Amber-5gms', 'uom' => 'Nos', 'is_active' => true,
        ]);
    }

    private function standard(?string $carton, ?string $tray = null, ?string $pouch = null): ProductionStandard
    {
        return ProductionStandard::create([
            'item_id' => $this->bottle->id,
            'source_product_name' => (string) $this->bottle->name,
            'carton_spec' => $carton,
            'tray_spec' => $tray,
            'pouch_spec' => $pouch,
            'status' => 'ready',
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function lines(ProductionStandard $standard): array
    {
        $out = [];

        foreach (app(PackingMaterialSuggestionService::class)->forStandard($standard) as $entry) {
            $out[$entry['kind']] = $entry;
        }

        return $out;
    }

    public function test_both_lines_appear_on_a_boxed_product(): void
    {
        $lines = $this->lines($this->standard('15ML', '60ML', '750*610'));

        $this->assertArrayHasKey('final_carton', $lines);
        $this->assertArrayHasKey('polymer_cover', $lines);
        $this->assertSame('Final carton', $lines['final_carton']['label']);
        $this->assertSame('Polymer cover', $lines['polymer_cover']['label']);
    }

    public function test_each_is_counted_once_per_batch(): void
    {
        $lines = $this->lines($this->standard('15ML', '60ML', '750*610'));

        foreach (['final_carton', 'polymer_cover'] as $kind) {
            $this->assertSame('per_batch', $lines[$kind]['basis'], "{$kind} must not be counted per carton.");
            $this->assertSame('batch', $lines[$kind]['quantity_basis']);
        }
    }

    public function test_the_final_carton_is_one_even_before_an_item_is_named(): void
    {
        // One outer box is one outer box, mapped or not — the same rule that gives
        // a carton a factor of 1. Without it the row would name a material and
        // count none of it, which is the worst of the three states.
        $lines = $this->lines($this->standard('15ML'));

        $this->assertSame('1', $lines['final_carton']['factor']);
        $this->assertSame('nos', $lines['final_carton']['unit']);
    }

    public function test_the_polymer_cover_has_no_default_quantity(): void
    {
        // DELIBERATELY UNLIKE THE CARTON. The cover is quoted in KILOGRAMS off a
        // grams figure, so a factor of 1 would read "1 Kg of cover" on the floor
        // and issue it. It computes nothing until the factory's counted weight is
        // recorded — the same rule the pouch follows.
        $lines = $this->lines($this->standard('15ML'));

        $this->assertNull($lines['polymer_cover']['factor']);
        $this->assertSame('kg', $lines['polymer_cover']['unit']);
        $this->assertSame('g', $lines['polymer_cover']['factor_unit']);
    }

    public function test_the_cover_takes_its_weight_from_the_mapping(): void
    {
        $cover = Item::create([
            'sku' => 'LD', 'name' => 'LDPE  COVER (30x49x120G)', 'uom' => 'Kgs.', 'is_active' => true,
        ]);

        PackingMaterialMapping::query()->create([
            'spec_kind' => PackingMaterialMapping::KIND_POLYMER_COVER,
            'spec_value' => PackingMaterialMapping::STANDING_SPEC,
            'item_id' => $cover->id,
            'grams_per_piece' => '50.0000',
        ]);

        $line = $this->lines($this->standard('15ML'))['polymer_cover'];

        $this->assertSame($cover->id, $line['item']['id']);
        $this->assertSame(0, bccomp((string) $line['factor'], '50.0000', 4));
        $this->assertTrue($line['submit_as_stock']);
        // One cover a batch at 50 g is 0.05 kg — the figure that reaches Tally.
        $this->assertSame(0, bccomp(bcdiv(bcmul('1', (string) $line['factor'], 8), '1000', 4), '0.0500', 4));
    }

    public function test_a_bag_packed_product_gets_neither(): void
    {
        // 17 workbook rows pack straight into an HM or LD bag, and the factory's
        // own rule is that the bag is the whole pack ("when HM, no need to use the
        // tray or pouch and other packing material"). An outer box over a product
        // that has no box, and a cover over that box, would be two invented lines
        // on a live voucher.
        $lines = $this->lines($this->standard('HM 30.5*49'));

        $this->assertSame(['carton'], array_keys($lines));
    }

    public function test_a_product_with_no_carton_gets_neither(): void
    {
        // Nothing to put in an outer box, and nothing for a cover to cover.
        $lines = $this->lines($this->standard(null, '60ML', '750*610'));

        $this->assertArrayNotHasKey('final_carton', $lines);
        $this->assertArrayNotHasKey('polymer_cover', $lines);
    }

    public function test_an_unnamed_standing_line_asks_for_the_material_by_name(): void
    {
        $lines = $this->lines($this->standard('15ML'));

        $this->assertNull($lines['final_carton']['item']);
        $this->assertSame('Final carton "ALL PRODUCTS" — choose the material', $lines['final_carton']['reason']);
        $this->assertSame('Polymer cover "ALL PRODUCTS" — choose the material', $lines['polymer_cover']['reason']);
    }

    public function test_naming_the_items_makes_both_lines_post(): void
    {
        // The whole point of the command: one run each and the two lines stop
        // being questions.
        $box = Item::create(['sku' => 'OB', 'name' => 'Corrugated Outer Carton', 'uom' => 'Nos', 'is_active' => true]);
        $cover = Item::create(['sku' => 'PC', 'name' => 'LDPE  COVER (30x49x120G)', 'uom' => 'Kgs.', 'is_active' => true]);

        $this->artisan('production:name-standing-packing', [
            '--kind' => 'final_carton', '--item' => 'Corrugated Outer Carton', '--write' => true,
        ])->assertSuccessful();

        $this->artisan('production:name-standing-packing', [
            '--kind' => 'polymer_cover', '--item' => 'LDPE  COVER (30x49x120G)', '--grams' => '50', '--write' => true,
        ])->assertSuccessful();

        $lines = $this->lines($this->standard('15ML'));

        $this->assertSame($box->id, $lines['final_carton']['item']['id']);
        $this->assertSame($cover->id, $lines['polymer_cover']['item']['id']);
        $this->assertSame(0, bccomp((string) $lines['polymer_cover']['factor'], '50.0000', 4));
        $this->assertTrue($lines['final_carton']['submit_as_stock']);
        $this->assertTrue($lines['polymer_cover']['submit_as_stock']);
    }

    public function test_a_cover_without_a_weight_is_refused_rather_than_stored_blank(): void
    {
        // A cover's quantity is kilograms derived from grams a piece. Stored with
        // no grams it would name a material and compute nothing — the silent blank
        // that cost the pouch and the masterbatch a day each.
        Item::create(['sku' => 'PC', 'name' => 'LDPE  COVER (30x49x120G)', 'uom' => 'Kgs.', 'is_active' => true]);

        $this->artisan('production:name-standing-packing', [
            '--kind' => 'polymer_cover', '--item' => 'LDPE  COVER (30x49x120G)', '--write' => true,
        ])->assertFailed();

        $this->assertSame(0, PackingMaterialMapping::query()->count());
    }

    public function test_an_unknown_item_is_refused(): void
    {
        // Naming the wrong outer box issues the wrong material on every voucher of
        // every product — a bigger error than a blank line.
        $this->artisan('production:name-standing-packing', [
            '--kind' => 'final_carton', '--item' => 'Corrugated Otuer Carton', '--write' => true,
        ])->assertFailed();

        $this->assertSame(0, PackingMaterialMapping::query()->count());
    }

    public function test_the_lines_come_last_so_packing_reads_in_order(): void
    {
        // Inner to outer, the way the floor packs: box, tray, pouch, tape, then
        // the outer box and the cover over it.
        $kinds = array_keys($this->lines($this->standard('15ML', '60ML', '750*610')));

        $this->assertSame(
            ['carton', 'tray', 'pouch_film', 'tape', 'final_carton', 'polymer_cover'],
            $kinds,
        );
    }
}
