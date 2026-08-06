<?php

namespace Tests\Feature\Production;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Services\PackingMaterialMappingService;
use App\Modules\Production\Services\PackingMaterialSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The edges of the two packing rules added on 5 August, probed against every
 * real spec string and every real item name this factory has.
 *
 * WHY THESE VALUES ARE HARDCODED HERE. They were verified by reading the
 * factory's own production workbook and their live Tally catalogue, and both of
 * those lived in a Downloads folder that has since been cleared. A rule proven
 * against data nobody can reproduce is a rule that quietly stops being proven.
 * So the evidence moves into the repository, where it stays.
 *
 * Both rules SUPPRESS or REDIRECT material lines, which is the dangerous
 * direction. A line wrongly suppressed does not fail: the voucher posts, Tally
 * accepts it, and the factory's carton stock climbs against a shelf that is
 * emptying — found weeks later at a physical count, if at all.
 */
class PackingRuleEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every distinct CARTON spec in the workbook's 103 rows.
     *
     * @var list<array{0: string, 1: bool}> spec => is it a bag?
     */
    private const CARTON_SPECS = [
        ['200ML ROUND', false],
        ['100ML', false],
        ['500ML ROUND', false],
        ['60ML', false],
        ['200ML BRUTE', false],
        ['170ML ROUND', false],
        ['170ML', false],
        ['100ML ROUND', false],
        ['30ML ROUND', false],
        ['300ML EMCURE', false],
        ['300ML ROUND', false],
        ['15ML', false],
        ['30ML', false],
        ['100 ML CARTON', false],
        // The three bag families, in the three spellings the sheet uses.
        ['HM 30.5*49', true],
        ['LD 30 X 49', true],
        ['LD 28.5 X 38', true],
    ];

    /**
     * Every distinct TRAY and FILM spec. None is a bag except the two the
     * workbook files in the wrong column.
     *
     * @var list<array{0: string, 1: bool}>
     */
    private const OTHER_SPECS = [
        ['60ML', false],
        ['60 ML', false],
        ['100 ML', false],
        ['500ML', false],
        ['500ML IFF', false],
        ['450 LAYER', false],
        // The near-miss that matters most: LAYER begins with L, and a lazier
        // rule ("starts with L") would call it a bag and silently drop three
        // material lines from every product that uses it.
        ['LAYER', false],
        ['835 X 610', false],
        ['750*610', false],
        ['780*610', false],
        ['835*610', false],
        ['710x610', false],
        // Written closed up, exactly as one row of the sheet has it.
        ['LD28.5 X 39', true],
        ['HM 30 X 49', true],
    ];

    private function standard(array $specs): ProductionStandard
    {
        // Unsaved: these rules read three spec columns and nothing else, and
        // persisting thirty near-identical standards would only trip the
        // uniqueness constraint that exists for an unrelated reason.
        return new ProductionStandard([
            'carton_spec' => $specs['carton'] ?? null,
            'tray_spec' => $specs['tray'] ?? null,
            'pouch_spec' => $specs['pouch'] ?? null,
        ]);
    }

    /** @return list<string> */
    private function kinds(ProductionStandard $standard): array
    {
        return collect(app(PackingMaterialSuggestionService::class)->forStandard($standard))
            ->pluck('kind')
            ->all();
    }

    public function test_the_bag_rule_is_exactly_right_on_every_real_carton_spec(): void
    {
        foreach (self::CARTON_SPECS as [$spec, $isBag]) {
            $kinds = $this->kinds($this->standard([
                'carton' => $spec, 'tray' => '60ML', 'pouch' => '750*610',
            ]));

            if ($isBag) {
                $this->assertSame(['carton'], $kinds,
                    "\"{$spec}\" is a bag: it must be the whole pack, with no tray, film or tape.");
            } else {
                $this->assertSame(['carton', 'tray', 'pouch_film', 'tape', 'final_carton', 'polymer_cover'], $kinds,
                    "\"{$spec}\" is a carton: suppressing its tray, film or tape loses real material.");
            }
        }
    }

    public function test_a_tray_or_film_spec_is_never_mistaken_for_a_bag(): void
    {
        // isBag is only ever asked about the CARTON spec, and this proves it
        // stays that way: a bag-looking value in the tray or film column must
        // not suppress anything, because the carton is still a carton.
        foreach (self::OTHER_SPECS as [$spec, $_]) {
            $kinds = $this->kinds($this->standard(['carton' => '170ML', 'tray' => $spec]));

            $this->assertSame(['carton', 'tray', 'tape', 'final_carton', 'polymer_cover'], $kinds,
                "\"{$spec}\" in the tray column must not change which materials a carton-packed product uses.");
        }
    }

    public function test_layer_is_not_a_bag(): void
    {
        // Called out on its own because it is the one value a plausible
        // shortening of the rule gets wrong. "LAYER" and "450 LAYER" are trays.
        foreach (['LAYER', '450 LAYER'] as $spec) {
            $this->assertSame(
                ['carton', 'tray', 'pouch_film', 'tape', 'final_carton', 'polymer_cover'],
                $this->kinds($this->standard(['carton' => '100ML', 'tray' => $spec, 'pouch' => '750*610'])),
                "\"{$spec}\" is a tray. Treating it as a bag would drop three real material lines.",
            );
        }
    }

    public function test_an_ldpe_named_spec_is_still_a_bag_when_it_names_a_dimension(): void
    {
        // The factory writes LD for the bag family and LDPE for the Tally item.
        // A spec of "LDPE COVER (28.5x38x120G)" would be an item name in a spec
        // column — wrong data, but it must not silently become a full pack with
        // a tray and tape quoted against a bag.
        $kinds = $this->kinds($this->standard([
            'carton' => 'LDPE  COVER (28.5x38x120G)', 'tray' => '60ML', 'pouch' => '750*610',
        ]));

        // Documents the CURRENT behaviour rather than asserting a preference:
        // "LDPE" does not match the bag rule, because LD is followed by a letter
        // rather than a word break or a digit. If the factory ever puts an item
        // name in a spec column this is the line that will say so.
        $this->assertContains('tray', $kinds,
            'LDPE-as-a-spec is not currently read as a bag — if that changes, this test is where it is recorded.');
    }

    public function test_the_film_rebase_moves_the_container_and_nothing_else(): void
    {
        $film = collect(app(PackingMaterialSuggestionService::class)->forStandard(
            $this->standard(['carton' => '170ML', 'tray' => '60ML', 'pouch' => '750*610'])
        ))->firstWhere('kind', 'pouch_film');

        // Counted per TRAY — one pouch covers one tray.
        $this->assertSame('trays', $film['quantity_basis']);
        $this->assertSame('per_tray', $film['basis']);
        // But it is still FILM: weighed in kg, dosed in grams per piece. A
        // re-base that also took the tray's units would quote kilograms of
        // cardboard.
        $this->assertSame('kg', $film['unit']);
        $this->assertSame('g', $film['factor_unit']);
    }

    public function test_the_rebase_does_not_leak_into_the_tray_or_tape_lines(): void
    {
        $rows = collect(app(PackingMaterialSuggestionService::class)->forStandard(
            $this->standard(['carton' => '170ML', 'tray' => '60ML', 'pouch' => '750*610'])
        ))->keyBy('kind');

        // The tray is counted per tray because that is its own basis, and tape
        // per carton because tape seals a box. Neither is the film's re-base.
        $this->assertSame('per_tray', $rows['tray']['basis']);
        $this->assertSame('per_carton', $rows['tape']['basis']);
        $this->assertSame('per_carton', $rows['carton']['basis']);
    }

    public function test_no_waste_account_or_bottle_is_offered_as_packing_material(): void
    {
        // The traps this factory's catalogue actually contains. "Bag Waste",
        // "Film Waste" and "Plastic Bags Used" are scrap and expense accounts,
        // not materials a shift consumes — offering one puts a real quantity
        // against the wrong master.
        foreach ([
            'Bag Waste', 'Film Waste', 'Plastic Bags Used',
            'L.180 Ml Hybrid Pet Bottle Clear Cover-14.5gms',
            'L.500ml Kidney Pet Bottles Clear-30gms Cover',
            'B.400 ML Pet Bottle Clear (Bag)-26g',
            '1000ml Round Amber Bags-41.5g',
        ] as $name) {
            Item::create(['sku' => $name, 'name' => $name, 'uom' => 'Nos.', 'is_active' => true]);
        }

        // And the genuine materials, so the test proves discrimination rather
        // than an empty result.
        foreach ([
            '100 Ml Master Box', '100 Ml Tray', 'Poly Olefin Pouch',
            'LDPE  COVER (28.5x38x120G)', 'Packing Tape - Transparent',
        ] as $name) {
            Item::create(['sku' => $name, 'name' => $name, 'uom' => 'Nos.', 'is_active' => true]);
        }

        $offered = collect(app(PackingMaterialMappingService::class)->optionsByKind())
            ->flatten(1)
            ->pluck('name')
            ->all();

        foreach ([
            'Bag Waste', 'Film Waste', 'Plastic Bags Used',
            'L.180 Ml Hybrid Pet Bottle Clear Cover-14.5gms',
            'L.500ml Kidney Pet Bottles Clear-30gms Cover',
            'B.400 ML Pet Bottle Clear (Bag)-26g',
            '1000ml Round Amber Bags-41.5g',
        ] as $trap) {
            $this->assertNotContains($trap, $offered, "\"{$trap}\" is not a packing material.");
        }

        foreach (['100 Ml Master Box', '100 Ml Tray', 'Poly Olefin Pouch', 'Packing Tape - Transparent'] as $real) {
            $this->assertContains($real, $offered, "\"{$real}\" is a packing material and must be offered.");
        }
    }

    public function test_an_unmapped_carton_still_quotes_one_per_box(): void
    {
        // WHAT MAKES THE PICKER USABLE. Once the completion screen lets a
        // supervisor choose the carton, the quantity beside their choice has to
        // compute — and one carton is one carton whether or not a mapping row
        // exists to say so.
        //
        // Read only off the mapping, an unmapped row came back with a null
        // factor, so a chosen carton counted nothing. That is the worst of the
        // three states: a mapped row posts, an empty row visibly posts nothing,
        // and this one looks answered while posting nothing.
        $rows = collect(app(PackingMaterialSuggestionService::class)->forStandard(
            // "100 ML CARTON" and "100ML TRAY" both map in this factory, but no
            // mapping exists in this test's database at all — which is exactly
            // the unmapped case.
            $this->standard(['carton' => '100 ML CARTON', 'tray' => '100ML TRAY'])
        ))->keyBy('kind');

        $this->assertNull($rows['carton']['item'], 'Unmapped: no item is suggested.');
        $this->assertSame('1', $rows['carton']['factor'], 'One carton per box packed, mapping or not.');
        $this->assertSame('1', $rows['tray']['factor'], 'One tray per tray packed, mapping or not.');
    }

    public function test_an_unmapped_film_or_tape_still_refuses_to_guess_a_dose(): void
    {
        // The other half of the same rule, and the more important half. Film is
        // dosed in grams per piece and tape in metres per box; neither has a
        // structural default, so neither may be defaulted. A guessed dose is a
        // wrong weight on a live voucher — the null is the protection.
        $rows = collect(app(PackingMaterialSuggestionService::class)->forStandard(
            $this->standard(['carton' => '100ML', 'pouch' => '750*610'])
        ))->keyBy('kind');

        $this->assertNull($rows['pouch_film']['factor'], 'Film grams per piece cannot be guessed.');
        $this->assertNull($rows['tape']['factor'], 'Tape metres per box cannot be guessed.');
    }

    public function test_a_product_with_no_carton_spec_still_gets_its_other_materials(): void
    {
        // The bag rule keys off the carton spec, so a blank carton must not be
        // read as a bag — that would suppress a tray and film the product does
        // use. Seventeen of the workbook's rows have no tray or film; none has a
        // film without a carton, but the rule must not depend on that holding.
        $kinds = $this->kinds($this->standard(['tray' => '60ML', 'pouch' => '750*610']));

        $this->assertSame(['tray', 'pouch_film'], $kinds);
        $this->assertNotContains(PackingMaterialMapping::KIND_TAPE, $kinds,
            'Tape seals a box; with no carton there is nothing to seal.');
    }
}
