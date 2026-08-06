<?php

namespace Tests\Feature\Production;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\PackingMaterialMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How much a pouch weighs, from the factory's own counted figures.
 *
 * The floor weighed a kilogram of each size and counted what was in it (photo,
 * 06-Aug). That count is the measurement. The "per pouch" column beside it on the
 * same sheet is derived and rounded to two decimals, and using it would be a real
 * error on a line that posts to Tally:
 *
 *   750 x 610 is 71 pouches to the kilogram, so one is 0.0141 kg.
 *   Rounded to 0.01, a thousand pouches read 10 kg instead of 14.08.
 *
 * These tests exist because that rounding looks harmless on the sheet and is 29%
 * wrong by the time it reaches a voucher.
 */
class PouchAndCoverDosesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, Item> */
    private function catalogue(): array
    {
        $items = [];

        // Named exactly as their Tally spells them — DOUBLE spaces included,
        // which is the fragility the command's name matching has to survive.
        foreach ([
            'Poly Olefin Pouch',
            'Hm Polythene Bags -  30.5 x 49 x 200G',
            'Hm Polythene Bags -  30 x 49 x 200G',
            'LDPE  COVER (28.5x38x120G)',
            'LDPE  COVER (30x49x120G)',
        ] as $name) {
            $items[$name] = Item::create([
                'sku' => $name, 'name' => $name, 'uom' => 'Kgs.', 'is_active' => true,
            ]);
        }

        return $items;
    }

    private function seedDoses(bool $write = true): void
    {
        $this->artisan('production:seed-pouch-doses', $write ? ['--write' => true] : [])
            ->assertSuccessful();
    }

    private function dose(string $kind, string $spec): ?string
    {
        $row = PackingMaterialMapping::query()
            ->where('spec_kind', $kind)
            ->where('spec_value', $spec)
            ->first();

        return $row === null ? null : (string) $row->grams_per_piece;
    }

    public function test_a_pouch_weight_comes_from_the_count_not_the_rounded_column(): void
    {
        $this->catalogue();
        $this->seedDoses();

        // 1000 / 71 = 14.0845 g. The sheet's own rounded figure would be 10 g.
        $this->assertSame(0, bccomp((string) $this->dose('pouch_film', '750*610'), '14.0845', 4));
        $this->assertSame(0, bccomp((string) $this->dose('pouch_film', '780*610'), '16.9492', 4));
        $this->assertSame(0, bccomp((string) $this->dose('pouch_film', '835*610'), '17.8571', 4));
    }

    public function test_a_thousand_pouches_weigh_what_the_factory_counted(): void
    {
        // The arithmetic that matters, stated as the floor would check it: if a
        // kilogram holds 71 pouches, a thousand pouches are a thousand
        // seventy-firsts of a kilogram.
        $this->catalogue();
        $this->seedDoses();

        $kgPerThousand = bcdiv(bcmul((string) $this->dose('pouch_film', '750*610'), '1000', 4), '1000', 4);

        $this->assertSame(0, bccomp($kgPerThousand, '14.0845', 4));
        // And emphatically not the 10 kg the rounded column implies.
        $this->assertSame(1, bccomp($kgPerThousand, '13.0000', 4), 'The rounded 0.01 kg figure has crept back in.');
    }

    public function test_every_spelling_of_one_size_gets_the_same_dose(): void
    {
        // The workbook writes one size four ways across its 103 rows, and the
        // mapping is keyed on the literal string — so a spelling without a row is
        // a product with no pouch figure at all.
        $this->catalogue();
        $this->seedDoses();

        foreach (['750*610', '750 X 610', '750X 610', '750 x 610'] as $spelling) {
            $this->assertSame(
                0,
                bccomp((string) $this->dose('pouch_film', $spelling), '14.0845', 4),
                "\"{$spelling}\" is the same pouch and must carry the same weight.",
            );
        }
    }

    public function test_the_covers_take_their_counted_weights(): void
    {
        $this->catalogue();
        $this->seedDoses();

        // 1 kg = 11 covers is 90.9091 g each — a cover is two orders of magnitude
        // heavier than a pouch, which is why they are separate figures and not one
        // "packing film" number.
        $this->assertSame(0, bccomp((string) $this->dose('carton', 'HM 30.5*49'), '90.9091', 4));
        $this->assertSame(0, bccomp((string) $this->dose('carton', 'LD 30 X 49'), '50.0000', 4));
    }

    public function test_both_spellings_of_the_28_point_5_cover_are_mapped(): void
    {
        // The dose sheet writes 28.5 x 39 and the workbook writes 28.5 x 38. The
        // owner confirmed 38 — and both spellings appear in live standards, so
        // both are mapped to the one cover rather than one of them being left to
        // find no figure.
        $this->catalogue();
        $this->seedDoses();

        foreach (['LD 28.5 X 38', 'LD28.5 X 39'] as $spelling) {
            $this->assertSame(0, bccomp((string) $this->dose('carton', $spelling), '40.0000', 4));
        }
    }

    public function test_a_double_space_in_the_tally_name_does_not_defeat_the_match(): void
    {
        // Their catalogue really is spelled "LDPE  COVER" and "Hm Polythene Bags -
        // 30.5". A single-space transcription anywhere would silently leave a whole
        // size unset — no error, no row, and a blank pouch line on the floor.
        $items = $this->catalogue();
        $this->seedDoses();

        $row = PackingMaterialMapping::query()->where('spec_value', 'LD 30 X 49')->sole();

        $this->assertSame($items['LDPE  COVER (30x49x120G)']->id, $row->item_id);
    }

    public function test_the_unanswered_size_is_left_unset(): void
    {
        // 710 x 610 is in live standards and not on the dose sheet. It sits one
        // interpolation away from 750 and 780, which is exactly why the guess is
        // not made: a film weight multiplies by every pouch in a shift.
        $this->catalogue();
        $this->seedDoses();

        $this->assertNull($this->dose('pouch_film', '710x610'));
    }

    public function test_the_uncounted_hm_bag_gets_no_dose_at_all(): void
    {
        // The dose sheet has four cover rows and none of them is HM 30 x 49. An
        // earlier version of this command gave it the LD 30x49 count of 20,
        // because the two are the same width and height — but the HM bags are
        // 200 gauge and the LDPE covers 120, and the sheet's own measurements say
        // what that means: HM 30.5x49 is 90.9 g where LD 30x49 is 50 g.
        //
        // So a dimension is not a weight, and this size must arrive on the floor
        // as blank rather than as 50 g.
        $this->catalogue();
        $this->seedDoses();

        $this->assertNull($this->dose('carton', 'HM 30 X 49'));
        $this->assertNull($this->dose('carton', 'HM 30*49'));
    }

    public function test_the_wrong_figure_already_seeded_is_withdrawn(): void
    {
        // It went live. A command that only stops writing a bad number leaves the
        // bad number in the table, prefilled onto a packing line and posted to
        // Tally — so it takes its own output back.
        $items = $this->catalogue();

        foreach (['HM 30 X 49', 'HM 30*49'] as $spec) {
            PackingMaterialMapping::query()->create([
                'spec_kind' => 'carton',
                'spec_value' => $spec,
                'item_id' => $items['Hm Polythene Bags -  30 x 49 x 200G']->id,
                'grams_per_piece' => '50.0000',
                'note' => 'Factory count, 06-Aug: 1 kg = 20 nos.',
            ]);
        }

        $this->seedDoses();

        $this->assertNull($this->dose('carton', 'HM 30 X 49'));
        $this->assertNull($this->dose('carton', 'HM 30*49'));

        // HARD deleted, not trashed: the "already answered" check reads
        // withTrashed, so a trashed row would block the factory's real figure
        // from ever being seeded.
        $this->assertSame(
            0,
            PackingMaterialMapping::query()->withTrashed()->where('spec_value', 'HM 30 X 49')->count(),
            'A withdrawn dose left in the trash would silently block the real answer.',
        );
    }

    public function test_withdrawal_only_touches_this_commands_own_untouched_row(): void
    {
        // The narrow predicate is the whole safety of a delete against live master
        // data: same spec, but a figure a person set or edited is theirs.
        $items = $this->catalogue();

        PackingMaterialMapping::query()->create([
            'spec_kind' => 'carton',
            'spec_value' => 'HM 30 X 49',
            'item_id' => $items['Hm Polythene Bags -  30 x 49 x 200G']->id,
            'grams_per_piece' => '88.0000',
            'note' => 'Weighed on the floor, 1 kg = 11 bags.',
        ]);

        $this->seedDoses();

        $this->assertSame(0, bccomp((string) $this->dose('carton', 'HM 30 X 49'), '88.0000', 4));
    }

    public function test_a_dry_run_withdraws_nothing(): void
    {
        $items = $this->catalogue();

        PackingMaterialMapping::query()->create([
            'spec_kind' => 'carton',
            'spec_value' => 'HM 30 X 49',
            'item_id' => $items['Hm Polythene Bags -  30 x 49 x 200G']->id,
            'grams_per_piece' => '50.0000',
            'note' => 'Factory count, 06-Aug: 1 kg = 20 nos.',
        ]);

        $this->seedDoses(write: false);

        $this->assertSame(0, bccomp((string) $this->dose('carton', 'HM 30 X 49'), '50.0000', 4));
    }

    public function test_a_figure_a_person_already_set_is_never_overwritten(): void
    {
        $items = $this->catalogue();

        PackingMaterialMapping::query()->create([
            'spec_kind' => 'pouch_film',
            'spec_value' => '750*610',
            'item_id' => $items['Poly Olefin Pouch']->id,
            'grams_per_piece' => '13.5000',
            'note' => 'Weighed on the floor by the supervisor.',
        ]);

        $this->seedDoses();

        // A measured figure typed by the floor outranks one seeded from a
        // photograph of a handwritten sheet.
        $this->assertSame(0, bccomp((string) $this->dose('pouch_film', '750*610'), '13.5000', 4));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->catalogue();
        $this->seedDoses(write: false);

        $this->assertSame(0, PackingMaterialMapping::query()->count());
    }

    public function test_a_missing_item_leaves_its_sizes_unset_rather_than_failing(): void
    {
        // No catalogue at all — a fresh instance before the Tally masters land.
        // The command must report and exit cleanly, not abort a deploy.
        $this->seedDoses();

        $this->assertSame(0, PackingMaterialMapping::query()->count());
    }
}
