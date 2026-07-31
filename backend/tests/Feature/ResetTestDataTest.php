<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing the rehearsal, without clearing the work.
 *
 * The command's two dangerous powers are deleting batches and rewriting stock
 * balances, so these tests are written against the two ways it could quietly
 * ruin a live database:
 *
 *  - deleting a master (products, standards, machines, settings) — days of
 *    work, and nothing in a rehearsal reset should go near them;
 *  - recomputing balances wrongly, which is worse than not recomputing at all:
 *    a floor that silently believes it has no resin stops a shift, and nobody
 *    would look for the cause in a cleanup command run days earlier.
 */
class ResetTestDataTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private Warehouse $fg;

    private Item $resin;

    private Item $bottle;

    private WorkCenter $machine;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store']);
        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'Finished Goods']);
        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Polyster Chips', 'uom' => 'KGS']);
        $this->bottle = Item::create(['sku' => 'BTL', 'name' => 'Bottle 200ml', 'uom' => 'NOS']);
        $this->machine = WorkCenter::create(['name' => 'Machine 1', 'code' => 'MC-01', 'is_active' => true]);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
    }

    private function entry(string $date = '2026-07-31'): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => $date,
            'batch_status' => BatchStatus::Completed->value,
            'status' => 'pending',
            'quantity_produced' => 1000,
        ]);
    }

    private function movement(Item $item, Warehouse $warehouse, string $type, string $qty, ?string $reference = null): StockMovement
    {
        return StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => $type,
            'quantity' => $qty,
            'reference' => $reference,
            'movement_date' => now(),
        ]);
    }

    public function test_a_dry_run_reports_and_deletes_nothing(): void
    {
        $entry = $this->entry();
        $this->movement($this->bottle, $this->fg, 'receipt', '1000', 'SPE #'.$entry->id);

        $this->artisan('production:reset-test-data')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertDatabaseCount('shift_production_entries', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_the_write_run_clears_the_batch_and_its_movements(): void
    {
        $entry = $this->entry();
        $this->movement($this->bottle, $this->fg, 'receipt', '1000', 'SPE #'.$entry->id);
        $this->movement($this->resin, $this->store, 'issue', '20', 'SPE #'.$entry->id);

        $this->artisan('production:reset-test-data --write --force')->assertExitCode(0);

        $this->assertDatabaseCount('shift_production_entries', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_the_masters_are_never_touched(): void
    {
        // The whole reason this is a command: a rehearsal reset that took the
        // imported workbook or the machine list with it would cost days.
        $standard = ProductionStandard::create([
            'item_id' => $this->bottle->id,
            'source_product_name' => '200ML ROUND',
            'cavities' => 4,
            'unit_weight_grams' => '18.0000',
            'cycle_time' => '16.50',
            'status' => 'draft',
        ]);
        $entry = $this->entry();
        $this->movement($this->bottle, $this->fg, 'receipt', '1000', 'SPE #'.$entry->id);

        $this->artisan('production:reset-test-data --write --force')->assertExitCode(0);

        $this->assertNotNull($standard->fresh(), 'The imported product standard must survive.');
        $this->assertNotNull($this->machine->fresh());
        $this->assertNotNull($this->bottle->fresh());
        $this->assertNotNull($this->resin->fresh());
        $this->assertNotNull($this->shift->fresh());
        $this->assertNotNull($this->store->fresh());
    }

    public function test_a_movement_that_is_not_from_a_batch_survives_and_keeps_its_stock(): void
    {
        // THE test that matters most. Opening stock someone entered by hand is
        // not rehearsal, and a recompute that zeroed it would tell the floor it
        // has no resin — a stopped shift whose cause nobody would trace back to
        // a cleanup run days earlier.
        $this->movement($this->resin, $this->store, 'receipt', '5000', 'Opening stock');
        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '4980.0000',
            'average_cost' => '118.5000',
        ]);

        // And one rehearsal batch that consumed 20 kg of it.
        $entry = $this->entry();
        $this->movement($this->resin, $this->store, 'issue', '20', 'SPE #'.$entry->id);

        $this->artisan('production:reset-test-data --write --force')->assertExitCode(0);

        $this->assertDatabaseCount('shift_production_entries', 0);
        // The hand-entered receipt survives, and the balance is back to the full
        // 5,000 — the 20 kg the deleted batch took is returned by construction,
        // because the balance is recomputed from what remains.
        $this->assertSame(
            '5000.0000',
            (string) StockBalance::query()
                ->where('item_id', $this->resin->id)
                ->where('warehouse_id', $this->store->id)
                ->value('quantity'),
        );
        $this->assertDatabaseHas('stock_movements', ['reference' => 'Opening stock']);
    }

    public function test_transfers_count_in_the_right_direction(): void
    {
        // A transfer pair moves stock between warehouses; treating transfer_in
        // as an issue (or ignoring it) would invent or destroy stock during the
        // recompute. Pinned because the first version of this command read a
        // `direction` column that does not exist.
        $this->movement($this->resin, $this->store, 'receipt', '1000', 'Opening stock');
        $this->movement($this->resin, $this->store, 'transfer_out', '300', 'Day bin load');
        $this->movement($this->resin, $this->fg, 'transfer_in', '300', 'Day bin load');
        foreach ([$this->store, $this->fg] as $warehouse) {
            StockBalance::create([
                'item_id' => $this->resin->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '0.0000',
                'average_cost' => '118.5000',
            ]);
        }

        // Nothing to clear (no entries) — but the reporting path must still be
        // safe to run, and must not rewrite balances on a dry run.
        $this->artisan('production:reset-test-data')->assertExitCode(0);
        $this->assertSame('0.0000', (string) StockBalance::query()
            ->where('warehouse_id', $this->store->id)->value('quantity'));

        // With a batch present, the write run recomputes: the day-bin transfer
        // pair is rehearsal and goes, leaving the opening 1,000 in the store.
        $entry = $this->entry();
        $this->movement($this->bottle, $this->fg, 'receipt', '10', 'SPE #'.$entry->id);

        $this->artisan('production:reset-test-data --write --force')->assertExitCode(0);

        $this->assertSame('1000.0000', (string) StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->store->id)
            ->value('quantity'));
    }

    public function test_the_since_option_leaves_earlier_batches_alone(): void
    {
        $old = $this->entry('2026-07-20');
        $recent = $this->entry('2026-07-31');

        $this->artisan('production:reset-test-data --write --force --since=2026-07-30')->assertExitCode(0);

        $this->assertNotNull($old->fresh(), 'A batch before the cutoff must survive.');
        $this->assertNull($recent->fresh());
    }

    public function test_a_batch_whose_voucher_reached_tally_is_named_not_hidden(): void
    {
        // The command cannot unsend a voucher. Saying so, with the numbers, is
        // the only honest behaviour — a silent local delete would leave the
        // books double-counting with nobody aware.
        $entry = $this->entry();
        $entry->update(['status' => 'synced']);
        TallySyncEntry::create([
            'syncable_type' => ShiftProductionEntry::class,
            'syncable_id' => $entry->id,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => ['voucher_number' => 'SPE-'.$entry->id],
            'status' => 'synced',
        ]);

        $this->artisan('production:reset-test-data')
            ->expectsOutputToContain('SPE-'.$entry->id)
            ->expectsOutputToContain('does not remove them THERE')
            ->assertExitCode(0);
    }
}
