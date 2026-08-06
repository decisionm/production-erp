<?php

namespace Tests\Feature\Production;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\FactorySetting;
use App\Modules\Production\Services\RunMaterialSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saying which masterbatch a colour uses, once, as data.
 *
 * Their masters hold two ambers, four whites, two blacks, two greens and two
 * yellows, and resolveMasterbatchItem refuses to choose between them — correctly,
 * because a wrong pre-selection books the wrong material and looks checked. The
 * cost is that the floor answers the same question on every batch.
 *
 * The owner answered it (06-Aug): "Master Batch Amber is the standard", "for amber
 * amber is the corret one". Their own Tally journals agree — the 38 Stock Journals
 * consume `Master Batch Amber`.
 */
class MapMasterbatchColourTest extends TestCase
{
    use RefreshDatabase;

    private function masterbatches(): void
    {
        foreach (['Master Batch Amber' => 'Amber', 'Master Batch Pet Amber' => 'Amber'] as $name => $colour) {
            Item::create([
                'sku' => $name, 'name' => $name, 'uom' => 'Kgs.', 'colour' => $colour, 'is_active' => true,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function map(): array
    {
        $value = FactorySetting::query()
            ->where('key', RunMaterialSuggestionService::COLOUR_MAP_KEY)
            ->value('value');

        return $value === null ? [] : (json_decode((string) $value, true) ?? []);
    }

    public function test_it_maps_a_colour_to_the_named_masterbatch(): void
    {
        $this->masterbatches();
        $amber = Item::query()->where('name', 'Master Batch Amber')->sole();

        $this->artisan('production:map-masterbatch-colour', [
            '--colour' => 'Amber', '--item' => 'Master Batch Amber', '--write' => true,
        ])->assertSuccessful();

        $this->assertSame(['Amber' => $amber->id], $this->map());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->masterbatches();

        $this->artisan('production:map-masterbatch-colour', [
            '--colour' => 'Amber', '--item' => 'Master Batch Amber',
        ])->expectsOutputToContain('DRY RUN')->assertSuccessful();

        $this->assertSame([], $this->map());
    }

    public function test_it_merges_rather_than_replacing(): void
    {
        // Answering white next month must not silently drop the answer for amber.
        $this->masterbatches();
        Item::create(['sku' => 'W', 'name' => 'Master Batch - Pet White', 'uom' => 'Kgs.', 'colour' => 'White', 'is_active' => true]);

        $this->artisan('production:map-masterbatch-colour', [
            '--colour' => 'Amber', '--item' => 'Master Batch Amber', '--write' => true,
        ])->assertSuccessful();

        $this->artisan('production:map-masterbatch-colour', [
            '--colour' => 'White', '--item' => 'Master Batch - Pet White', '--write' => true,
        ])->assertSuccessful();

        $this->assertSame(['Amber', 'White'], array_keys($this->map()));
    }

    public function test_an_unknown_item_is_refused_rather_than_near_matched(): void
    {
        // Mapping a colour to the wrong colourant books the wrong material on
        // every voucher of that colour — the exact failure the ambiguity guard
        // exists to prevent. So a name that does not match exactly is refused.
        $this->masterbatches();

        $this->artisan('production:map-masterbatch-colour', [
            '--colour' => 'Amber', '--item' => 'Master Batch Ambr', '--write' => true,
        ])->assertFailed();

        $this->assertSame([], $this->map());
    }

    public function test_a_double_space_in_the_tally_name_still_matches(): void
    {
        // Their catalogue really is spelled "Master Batch - Pet  White" in places.
        Item::create(['sku' => 'W', 'name' => 'Master Batch -  Pet White', 'uom' => 'Kgs.', 'colour' => 'White', 'is_active' => true]);

        $this->artisan('production:map-masterbatch-colour', [
            '--colour' => 'White', '--item' => 'Master Batch - Pet White', '--write' => true,
        ])->assertSuccessful();

        $this->assertArrayHasKey('White', $this->map());
    }

    public function test_the_mapped_colour_then_resolves_without_ambiguity(): void
    {
        // The whole point: with the answer stored, the row stops asking.
        $this->masterbatches();
        $amber = Item::query()->where('name', 'Master Batch Amber')->sole();

        $bottle = Item::create([
            'sku' => 'B', 'name' => 'A.15ml Round Pet Bottle Amber-5gms', 'uom' => 'Nos',
            'colour' => 'Amber', 'nominal_weight_grams' => '5.0000', 'is_active' => true,
        ]);

        $before = app(RunMaterialSuggestionService::class)->masterbatchFor($bottle, 'Amber', 13333, '5.0000');
        $this->assertNull($before['item'], 'Two ambers must not resolve on their own.');
        $this->assertSame('2 Amber materials in the masters', $before['reason']);

        $this->artisan('production:map-masterbatch-colour', [
            '--colour' => 'Amber', '--item' => 'Master Batch Amber', '--write' => true,
        ])->assertSuccessful();

        $after = app(RunMaterialSuggestionService::class)->masterbatchFor($bottle, 'Amber', 13333, '5.0000');

        $this->assertSame($amber->id, $after['item']['id']);
        $this->assertSame('factory_map', $after['source']);
        // 2.5% of a 5 g bottle, and the sentence no longer asks anything.
        $this->assertSame('Master Batch Amber · 2.5% = 0.125 g/bottle', $after['reason']);
        $this->assertSame(0, bccomp((string) $after['suggested_kg'], '1.6666', 4));
    }

    public function test_a_colour_can_be_taken_back_out(): void
    {
        $this->masterbatches();

        $this->artisan('production:map-masterbatch-colour', [
            '--colour' => 'Amber', '--item' => 'Master Batch Amber', '--write' => true,
        ])->assertSuccessful();

        $this->artisan('production:map-masterbatch-colour', ['--forget' => 'Amber', '--write' => true])
            ->assertSuccessful();

        $this->assertSame([], $this->map());
    }
}
