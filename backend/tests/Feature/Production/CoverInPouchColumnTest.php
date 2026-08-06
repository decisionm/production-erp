<?php

namespace Tests\Feature\Production;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Services\PackingMaterialSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A COVER in the pouch column is not a pouch, and must not be counted like one.
 *
 * The workbook's pouch column holds two different things, and the owner drew the
 * distinction himself (06-Aug): "if it is pocu 750*610, 835*610 and 780*610 ... if
 * it is single packaging conver like HM and Ld, we have the calcuatio".
 *
 * Verified against the product sheet — all 103 rows:
 *
 *   67 rows name a poly-olefin POUCH (750*610, 780*610, 835*610).
 *    6 rows name an HM or LD COVER: 400ML ROUND, 90ML RIB, 750ML KIDNEY,
 *      500ML KIDNEY LONG NECK, 500ML ROUND / IFF, 450ML RIBBED.
 *
 * They are counted differently. A pouch goes over a TRAY — five trays, five
 * pouches. A cover holds a stated number of BOTTLES, which the sheet gives in its
 * nos_per_pouch column: 145, 110, 161, 83, 120.
 *
 * Getting this wrong is not a rounding difference. 90ML RIB packs ten trays to a
 * box, so a per-tray cover books TEN covers where the box takes about one.
 */
class CoverInPouchColumnTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create([
            'sku' => 'BOTTLE-400', 'name' => '400ML ROUND', 'uom' => 'Nos', 'is_active' => true,
        ]);

        foreach ([
            'LDPE  COVER (30x49x120G)',
            'LDPE  COVER (28.5x38x120G)',
            'Poly Olefin Pouch',
            '170 Ml Master Box',
            '60 Ml Tray',
        ] as $name) {
            Item::create(['sku' => $name, 'name' => $name, 'uom' => 'Kgs.', 'is_active' => true]);
        }
    }

    private function standard(?string $carton, ?string $tray, ?string $pouch): ProductionStandard
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
            $out[$entry['kind'].':'.$entry['spec']] = $entry;
        }

        return $out;
    }

    private function seedDoses(): void
    {
        $this->artisan('production:seed-pouch-doses', ['--write' => true])->assertSuccessful();
    }

    public function test_a_cover_in_the_pouch_column_is_counted_per_cover_not_per_tray(): void
    {
        // 400ML ROUND as the sheet has it: a 170ML box, a 60ML tray, and an
        // LD 30 x 49 COVER in the pouch column.
        $lines = $this->lines($this->standard('170ML ROUND', '60ML', 'LD 30 X 49'));

        $cover = $lines['pouch_film:LD 30 X 49'];

        $this->assertSame('per_pouch', $cover['basis'], 'A cover is counted per cover, not per tray.');
        $this->assertSame('covers', $cover['quantity_basis']);
        $this->assertSame('Cover', $cover['label'], 'The row must not call a cover a pouch.');
    }

    public function test_a_real_pouch_is_still_counted_per_tray(): void
    {
        // The owner's own arithmetic, unchanged: "five trays, five pouches".
        $lines = $this->lines($this->standard('170ML ROUND', '60ML', '750*610'));

        $pouch = $lines['pouch_film:750*610'];

        $this->assertSame('per_tray', $pouch['basis']);
        $this->assertSame('trays', $pouch['quantity_basis']);
        // No override — an ordinary pouch row keeps the frontend's default word.
        $this->assertNull($pouch['label']);
    }

    public function test_the_cover_carries_the_weight_the_factory_counted(): void
    {
        // Seeded under the CARTON kind only, this row found no mapping and
        // arrived on the floor with no weight and no kilograms — a cover line
        // that consumed nothing. An LD 30 x 49 weighs the same whichever column
        // names it.
        $this->seedDoses();

        $cover = $this->lines($this->standard('170ML ROUND', '60ML', 'LD 30 X 49'))['pouch_film:LD 30 X 49'];

        $this->assertNotNull($cover['item'], 'The cover must resolve to a Tally item.');
        $this->assertSame('LDPE  COVER (30x49x120G)', $cover['item']['name']);
        // 1 kg = 20 covers, so 50 g each.
        $this->assertSame(0, bccomp((string) $cover['factor'], '50.0000', 4));
        $this->assertSame('g', $cover['factor_unit']);
        $this->assertSame('kg', $cover['unit']);
        $this->assertTrue($cover['submit_as_stock']);
    }

    public function test_the_kilograms_are_what_the_sheet_implies(): void
    {
        // The arithmetic a person can check, end to end, on the sheet's own
        // figures for 400ML ROUND:
        //
        //   a box is 60 bottles a tray x 4 trays          = 240 bottles
        //   a cover holds 145 bottles                     = 1.66 covers a box
        //   ...so 1,450 bottles is 10 covers
        //   an LD 30 x 49 is 1 kg / 20                    = 50 g
        //   10 covers x 50 g                              = 0.5 kg
        $this->seedDoses();

        $cover = $this->lines($this->standard('170ML ROUND', '60ML', 'LD 30 X 49'))['pouch_film:LD 30 X 49'];

        $covers = 10;
        $kg = bcdiv(bcmul((string) $covers, (string) $cover['factor'], 8), '1000', 4);

        $this->assertSame(0, bccomp($kg, '0.5000', 4));
    }

    public function test_a_cover_never_multiplies_by_the_tray_count(): void
    {
        // 90ML RIB: ten trays to a box. Per tray this line would book ten covers
        // for a box that takes about one — a tenfold over-issue, posted.
        $this->seedDoses();

        $cover = $this->lines($this->standard('500ML ROUND', '500ML ROUND', 'LD 28.5 X 38'))['pouch_film:LD 28.5 X 38'];

        $this->assertNotSame('per_tray', $cover['basis']);
        $this->assertNotSame('trays', $cover['quantity_basis']);
    }

    public function test_a_cover_with_no_box_or_tray_is_the_whole_pack_and_still_gets_a_line(): void
    {
        // 750ML KIDNEY and 500ML KIDNEY LONG NECK: nothing in the carton or tray
        // column, an HM 30 x 49 in the pouch column. The cover IS the pack.
        $lines = $this->lines($this->standard(null, null, 'HM 30 X 49'));

        $this->assertArrayHasKey('pouch_film:HM 30 X 49', $lines);
        $this->assertSame('Cover', $lines['pouch_film:HM 30 X 49']['label']);
        // No carton, so no tape line to seal a box that does not exist.
        $this->assertArrayNotHasKey('tape:HM 30 X 49', $lines);
    }

    public function test_the_uncounted_hm_cover_gets_a_line_with_no_weight(): void
    {
        // HM 30 x 49 is used by two products and was never weighed by the
        // factory — it is not on the dose sheet. So the row appears, names
        // itself, and carries no figure. Blank, never a guess: the fabricated
        // 50 g this size briefly carried was about 40% under.
        $this->seedDoses();

        $cover = $this->lines($this->standard(null, null, 'HM 30 X 49'))['pouch_film:HM 30 X 49'];

        $this->assertNull($cover['factor'], 'An unweighed cover must not arrive with a number.');
        $this->assertStringContainsString('choose the material', $cover['reason']);
        $this->assertStringContainsString('Cover', $cover['reason']);
    }

    public function test_the_bag_in_the_carton_column_still_ends_the_line_there(): void
    {
        // Unchanged and deliberately so: 17 rows pack straight into an HM or LD
        // bag and that bag is the whole pack — no tray, no pouch, no tape. This
        // is a different case from a cover in the POUCH column, which sits over a
        // real box.
        $lines = $this->lines($this->standard('HM 30.5*49', null, null));

        $this->assertCount(1, $lines);
        $this->assertArrayHasKey('carton:HM 30.5*49', $lines);
    }

    public function test_the_cover_dose_is_seeded_under_both_kinds(): void
    {
        $this->seedDoses();

        foreach ([PackingMaterialMapping::KIND_CARTON, PackingMaterialMapping::KIND_POUCH_FILM] as $kind) {
            $row = PackingMaterialMapping::query()
                ->where('spec_kind', $kind)
                ->where('spec_value', 'LD 30 X 49')
                ->first();

            $this->assertNotNull($row, "A cover must carry its weight under the {$kind} kind too.");
            $this->assertSame(0, bccomp((string) $row->grams_per_piece, '50.0000', 4));
        }
    }
}
