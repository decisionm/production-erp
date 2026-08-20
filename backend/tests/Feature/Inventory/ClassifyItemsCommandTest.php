<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `inventory:classify-items` — proposes an item category from evidence, and
 * the things it must never do.
 *
 * The command exists because 624 active items need a category before the
 * purchase-order / sales-order / material-request rules can be enforced, and
 * only about 166 of them can be derived. Its value is entirely in what it
 * REFUSES to do with the other 458, so that is what these tests pin.
 */
class ClassifyItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** A production standard is the strongest "the factory makes this" signal. */
    private function productionStandardFor(Item $item): void
    {
        DB::table('production_standards')->insert([
            'item_id' => $item->id,
            'source_product_name' => $item->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function item(string $sku, array $overrides = []): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $sku,
            'uom' => 'Nos.',
            'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $item = $this->item('FG-1');
        $this->productionStandardFor($item);

        $this->artisan('inventory:classify-items')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNull($item->fresh()->category, 'a dry run must not write');
    }

    public function test_write_applies_a_proposal_backed_by_evidence(): void
    {
        $item = $this->item('FG-2');
        $this->productionStandardFor($item);

        $this->artisan('inventory:classify-items', ['--write' => true])->assertExitCode(0);

        // Carrying a production standard means the factory MAKES it, which is
        // what a sales order is for.
        $this->assertSame(ItemCategory::FinishedGood, $item->fresh()->category);
    }

    public function test_an_item_with_no_evidence_is_left_null_rather_than_guessed(): void
    {
        $unknown = $this->item('MYSTERY-1');

        $this->artisan('inventory:classify-items', ['--write' => true])->assertExitCode(0);

        // THE POINT OF THE COMMAND. 458 live items look like this. A default
        // of raw_material here would be a guessed factory value that the
        // document rules then act on — the PR #128 error class.
        $this->assertNull($unknown->fresh()->category);
    }

    public function test_a_category_a_person_already_set_is_never_overwritten(): void
    {
        // Evidence says finished good; a person has said otherwise. The person
        // wins — SOURCE-PRIORITY puts an owner answer above derived data.
        $item = $this->item('FG-3', ['category' => ItemCategory::Other]);
        $this->productionStandardFor($item);

        $this->artisan('inventory:classify-items', ['--write' => true])->assertExitCode(0);

        $this->assertSame(ItemCategory::Other, $item->fresh()->category);
    }

    public function test_contradictory_evidence_is_reported_and_not_written(): void
    {
        // Both produced AND used as packing. Which it really is belongs to the
        // factory, so the command says so and writes nothing for this row.
        $item = $this->item('CONFLICT-1');
        $this->productionStandardFor($item);
        DB::table('packing_material_mappings')->insert([
            'spec_kind' => 'tray',
            'spec_value' => 'test',
            'item_id' => $item->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('inventory:classify-items', ['--write' => true])
            ->expectsOutputToContain('CONTRADICTORY EVIDENCE')
            ->assertExitCode(0);

        $this->assertNull(
            $item->fresh()->category,
            'a row the evidence disagrees about must be left for a person',
        );
    }

    public function test_a_packing_material_mapping_classifies_packing(): void
    {
        $item = $this->item('PACK-1');
        DB::table('packing_material_mappings')->insert([
            'spec_kind' => 'tray',
            'spec_value' => 'test',
            'item_id' => $item->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('inventory:classify-items', ['--write' => true])->assertExitCode(0);

        $this->assertSame(ItemCategory::PackingMaterial, $item->fresh()->category);
    }
}
