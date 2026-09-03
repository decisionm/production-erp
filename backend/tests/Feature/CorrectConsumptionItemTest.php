<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DEC-20260903-001 — batch consumption booked against one item is corrected
 * to another by NEW, append-only movements.
 *
 * The live case this exists for: 115 batches booked roughly 15.5 t against
 * item "Pet Resin", which DEC-20260805-002 records as demo data, while the
 * Store had issued Relpet. The owner ruled the consumption is corrected and
 * the accountant posts the matching Tally entry from the ERP's statement.
 *
 * The three things that must hold, and each has a way of going quietly
 * wrong:
 *
 *   1. THE NET MOVES, GRAM FOR GRAM. After the write, the from-item's net
 *      batch consumption is zero and the to-item's is exactly what the
 *      from-item's was. An amended batch wrote a reversal receipt as well as
 *      an issue; miss it and the correction over-books by the amended
 *      quantity.
 *   2. THE ORIGINALS ARE UNTOUCHED. The ledger is append-only and the Tally
 *      journals are already posted; a correction that edited a posted row
 *      would leave the books and the ERP disagreeing with no trace.
 *   3. IT IS IDEMPOTENT. A second write, or a re-run after an interrupted
 *      one, must find nothing left to correct — otherwise running it twice
 *      doubles a correction that looks right both times.
 */
class CorrectConsumptionItemTest extends TestCase
{
    use RefreshDatabase;

    private Item $petResin;

    private Item $relpet;

    private Item $masterbatch;

    private Item $bottle;

    private Warehouse $store;

    private Warehouse $wip;

    private Shift $shift;

    private WorkCenter $machine;

    private StockMovementService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockMovementService::class);

        $this->store = Warehouse::create(['code' => 'RM', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production WIP', 'is_active' => true]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);

        $this->petResin = Item::create(['sku' => 'PET-RESIN', 'name' => 'Pet Resin', 'uom' => 'Kgs', 'is_active' => true]);
        $this->relpet = Item::create(['sku' => 'RELPET', 'name' => 'Relpet G5801M', 'uom' => 'Kgs', 'is_active' => true]);
        $this->masterbatch = Item::create(['sku' => 'MB-AMBER', 'name' => 'Master Batch Amber', 'uom' => 'Kgs', 'is_active' => true]);
        $this->bottle = Item::create(['sku' => 'BTL-1', 'name' => '100ML ROUND', 'uom' => 'Nos', 'is_active' => true]);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
    }

    private function batch(string $date = '2026-08-04'): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->store->id,
            'production_date' => $date,
            'batch_number' => 'B-'.str_pad((string) (ShiftProductionEntry::count() + 1), 4, '0', STR_PAD_LEFT),
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '8100',
            'quantity_scrap' => '0',
        ]);
    }

    /** A batch consuming the from-item out of the Store, exactly as live did. */
    private function consumed(ShiftProductionEntry $entry, Item $item, string $kg): StockMovement
    {
        return $this->stock->recordIssue(
            itemId: $item->id,
            warehouseId: $this->store->id,
            quantity: $kg,
            reference: "SPE #{$entry->id}",
            allowNegative: true,
            purpose: StockMovementPurpose::Consumption,
        );
    }

    /** The reversal row an amendment writes: the material handed back, unstamped. */
    private function amendedBack(ShiftProductionEntry $entry, Item $item, string $kg): StockMovement
    {
        return $this->stock->recordReceipt(
            itemId: $item->id,
            warehouseId: $this->store->id,
            quantity: $kg,
            unitCost: '90.0000',
            reference: "SPE #{$entry->id} amended",
        );
    }

    /** The signed net of every batch row for one item: issues minus hand-backs. */
    private function netBatchConsumption(Item $item): string
    {
        $net = '0.0000';

        $rows = StockMovement::query()
            ->where('item_id', $item->id)
            ->where(fn ($q) => $q->where('reference', 'like', 'SPE #%')->orWhere('reference', 'like', 'Correction of SPE #%'))
            ->get();

        foreach ($rows as $row) {
            $sign = $row->type === StockMovementType::Issue ? '1' : '-1';
            $net = bcadd($net, bcmul((string) $row->quantity, $sign, 4), 4);
        }

        return $net;
    }

    private function balance(Item $item, Warehouse $warehouse): string
    {
        return bcadd((string) StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity'), '0', 4);
    }

    private function assertLedgerSigns(): void
    {
        foreach (StockBalance::all() as $balance) {
            $sum = '0.0000';

            foreach (DB::table('stock_movements')->where('item_id', $balance->item_id)->where('warehouse_id', $balance->warehouse_id)->get(['type', 'quantity']) as $row) {
                $sign = in_array((string) $row->type, ['receipt', 'transfer_in'], true) ? '1' : '-1';
                $sum = bcadd($sum, bcmul((string) $row->quantity, $sign, 4), 4);
            }

            $this->assertSame(
                bcadd($sum, '0', 4),
                bcadd((string) $balance->quantity, '0', 4),
                "balance drifted from the ledger for item {$balance->item_id} at warehouse {$balance->warehouse_id}",
            );
        }
    }

    public function test_the_dry_run_states_the_batches_and_the_net_and_posts_nothing(): void
    {
        $one = $this->batch('2026-08-04');
        $two = $this->batch('2026-08-05');
        $this->consumed($one, $this->petResin, '118.998');
        $this->consumed($two, $this->petResin, '120.002');

        $before = StockMovement::count();

        $this->artisan('inventory:correct-consumption-item --from-item=PET-RESIN --to-item=RELPET')
            ->expectsOutputToContain('batches 2')
            ->expectsOutputToContain('net kg 239.0000')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame($before, StockMovement::count(), 'a dry run posts nothing');
        $this->assertSame('239.0000', $this->netBatchConsumption($this->petResin));
        $this->assertSame('0.0000', $this->netBatchConsumption($this->relpet));
    }

    public function test_the_write_moves_the_net_from_one_item_to_the_other_and_leaves_the_originals_alone(): void
    {
        $one = $this->batch('2026-08-04');
        $original = $this->consumed($one, $this->petResin, '118.998');
        $originalQuantity = (string) $original->fresh()->quantity;

        $this->artisan('inventory:correct-consumption-item --from-item=PET-RESIN --to-item=RELPET --write')
            ->expectsOutputToContain('POSTED')
            ->assertSuccessful();

        // (1) The net moved, gram for gram.
        $this->assertSame('0.0000', $this->netBatchConsumption($this->petResin));
        $this->assertSame('118.9980', $this->netBatchConsumption($this->relpet));

        // (2) The original is exactly as it was — same quantity, same item,
        // same reference, not soft-deleted.
        $still = StockMovement::find($original->id);
        $this->assertNotNull($still);
        $this->assertSame($originalQuantity, (string) $still->quantity);
        $this->assertSame($this->petResin->id, (int) $still->item_id);
        $this->assertSame("SPE #{$one->id}", $still->reference);

        // The correction rows name the batch and the row they correct.
        $corrections = StockMovement::where('reference', 'like', 'Correction of SPE #%')->get();
        $this->assertCount(2, $corrections);
        foreach ($corrections as $row) {
            $this->assertStringContainsString("Corrects stock movement #{$original->id}", (string) $row->notes);
            $this->assertStringContainsString('DEC-20260903-001', (string) $row->reference);
        }

        // Posted where the original was: the Store, not Production/WIP.
        $this->assertSame([$this->store->id], $corrections->pluck('warehouse_id')->unique()->map(fn ($id) => (int) $id)->all());
        $this->assertSame('0.0000', $this->balance($this->relpet, $this->wip));

        $this->assertLedgerSigns();
    }

    public function test_an_amended_batch_keeps_its_net(): void
    {
        // The live shape of an amendment: the wrong figure issued, handed
        // back by the amendment, the right figure issued again. Net 118.998.
        $entry = $this->batch('2026-08-06');
        $this->consumed($entry, $this->petResin, '130.000');
        $this->amendedBack($entry, $this->petResin, '130.000');
        $this->consumed($entry, $this->petResin, '118.998');

        $this->assertSame('118.9980', $this->netBatchConsumption($this->petResin));

        $this->artisan('inventory:correct-consumption-item --from-item=PET-RESIN --to-item=RELPET --write')
            ->assertSuccessful();

        // Miss the reversal and this would read 249.0000.
        $this->assertSame('0.0000', $this->netBatchConsumption($this->petResin));
        $this->assertSame('118.9980', $this->netBatchConsumption($this->relpet));
        $this->assertLedgerSigns();
    }

    public function test_a_second_write_finds_nothing_left_to_correct(): void
    {
        $entry = $this->batch('2026-08-04');
        $this->consumed($entry, $this->petResin, '118.998');

        $this->artisan('inventory:correct-consumption-item --from-item=PET-RESIN --to-item=RELPET --write')->assertSuccessful();
        $after = StockMovement::count();

        $this->artisan('inventory:correct-consumption-item --from-item=PET-RESIN --to-item=RELPET --write')
            ->expectsOutputToContain('nothing left to correct')
            ->assertSuccessful();

        $this->assertSame($after, StockMovement::count(), 'a second run must post nothing');
        $this->assertSame('118.9980', $this->netBatchConsumption($this->relpet));
    }

    public function test_only_batch_consumption_of_the_from_item_is_corrected(): void
    {
        $entry = $this->batch('2026-08-04');
        $this->consumed($entry, $this->petResin, '118.998');

        // A store receipt of the same item, and another item's consumption
        // on the same batch: neither is batch consumption of the from-item.
        $this->stock->recordReceipt(
            itemId: $this->petResin->id,
            warehouseId: $this->store->id,
            quantity: '5000',
            unitCost: '90.0000',
            reference: 'Provisional opening',
            purpose: StockMovementPurpose::Opening,
        );
        $this->consumed($entry, $this->masterbatch, '0.250');

        $this->artisan('inventory:correct-consumption-item --from-item=PET-RESIN --to-item=RELPET --write')->assertSuccessful();

        // The masterbatch line is untouched, and the opening is still an
        // opening: only the batch's resin consumption moved.
        $this->assertSame('0.2500', $this->netBatchConsumption($this->masterbatch));
        $this->assertSame(1, StockMovement::where('purpose', StockMovementPurpose::Opening->value)->count());
        $this->assertSame('118.9980', $this->netBatchConsumption($this->relpet));
    }

    public function test_the_statement_lists_other_negative_balances_without_correcting_them(): void
    {
        $entry = $this->batch('2026-08-04');
        $this->consumed($entry, $this->petResin, '118.998');

        // The Master Batch Amber case: negative in the same warehouse.
        $this->consumed($entry, $this->masterbatch, '113.4735');
        $negativeBefore = $this->balance($this->masterbatch, $this->store);
        $this->assertSame('-113.4735', $negativeBefore);

        $this->artisan('inventory:correct-consumption-item --from-item=PET-RESIN --to-item=RELPET')
            ->expectsOutputToContain('Master Batch Amber')
            ->assertSuccessful();

        $this->assertSame($negativeBefore, $this->balance($this->masterbatch, $this->store), 'listed, never corrected');
    }

    public function test_an_item_that_cannot_be_named_uniquely_is_refused(): void
    {
        $this->artisan('inventory:correct-consumption-item --from-item=NOT-AN-ITEM --to-item=RELPET')->assertFailed();
        $this->artisan('inventory:correct-consumption-item --from-item=PET-RESIN --to-item=PET-RESIN')->assertFailed();
    }
}
