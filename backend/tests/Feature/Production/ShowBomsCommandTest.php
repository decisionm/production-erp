<?php

namespace Tests\Feature\Production;

use App\Console\Commands\ShowBoms;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `boms:show` is pointed at the LIVE database by a manual workflow, so the
 * thing worth pinning is not its formatting but its two load-bearing claims:
 * that it reports the SAME kg-per-unit the shift comparison would use, and
 * that it names an active BOM which silently supplies no norm at all.
 *
 * A report that disagrees with the code it describes is worse than no report,
 * which is why the norm here is asserted against a hand-computed figure
 * rather than against whatever the command happens to print.
 */
class ShowBomsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $name, string $uom, string $sku): Item
    {
        return Item::query()->create([
            'sku' => $sku,
            'name' => $name,
            'uom' => $uom,
            'item_type' => 'raw_material',
            'is_active' => true,
        ]);
    }

    public function test_it_says_so_plainly_when_there_are_no_boms(): void
    {
        $this->artisan('boms:show')
            ->expectsOutputToContain('No Bills of Material in this database.')
            ->expectsOutputToContain('VERDICT: 0 BOMs.')
            ->assertSuccessful();
    }

    public function test_it_sums_only_the_kg_lines_into_the_norm(): void
    {
        $product = $this->item('200ML ROUND', 'Nos', 'FG-200-ROUND');
        $resin = $this->item('Relpet', 'Kgs', 'RM-RESIN');
        $cap = $this->item('Cap 28mm', 'Nos', 'RM-CAP');

        $bom = Bom::query()->create([
            'item_id' => $product->id,
            'name' => 'Standard',
            'version' => '1',
            'is_active' => true,
        ]);

        // 0.0129 kg of resin + one cap. Only the resin is a mass line, so the
        // norm must be 0.0129 exactly — the cap must not leak into it.
        BomLine::query()->create([
            'bom_id' => $bom->id,
            'component_item_id' => $resin->id,
            'quantity_per' => '0.0129',
        ]);
        BomLine::query()->create([
            'bom_id' => $bom->id,
            'component_item_id' => $cap->id,
            'quantity_per' => '1',
        ]);

        $this->artisan('boms:show')
            ->expectsOutputToContain('kg/unit = 0.0129')
            ->expectsOutputToContain('(counts toward the norm)')
            ->doesntExpectOutputToContain(ShowBoms::NO_NORM_FLAG)
            ->assertSuccessful();
    }

    public function test_it_flags_an_active_bom_that_provides_no_weight_norm(): void
    {
        $product = $this->item('500ML KIDNEY', 'Nos', 'FG-500-KIDNEY');
        $carton = $this->item('Master Box', 'Nos', 'RM-BOX');

        $bom = Bom::query()->create([
            'item_id' => $product->id,
            'name' => 'Packing only',
            'version' => '1',
            'is_active' => true,
        ]);

        BomLine::query()->create([
            'bom_id' => $bom->id,
            'component_item_id' => $carton->id,
            'quantity_per' => '1',
        ]);

        $this->artisan('boms:show')
            ->expectsOutputToContain(ShowBoms::NO_NORM_FLAG)
            ->expectsOutputToContain('kg/unit = (none)')
            ->assertSuccessful();
    }

    /**
     * An INACTIVE BOM is not the shift comparison's business — only the
     * active one supplies the norm — so a kg-less inactive row must not be
     * flagged as a missing norm. Otherwise every retired recipe reads as a
     * live gap and the flag stops meaning anything.
     */
    public function test_an_inactive_bom_without_kg_lines_is_not_flagged(): void
    {
        $product = $this->item('750ML KIDNEY', 'Nos', 'FG-750-KIDNEY');
        $carton = $this->item('Old Box', 'Nos', 'RM-OLDBOX');

        $bom = Bom::query()->create([
            'item_id' => $product->id,
            'name' => 'Retired',
            'version' => '1',
            'is_active' => false,
        ]);

        BomLine::query()->create([
            'bom_id' => $bom->id,
            'component_item_id' => $carton->id,
            'quantity_per' => '1',
        ]);

        $this->artisan('boms:show')
            ->expectsOutputToContain('inactive')
            ->doesntExpectOutputToContain(ShowBoms::NO_NORM_FLAG)
            ->assertSuccessful();
    }

    /**
     * THE UOM SPELLINGS THE LIVE NORM ACTUALLY COUNTS, pinned one by one.
     *
     * This is the trap this command was nearly built on. When it was written
     * "is this kilograms" had two disagreeing answers, and the report had to
     * mirror the SERVICES' one — the answer that defines the norm — rather
     * than Item::hasKgUom(), whose literal set then omitted
     * "kilogram"/"kilograms". Tally masters write "Kgs." with a dot on 90+
     * live items, so a report built on the wrong predicate would print "no
     * norm" for BOMs that have one, on live, in the rows that matter most.
     *
     * That divergence is gone: Item::isKgUom() IS the services' answer now,
     * and this command calls it directly. The spellings stay pinned HERE
     * anyway — they are the contract the report depends on, so narrowing the
     * predicate must break the report's own test, not only the model's.
     */
    #[DataProvider('massUomSpellings')]
    public function test_it_counts_every_uom_spelling_the_live_norm_counts(string $uom): void
    {
        $product = $this->item("Product {$uom}", 'Nos', 'FG-'.md5($uom));
        $resin = $this->item("Resin {$uom}", $uom, 'RM-'.md5($uom));

        $bom = Bom::query()->create([
            'item_id' => $product->id,
            'name' => 'Standard',
            'version' => '1',
            'is_active' => true,
        ]);
        BomLine::query()->create([
            'bom_id' => $bom->id,
            'component_item_id' => $resin->id,
            'quantity_per' => '0.0129',
        ]);

        $this->artisan('boms:show')
            ->expectsOutputToContain('kg/unit = 0.0129')
            ->doesntExpectOutputToContain(ShowBoms::NO_NORM_FLAG)
            ->assertSuccessful();
    }

    /** @return array<string, array{string}> */
    public static function massUomSpellings(): array
    {
        return [
            'kg' => ['Kg'],
            'kg with the Tally trailing dot' => ['Kg.'],
            'kgs' => ['Kgs'],
            'kgs with the Tally trailing dot' => ['Kgs.'],
            // The two the old hasKgUom() would have missed, before unification.
            'kilogram' => ['Kilogram'],
            'kilograms' => ['Kilograms'],
        ];
    }

    /**
     * The command writes nothing. Pinned because it is aimed at the LIVE
     * database by a workflow that carries no dry run and no confirmation
     * gate — the justification for having neither is exactly this property.
     */
    public function test_it_writes_nothing(): void
    {
        $product = $this->item('100ML ROUND', 'Nos', 'FG-100-ROUND');
        $resin = $this->item('Relpet 100', 'Kgs', 'RM-RESIN-100');

        $bom = Bom::query()->create([
            'item_id' => $product->id,
            'name' => 'Standard',
            'version' => '1',
            'is_active' => true,
        ]);
        BomLine::query()->create([
            'bom_id' => $bom->id,
            'component_item_id' => $resin->id,
            'quantity_per' => '0.0100',
        ]);

        $before = [
            'boms' => Bom::query()->count(),
            'lines' => BomLine::query()->count(),
            'items' => Item::query()->count(),
            'bom_updated' => (string) Bom::query()->find($bom->id)?->updated_at,
        ];

        $this->artisan('boms:show')->assertSuccessful();

        $this->assertSame($before, [
            'boms' => Bom::query()->count(),
            'lines' => BomLine::query()->count(),
            'items' => Item::query()->count(),
            'bom_updated' => (string) Bom::query()->find($bom->id)?->updated_at,
        ], 'boms:show must be read-only — the live workflow has no dry run because of it.');
    }
}
