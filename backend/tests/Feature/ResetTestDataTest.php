<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
 *
 * And a third, which only appears once the factory has history: `--since`. A
 * cutoff that scoped the batches but not the bags, lots, bin ledger and bin
 * transfers would let a one-day cleanup take everything the factory ever made —
 * so the --since tests below build a world on BOTH sides of the cutoff and
 * assert the earlier side survives whole, rows and recomputed balances alike.
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

    private function movement(
        Item $item,
        Warehouse $warehouse,
        string $type,
        string $qty,
        ?string $reference = null,
        ?string $date = null,
        ?string $transferGroup = null,
    ): StockMovement {
        return StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => $type,
            'quantity' => $qty,
            'reference' => $reference,
            'transfer_group' => $transferGroup,
            'movement_date' => $date ?? now(),
        ]);
    }

    /**
     * Both legs of one transfer, sharing a transfer_group — the shape
     * StockMovementService::recordTransfer() actually writes. Returns the group
     * so a test can assert on the pair rather than on either leg.
     */
    private function transferPair(
        Item $item,
        Warehouse $from,
        Warehouse $to,
        string $qty,
        string $reference,
        string $date,
        ?string $inDate = null,
    ): string {
        $group = (string) Str::uuid();

        $this->movement($item, $from, 'transfer_out', $qty, $reference, $date, $group);
        $this->movement($item, $to, 'transfer_in', $qty, $reference, $inDate ?? $date, $group);

        return $group;
    }

    private function lotWithBags(string $receivedDate, array $barcodes, string $perBagKg = '25.0000'): MaterialLot
    {
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'supplier_lot_no' => 'SUP-'.$receivedDate,
            'received_date' => $receivedDate,
            'bag_count' => count($barcodes),
            'bag_weight_kg' => $perBagKg,
            'total_received_kg' => number_format((float) $perBagKg * count($barcodes), 4, '.', ''),
        ]);

        foreach ($barcodes as $barcode) {
            MaterialBag::create([
                'material_lot_id' => $lot->id,
                'barcode' => $barcode,
                'original_kg' => $perBagKg,
                'remaining_kg' => $perBagKg,
                'status' => MaterialBagStatus::InStore->value,
                'current_warehouse_id' => $this->store->id,
            ]);
        }

        return $lot;
    }

    private function binLedgerRow(MaterialBag $bag, string $qty, string $recordedAt, ?ShiftProductionEntry $entry = null): DayBinMovement
    {
        return DayBinMovement::create([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->resin->id,
            'shift_production_entry_id' => $entry?->id,
            'type' => DayBinMovementType::Load->value,
            'material_bag_id' => $bag->id,
            'quantity_kg' => $qty,
            'recorded_at' => $recordedAt,
        ]);
    }

    private function balanceFor(Item $item, Warehouse $warehouse): string
    {
        return (string) StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity');
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

    public function test_the_since_option_leaves_the_whole_earlier_world_alone(): void
    {
        // The test that stands between a one-day cleanup and the factory's
        // entire history. Two weeks after go-live somebody clears "since
        // yesterday"; everything below dated 20 July is real production and must
        // be untouched afterwards — the batch, its bin ledger row, the lot and
        // its bags, BOTH legs of the transfer that moved the resin, and the
        // recomputed balances that follow from them.
        $bin = Warehouse::create(['code' => 'WH-BIN', 'name' => 'Factory Day Bin']);

        // ---- Before the cutoff: real production. ----
        $this->movement($this->resin, $this->store, 'receipt', '5000', 'Opening stock', '2026-07-10');
        $julyLot = $this->lotWithBags('2026-07-15', ['LOT-JUL-B1', 'LOT-JUL-B2']);
        $julyBag = $julyLot->bags()->first();
        $julyTransfer = $this->transferPair($this->resin, $this->store, $bin, '300', 'Day bin load — bag LOT-JUL-B1', '2026-07-20');
        $julyEntry = $this->entry('2026-07-20');
        $julyIssue = $this->movement($this->resin, $bin, 'issue', '200', 'SPE #'.$julyEntry->id, '2026-07-20');
        $julyLedger = $this->binLedgerRow($julyBag, '300', '2026-07-20 07:15:00', $julyEntry);
        // A day-bin movement that is NOT half of a transfer — a hand-entered
        // recount, standing alone. The reference match would claim it and the
        // pair rule cannot protect it, so the date is the only thing that does.
        $julyRecount = $this->movement($this->resin, $bin, 'receipt', '50', 'Day bin recount adjustment', '2026-07-20');

        // ---- After the cutoff: the rehearsal to be cleared. ----
        $rehearsalLot = $this->lotWithBags('2026-07-31', ['LOT-REH-B1']);
        $rehearsalBag = $rehearsalLot->bags()->first();
        $rehearsalTransfer = $this->transferPair($this->resin, $this->store, $bin, '400', 'Day bin load — bag LOT-REH-B1', '2026-07-31');
        $rehearsalEntry = $this->entry('2026-07-31');
        $this->movement($this->resin, $bin, 'issue', '150', 'SPE #'.$rehearsalEntry->id, '2026-07-31');
        $this->movement($this->bottle, $this->fg, 'receipt', '1000', 'SPE #'.$rehearsalEntry->id, '2026-07-31');
        $rehearsalLedger = $this->binLedgerRow($rehearsalBag, '400', '2026-07-31 07:15:00', $rehearsalEntry);

        // The recompute only UPDATES balance rows, so they must exist for the
        // figures below to mean anything.
        foreach ([[$this->resin, $this->store], [$this->resin, $bin], [$this->bottle, $this->fg]] as [$item, $warehouse]) {
            StockBalance::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '0.0000',
                'average_cost' => '118.5000',
            ]);
        }

        $this->artisan('production:reset-test-data --write --force --since=2026-07-30')->assertExitCode(0);

        // ---- The earlier world is intact, row for row. ----
        $this->assertNotNull($julyEntry->fresh(), 'A batch before the cutoff must survive.');
        $this->assertNotNull($julyIssue->fresh(), "The surviving batch's own issue must survive with it.");
        $this->assertNotNull($julyRecount->fresh(), 'A pre-cutoff day-bin movement is not rehearsal just because its reference matches.');
        $this->assertNotNull($julyLot->fresh(), 'A lot received before the cutoff is real intake, not rehearsal.');
        $this->assertNotNull($julyLedger->fresh(), 'A day-bin ledger row from before the cutoff must survive.');
        $this->assertDatabaseCount('material_lots', 1);
        $this->assertDatabaseCount('material_bags', 2);
        $this->assertDatabaseCount('day_bin_movements', 1);
        $this->assertDatabaseHas('stock_movements', ['reference' => 'Opening stock']);
        $this->assertSame(
            2,
            StockMovement::query()->where('transfer_group', $julyTransfer)->count(),
            'BOTH legs of a pre-cutoff transfer must survive — half a transfer invents stock at one end.',
        );
        // Opening receipt + the two July transfer legs + the July issue + the
        // July recount. Nothing else, and nothing missing.
        $this->assertDatabaseCount('stock_movements', 5);

        // ---- The rehearsal is gone. ----
        $this->assertNull($rehearsalEntry->fresh());
        $this->assertNull($rehearsalLot->fresh());
        $this->assertNull($rehearsalBag->fresh());
        $this->assertNull($rehearsalLedger->fresh());
        $this->assertSame(0, StockMovement::query()->where('transfer_group', $rehearsalTransfer)->count());

        // ---- And the balances follow from exactly those survivors. ----
        // Store: 5,000 in, 300 transferred to the bin = 4,700. If the pre-cutoff
        // transfer_out had been deleted the store would read 5,000 — 300 kg of
        // resin conjured out of a cleanup command.
        $this->assertSame('4700.0000', $this->balanceFor($this->resin, $this->store));
        // Bin: 300 loaded, 200 consumed by the surviving batch, 50 recounted in
        // = 150. Deleting the transfer_in would leave -150, clamped to a silent
        // zero — a bin the floor is told is empty while it is not.
        $this->assertSame('150.0000', $this->balanceFor($this->resin, $bin));
        $this->assertSame('0.0000', $this->balanceFor($this->bottle, $this->fg));
    }

    public function test_a_transfer_straddling_the_cutoff_is_left_whole(): void
    {
        // Legs are written in one transaction with one date, so this is a state
        // the services do not produce — but if a backdated correction ever makes
        // one, the answer is to leave the pair ALONE, not to delete the half that
        // happens to fall after the cutoff.
        $bin = Warehouse::create(['code' => 'WH-BIN', 'name' => 'Factory Day Bin']);
        $group = (string) Str::uuid();
        $out = $this->movement($this->resin, $this->store, 'transfer_out', '300', 'Day bin load — bag X', '2026-07-29', $group);
        $in = $this->movement($this->resin, $bin, 'transfer_in', '300', 'Day bin load — bag X', '2026-07-31', $group);

        // A batch after the cutoff, so the command has something to clear.
        $rehearsal = $this->entry('2026-07-31');
        $this->movement($this->bottle, $this->fg, 'receipt', '1000', 'SPE #'.$rehearsal->id, '2026-07-31');

        $this->artisan('production:reset-test-data --write --force --since=2026-07-30')
            ->expectsOutputToContain('one leg before the cutoff and one after')
            ->assertExitCode(0);

        $this->assertNull($rehearsal->fresh());
        $this->assertNotNull($out->fresh(), 'The pre-cutoff leg must survive.');
        $this->assertNotNull($in->fresh(), 'And so must its partner, or the pair is half-deleted.');
        $this->assertSame(2, StockMovement::query()->where('transfer_group', $group)->count());
    }

    public function test_a_since_that_is_not_a_date_is_refused_not_stripped(): void
    {
        // The defect this replaces: the workflow stripped "yesterday" to the
        // empty string, the flag was dropped, and a run the operator had scoped
        // to one day deleted every batch in the database. The command refuses the
        // same shapes itself — the second layer, and the only one a hand-typed
        // run has.
        $entry = $this->entry('2026-07-31');
        $this->movement($this->bottle, $this->fg, 'receipt', '1000', 'SPE #'.$entry->id);

        $this->artisan('production:reset-test-data --write --force --since=yesterday')
            ->expectsOutputToContain('YYYY-MM-DD')
            ->assertExitCode(1);

        // An empty --since is the exact value the stripping produced.
        $this->artisan('production:reset-test-data', ['--write' => true, '--force' => true, '--since' => ''])
            ->assertExitCode(1);

        // Shaped like a date, but no such day exists.
        $this->artisan('production:reset-test-data --write --force --since=2026-02-31')
            ->assertExitCode(1);

        $this->assertNotNull($entry->fresh(), 'A refused run must delete nothing at all.');
        $this->assertDatabaseCount('stock_movements', 1);
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
