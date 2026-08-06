<?php

namespace Tests\Feature\Production;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Services\ProductionStandardResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telling 840 from 810 on screen.
 *
 * THE DEFECT. 18 of the production workbook's 103 rows are ONE product counted
 * two ways: identical cavities, weight and cycle time, differing only in how many
 * bottles go in a box. 100ML ROUND at 12.9 g is SL 8 (pouch, 168/pouch → 840/box)
 * and SL 9 (tray, 162/tray → 810/box), and SL 35 carries both on one row.
 *
 * The variant picker labelled each standard with its cavities, weight and cycle
 * time — so the floor was shown two buttons reading "5 cav · 12.9 g · 12.3 s" and
 * "5 cav · 12.9 g · 12.3 s" and asked to choose. The screen's own hint even said
 * "same product, different cavity / weight / cycle time", which for these is
 * false in all three.
 *
 * Reported from the demo (05-Aug): "the number like 840 on 100 ml round pet
 * clear, they say for one round 840, another time 810". Both are right. Neither
 * was on screen.
 *
 * Their Tally agrees the pack is part of a product's identity — 17 items carry
 * the count in the name, "…Clear 12.9 Gms -840 Nos" among them.
 */
class PackVariantLabelTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create([
            'sku' => 'B100RC', 'name' => 'B.100 Ml Round Clear Pet Bottle',
            'uom' => 'Nos.', 'nominal_weight_grams' => '12.9000', 'is_active' => true,
        ]);
    }

    /** The real SL 8 / SL 9 pair, as the workbook has them. */
    private function packPair(): array
    {
        $pouch = ProductionStandard::create([
            'item_id' => $this->bottle->id, 'source_product_name' => '100ML ROUND',
            'cavities' => 5, 'unit_weight_grams' => '12.9000', 'cycle_time' => '12.30',
            'status' => 'approved', 'source_reference' => '8',
        ]);
        ProductionStandardPackaging::create([
            'production_standard_id' => $pouch->id, 'mode' => ProductionStandardPackaging::MODE_POUCH,
            'nos_per_pouch' => 168, 'pouches_per_box' => 5, 'nos_per_box' => 840, 'is_default' => true,
        ]);

        $tray = ProductionStandard::create([
            'item_id' => $this->bottle->id, 'source_product_name' => '100ML ROUND',
            'cavities' => 5, 'unit_weight_grams' => '12.9000', 'cycle_time' => '12.31',
            'status' => 'approved', 'source_reference' => '9',
        ]);
        ProductionStandardPackaging::create([
            'production_standard_id' => $tray->id, 'mode' => ProductionStandardPackaging::MODE_TRAY,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810, 'is_default' => true,
        ]);

        return [$pouch, $tray];
    }

    public function test_two_pack_variants_do_not_read_identically(): void
    {
        [$pouch, $tray] = $this->packPair();

        $labels = app(ProductionStandardResolver::class)
            ->variantsFor($this->bottle->id)
            ->map(fn (ProductionStandard $standard) => $standard->variantLabel())
            ->all();

        // THE POINT OF THE WHOLE CHANGE. Two buttons a supervisor cannot tell
        // apart is not a choice, it is a coin toss with a Tally voucher on it.
        $this->assertCount(2, array_unique($labels), 'Both variants read the same: '.implode(' / ', $labels));

        $this->assertStringContainsString('840/box', $pouch->fresh()->load('packagings')->variantLabel());
        $this->assertStringContainsString('810/box', $tray->fresh()->load('packagings')->variantLabel());
    }

    public function test_the_label_still_carries_the_figures_it_always_did(): void
    {
        [$pouch] = $this->packPair();

        $label = $pouch->fresh()->load('packagings')->variantLabel();

        // Additive, not a replacement: cavities, weight and cycle time are still
        // how a supervisor recognises a genuinely different run.
        $this->assertStringContainsString('5 cav', $label);
        $this->assertStringContainsString('12.9 g', $label);
        $this->assertStringContainsString('12.3 s', $label);
    }

    public function test_a_standard_carrying_both_packs_names_both_counts(): void
    {
        // SL 35, the row that proves the two are one product: pouch 168/840 AND
        // tray 162/810 on a single line of the workbook.
        $standard = ProductionStandard::create([
            'item_id' => $this->bottle->id, 'source_product_name' => '100ML ROUND',
            'cavities' => 5, 'unit_weight_grams' => '12.9000', 'cycle_time' => '11.50',
            'status' => 'approved', 'source_reference' => '35',
        ]);
        ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => ProductionStandardPackaging::MODE_POUCH,
            'nos_per_pouch' => 168, 'pouches_per_box' => 5, 'nos_per_box' => 840, 'is_default' => true,
        ]);
        ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => ProductionStandardPackaging::MODE_TRAY,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810, 'is_default' => false,
        ]);

        // Bigger box first, because that is the order the factory says it in.
        $this->assertStringContainsString('840 or 810/box', $standard->load('packagings')->variantLabel());
    }

    public function test_a_standard_with_no_packaging_says_nothing_about_a_box(): void
    {
        // 72 of this factory's standards were incomplete at the time of writing,
        // many with no packing at all. A label reading "0/box" or a bare "/box"
        // would invent a pack for a product that has none.
        $standard = ProductionStandard::create([
            'item_id' => $this->bottle->id, 'source_product_name' => '100ML ROUND',
            'cavities' => 5, 'unit_weight_grams' => '12.9000', 'cycle_time' => '12.30',
            'status' => 'draft', 'source_reference' => '99',
        ]);

        $label = $standard->load('packagings')->variantLabel();

        $this->assertSame('5 cav · 12.9 g · 12.3 s', $label);
        $this->assertStringNotContainsString('box', $label);
    }

    public function test_the_label_costs_no_query_when_the_relation_is_not_loaded(): void
    {
        // Guards the lazy-load trap rather than a preference: variantsFor()
        // eager-loads packagings, and a label that queried per variant would turn
        // a list of eight standards into eight extra queries on a floor screen
        // that repolls. Not loaded means not mentioned.
        $standard = ProductionStandard::create([
            'item_id' => $this->bottle->id, 'source_product_name' => '100ML ROUND',
            'cavities' => 5, 'unit_weight_grams' => '12.9000', 'cycle_time' => '12.30',
            'status' => 'approved', 'source_reference' => '8',
        ]);
        ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => ProductionStandardPackaging::MODE_POUCH,
            'nos_per_pouch' => 168, 'pouches_per_box' => 5, 'nos_per_box' => 840, 'is_default' => true,
        ]);

        $unloaded = ProductionStandard::query()->findOrFail($standard->id);
        $this->assertFalse($unloaded->relationLoaded('packagings'));
        $this->assertStringNotContainsString('box', $unloaded->variantLabel());

        // And the resolver, which every real caller goes through, does load it.
        $this->assertStringContainsString(
            '840/box',
            app(ProductionStandardResolver::class)->variantsFor($this->bottle->id)->sole()->variantLabel(),
        );
    }

    public function test_duplicate_box_counts_are_stated_once(): void
    {
        // A product packed two ways into the same size box — pouch and tray both
        // reaching 400 — has one thing to say about its box, not two.
        $standard = ProductionStandard::create([
            'item_id' => $this->bottle->id, 'source_product_name' => '250ML SQUARE',
            'cavities' => 4, 'unit_weight_grams' => '20.0000', 'cycle_time' => '18.00',
            'status' => 'approved', 'source_reference' => '51',
        ]);
        foreach ([ProductionStandardPackaging::MODE_POUCH, ProductionStandardPackaging::MODE_TRAY] as $mode) {
            ProductionStandardPackaging::create([
                'production_standard_id' => $standard->id, 'mode' => $mode,
                'nos_per_pouch' => 80, 'pouches_per_box' => 5,
                'nos_per_tray' => 80, 'trays_per_box' => 5,
                'nos_per_box' => 400, 'is_default' => $mode === ProductionStandardPackaging::MODE_POUCH,
            ]);
        }

        $this->assertStringContainsString('400/box', $standard->load('packagings')->variantLabel());
        $this->assertStringNotContainsString('400 or 400', $standard->variantLabel());
    }
}
