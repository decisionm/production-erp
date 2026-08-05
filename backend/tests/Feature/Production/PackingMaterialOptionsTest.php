<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The lists the completion screen's dropdowns are built from.
 *
 * A packing line the app cannot resolve used to print a sentence telling a
 * supervisor to go and administer master data. The owner's verdict (05-Aug):
 * "why so many English notes, will they really read them." A line that cannot be
 * resolved needs a PICKER — and the picker needs a list.
 *
 * It serves the case the sentence never addressed at all: the 100 ml cartons ran
 * out, so today this product goes in a 90 ml box. Not a data error to correct
 * later — Tuesday.
 */
class PackingMaterialOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This factory's real packing catalogue, spelled as their Tally spells
        // it, plus the traps: bottles whose names end in "Cover", and a retired
        // item.
        foreach ([
            ['100 Ml Master Box', 'Nos.', true],
            ['300ml Emcure Master Carton', 'Nos.', true],
            ['100 Ml Tray', 'Nos.', true],
            ['500 Ml PAD', 'Nos.', true],
            ['450 LAYER', 'Nos.', true],
            ['LDPE  COVER (28.5x38x120G)', 'Nos.', true],
            ['Hm Polythene Bags -  30.5 x 49 x 200G', 'Nos.', true],
            ['Poly Olefin Pouch', 'Kgs.', true],
            ['Shrink Film Poly Olefin', 'Kgs.', true],
            ['Stretch Film', 'Kgs.', true],
            ['Packing Tape - Transparent', 'Nos.', true],
            ['Packing Tape Green', 'Nos.', true],
            // THE TRAP: a BOTTLE, not a film. Their catalogue is full of these.
            ['L.180 Ml Hybrid Pet Bottle Clear Cover-14.5gms', 'Nos.', true],
            ['L.500ml Kidney Pet Bottles Clear-30gms Cover', 'Nos.', true],
            // Retired — deactivated foreign data must not come back as a choice.
            ['Corrugated Carton Master Box', 'Nos.', false],
        ] as [$name, $uom, $active]) {
            Item::create(['sku' => $name, 'name' => $name, 'uom' => $uom, 'is_active' => $active]);
        }

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        $this->actingAs($user);
    }

    /** @return array<string, list<string>> */
    private function names(): array
    {
        $data = $this->getJson('/api/v1/production/packing-material-options')
            ->assertOk()
            ->json('data');

        return collect($data)->map(fn ($rows) => collect($rows)->pluck('name')->all())->all();
    }

    public function test_each_kind_offers_the_items_it_could_actually_be(): void
    {
        $o = $this->names();

        $this->assertSame(['100 Ml Master Box', '300ml Emcure Master Carton'], $o['carton']);
        $this->assertSame(['100 Ml Tray', '450 LAYER', '500 Ml PAD'], $o['tray']);
        $this->assertSame(['Packing Tape - Transparent', 'Packing Tape Green'], $o['tape']);
    }

    public function test_the_film_list_holds_the_film_the_factory_really_consumes(): void
    {
        // `Poly Olefin Pouch` is it — 233 kg on one real Stock Journal, at
        // Rs 296/kg. The matcher's own film words never matched it, which is why
        // this list is built separately.
        $film = $this->names()['pouch_film'];

        $this->assertContains('Poly Olefin Pouch', $film);
        $this->assertContains('LDPE  COVER (28.5x38x120G)', $film);
        $this->assertContains('Hm Polythene Bags -  30.5 x 49 x 200G', $film);
        $this->assertContains('Shrink Film Poly Olefin', $film);
        $this->assertContains('Stretch Film', $film);
    }

    public function test_a_bottle_named_cover_is_not_offered_as_a_film(): void
    {
        // The reason "cover" is not a word in this list. Their catalogue holds
        // dozens of bottles whose names end in Cover, and a dropdown with thirty
        // bottles in it is the same failure as no dropdown at all.
        $film = $this->names()['pouch_film'];

        $this->assertNotContains('L.180 Ml Hybrid Pet Bottle Clear Cover-14.5gms', $film);
        $this->assertNotContains('L.500ml Kidney Pet Bottles Clear-30gms Cover', $film);
    }

    public function test_a_retired_item_is_never_offered(): void
    {
        // Twenty items belonging to another company were deactivated on 5 August
        // precisely so the floor could not pick them. A dropdown that offers them
        // again undoes that in one afternoon.
        foreach ($this->names() as $names) {
            $this->assertNotContains('Corrugated Carton Master Box', $names);
        }
    }

    public function test_the_lists_carry_the_unit_so_a_screen_can_label_its_column(): void
    {
        $rows = $this->getJson('/api/v1/production/packing-material-options')->json('data.pouch_film');

        $pouch = collect($rows)->firstWhere('name', 'Poly Olefin Pouch');
        $this->assertSame('Kgs.', $pouch['uom'], 'Film is weighed; the screen has to be able to say so.');

        $box = collect($this->getJson('/api/v1/production/packing-material-options')->json('data.carton'))
            ->firstWhere('name', '100 Ml Master Box');
        $this->assertSame('Nos.', $box['uom'], 'Cartons are counted.');
    }
}
