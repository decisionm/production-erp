<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
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

    private ?Shift $shift = null;

    private ?WorkCenter $machine = null;

    private ?Warehouse $store = null;

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

    /**
     * A batch that names $finished as what it produced, in the given state.
     *
     * The shift/machine/warehouse rows exist only because the columns are NOT
     * NULL foreign keys; nothing here reads them.
     */
    private function shiftEntryFor(Item $finished, BatchStatus $status): ShiftProductionEntry
    {
        static $sequence = 0;
        $sequence++;

        $this->shift ??= Shift::create(['name' => 'Day', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine ??= WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        $this->store ??= Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $finished->id,
            'finished_item_id' => $finished->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-08-20',
            'batch_number' => sprintf('20260820-M01-%03d', $sequence),
            'batch_status' => $status,
            'quantity_produced' => '100',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
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

    /**
     * A dry run whose only output is "finished_good 2" is not a review, it is
     * a request to approve a number. --write then applies rows nobody saw.
     */
    public function test_the_dry_run_names_every_clean_proposal_before_write_could_apply_it(): void
    {
        $item = $this->item('FG-LISTED');
        $this->productionStandardFor($item);

        $this->artisan('inventory:classify-items')
            // Console expectations advance line by line, so assert the three
            // fields that share one proposal line as one string.
            ->expectsOutputToContain(sprintf(
                '#%-6d %-32s -> %-18s',
                $item->id,
                'FG-LISTED',
                ItemCategory::FinishedGood->value,
            ))
            ->expectsOutputToContain('carries a production standard')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNull($item->fresh()->category, 'still a dry run');
    }

    /**
     * STILL UNCLASSIFIED is a count of ACTIVE items with no category, less the
     * proposals that would actually change one.
     *
     * The old arithmetic was `active - already-classified - proposals`, which
     * subtracted twice over: `already` counted inactive classified items and
     * `proposals` counted rows --write would skip. Here that formula gives
     * 2 - 1 - 2 = -1, clamped to 0 — a clean bill of health for a factory with
     * one item still needing a person.
     */
    public function test_still_unclassified_counts_active_null_items_and_subtracts_only_what_write_would_change(): void
    {
        // Active, classified by a person, and ALSO proposable: the old
        // `already` term counted it and the old `proposals` term counted it
        // again.
        $classified = $this->item('FG-DONE', ['category' => ItemCategory::Other]);
        $this->productionStandardFor($classified);

        // Inactive and proposable: not in the active population at all, but
        // the old `proposals` term subtracted it from that population.
        $inactive = $this->item('FG-OFF', ['is_active' => false]);
        $this->productionStandardFor($inactive);

        // The one row the line is actually about.
        $this->item('MYSTERY-1');

        $this->artisan('inventory:classify-items')
            ->expectsOutputToContain('STILL UNCLASSIFIED    1')
            ->assertExitCode(0);
    }

    /** Half-finished and withdrawn batches are not evidence that the factory makes something. */
    public function test_only_a_completed_batch_counts_as_having_been_produced(): void
    {
        $made = $this->item('FG-MADE');
        $running = $this->item('FG-RUNNING');
        $withdrawn = $this->item('FG-CANCELLED');
        $legacyWithdrawn = $this->item('FG-LEGACY-CANCELLED');

        $this->shiftEntryFor($made, BatchStatus::Completed);
        $this->shiftEntryFor($running, BatchStatus::InProgress);
        // The failure this pins: a batch entered against the WRONG item and
        // then cancelled reversed its stock and made nothing, but used to
        // classify that item as a finished good all the same.
        $this->shiftEntryFor($withdrawn, BatchStatus::Cancelled);
        // Defence against a legacy/manual repair that stamped cancellation
        // without updating the enum column in the same write.
        $this->shiftEntryFor($legacyWithdrawn, BatchStatus::Completed)
            ->forceFill(['cancelled_at' => now()])
            ->save();

        $this->artisan('inventory:classify-items', ['--write' => true])->assertExitCode(0);

        $this->assertSame(ItemCategory::FinishedGood, $made->fresh()->category);
        $this->assertNull($running->fresh()->category, 'a running batch has produced nothing yet');
        $this->assertNull($withdrawn->fresh()->category, 'a cancelled batch was withdrawn as a mistake');
        $this->assertNull($legacyWithdrawn->fresh()->category, 'cancelled_at also excludes a stale completed status');
    }
}
