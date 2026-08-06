<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading a bottle's colour out of the name Tally already gave it.
 *
 * The item names below are verbatim from the factory's real Tally catalogue.
 * They are the point of the test: the reason this command exists is that these
 * names state the colour while the colour COLUMN is empty, and the reason it has
 * to be careful is that "Milk White" contains "White" and getting that wrong
 * picks a different masterbatch.
 *
 * Setting a colour changes floor behaviour — Start Batch stops asking for it and
 * shows the master's value — so every test here is about refusing to guess.
 */
class DeriveItemColoursTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $name, ?string $colour = null): Item
    {
        return Item::create([
            'sku' => $name,
            'name' => $name,
            'uom' => 'NOS',
            'is_active' => true,
            'colour' => $colour,
        ]);
    }

    public function test_it_reads_the_colour_from_a_real_tally_name(): void
    {
        $amber = $this->item('A.15ml Round Pet Bottle Amber-5gms');
        $clear = $this->item('A.15ml Round Pet Bottle Clear -5gms');

        $this->artisan('inventory:derive-item-colours --write')->assertExitCode(0);

        $this->assertSame('Amber', $amber->fresh()->colour);
        $this->assertSame('Clear', $clear->fresh()->colour);
    }

    public function test_a_longer_colour_wins_over_the_shorter_one_inside_it(): void
    {
        // The case that makes the ordering load-bearing: matching "White" first
        // would record every Milk White bottle as White, and White and Milk
        // White are different masterbatches.
        $item = $this->item('B.100 Ml Round Milk White Pet Bottle-12.9gms');

        $this->artisan('inventory:derive-item-colours --write');

        $this->assertSame('Milk White', $item->fresh()->colour);
    }

    public function test_dark_amber_is_not_recorded_as_amber(): void
    {
        $item = $this->item('B.120ml Round Pet Bottle Dark Amber-16.5gms');

        $this->artisan('inventory:derive-item-colours --write');

        $this->assertSame('Dark Amber', $item->fresh()->colour);
    }

    public function test_two_colour_words_are_left_alone(): void
    {
        // Unreadable, so nothing is written. Silence is the correct answer to a
        // name nobody can interpret confidently.
        $item = $this->item('B.200ml Round Amber and Clear Pet Bottle');

        $this->artisan('inventory:derive-item-colours --write');

        $this->assertNull($item->fresh()->colour);
    }

    public function test_an_existing_colour_is_never_overwritten(): void
    {
        // Someone set this deliberately. The command does not get to argue with
        // them, even when the name disagrees — it reports instead.
        $item = $this->item('A.15ml Round Pet Bottle Amber-5gms', 'Green');

        $this->artisan('inventory:derive-item-colours --write')
            ->expectsOutputToContain('disagrees with the name');

        $this->assertSame('Green', $item->fresh()->colour);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $item = $this->item('A.30ml Round Pet Bottle Amber- 8gms');

        $this->artisan('inventory:derive-item-colours')
            ->expectsOutputToContain('DRY RUN');

        $this->assertNull($item->fresh()->colour);
    }

    public function test_only_products_skips_the_colourant_itself(): void
    {
        // "Master Batch Amber" IS the amber colourant, not a bottle that happens
        // to be amber. Giving it a colour would make it look like a product.
        $mb = $this->item('Master Batch Amber');
        $bottle = $this->item('A.15ml Round Pet Bottle Amber-5gms');

        $this->artisan('inventory:derive-item-colours --write --only-products');

        $this->assertNull($mb->fresh()->colour);
        $this->assertSame('Amber', $bottle->fresh()->colour);
    }

    public function test_only_masterbatch_colours_the_colourants_and_nothing_else(): void
    {
        // THE LIVE INCIDENT THIS SCOPE EXISTS FOR. The masterbatch row came up
        // empty on an amber run because no colourant on live carried a colour —
        // resolveMasterbatchItem looks for kg-family items whose items.colour
        // matches the run's, and 63 items were blank.
        //
        // --only-products cannot fix it: it skips the masterbatches by design.
        // And running with no scope would colour the amber SCRAP too, which is a
        // Kgs item and would then be offered to the floor as a masterbatch on
        // every amber run. So this scope is the colourant family and nothing else.
        $amber = $this->item('Master Batch Amber');
        $arihant = $this->item('ARIHANT PET WHITE 1020 Master Batch');
        $scrap = $this->item('PET Scrap - Amber');
        $cap = $this->item('20mm Flip Top Cap -White');
        $tape = $this->item('Packing Tape Green');
        $bottle = $this->item('A.15ml Round Pet Bottle Amber-5gms');

        $this->artisan('inventory:derive-item-colours --write --only-masterbatch')
            ->assertSuccessful();

        $this->assertSame('Amber', $amber->fresh()->colour);
        // However their masters space it.
        $this->assertSame('White', $arihant->fresh()->colour);

        // And nothing else — least of all the scrap.
        $this->assertNull($scrap->fresh()->colour, 'Amber scrap must never become a candidate colourant.');
        $this->assertNull($cap->fresh()->colour);
        $this->assertNull($tape->fresh()->colour);
        $this->assertNull($bottle->fresh()->colour);
    }

    public function test_the_two_scopes_are_opposites_and_refuse_to_run_together(): void
    {
        $this->artisan('inventory:derive-item-colours --write --only-products --only-masterbatch')
            ->expectsOutputToContain('opposites')
            ->assertFailed();
    }

    public function test_a_colour_word_inside_another_word_is_not_a_match(): void
    {
        // Whole words only. A name is not a colour just because it contains the
        // letters of one.
        $item = $this->item('B.500ml Clearance Sample Bottle');

        $this->artisan('inventory:derive-item-colours --write');

        $this->assertNull($item->fresh()->colour);
    }

    public function test_a_name_with_no_colour_is_left_null_rather_than_guessed(): void
    {
        // One real mapped item has no colour in its name; it must stay unset so
        // the supervisor is still asked, rather than given a default.
        $item = $this->item('O.100ml Hair Oil -16.5g');

        $this->artisan('inventory:derive-item-colours --write');

        $this->assertNull($item->fresh()->colour);
    }

    public function test_an_inactive_item_is_ignored(): void
    {
        $item = $this->item('A.15ml Round Pet Bottle Amber-5gms');
        $item->update(['is_active' => false]);

        $this->artisan('inventory:derive-item-colours --write');

        $this->assertNull($item->fresh()->colour);
    }
}
