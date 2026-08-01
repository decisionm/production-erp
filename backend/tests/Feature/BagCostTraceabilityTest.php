<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\MaterialCostVersionService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Exceptions\DuplicateAllocationException;
use App\Modules\Production\Models\BatchResinAllocation;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BagCostAllocationService;
use App\Modules\Production\Services\BagLotRateResolver;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * RESIN-BAG COST TRACEABILITY — the story the factory owner asked for, told
 * end to end: which bag did this batch's resin come out of, what was that
 * bag bought for, and what did the batch therefore cost per accepted piece.
 *
 * WHAT MUST HOLD, and each one is a scene below:
 *
 *  1. FIFO ACROSS A BAG BOUNDARY. Two bags at two different rates are
 *     scanned into a machine. A batch that burns more than the first bag
 *     held draws the oldest layer to exhaustion and the remainder from the
 *     next one, priced at ITS rate — and the part-used second bag carries
 *     into the NEXT batch at that same rate, with nobody carrying anything
 *     forward by hand.
 *  2. A CORRECTION REPLACES, IT DOES NOT ACCUMULATE. An amendment reverses
 *     the whole first run (stamped, never deleted) and writes run 2 beside
 *     it. Afterwards the layers hold exactly what they would have held if
 *     the wrong completion had never happened.
 *  3. NEVER TWICE. A batch may have at most one live allocation run.
 *  4. BEYOND THE LAYERS IS ADMITTED, NOT HIDDEN. Consumption the scans
 *     cannot account for falls back to the stock average and says so in
 *     rate_source, because that gap is the scan discipline's own error bar.
 *  5. NO PRICE IS EVER INVENTED. A bag whose lot has no recorded rate —
 *     opening stock, the commonest case on day one — is allocated with a
 *     null rate and rate_source 'unknown', and the batch total goes null
 *     rather than quietly understating itself.
 *  6. THE SCAN ASKS A QUESTION, NEVER FOR A WEIGHT. Topping up a machine
 *     the estimate says still holds material is refused until one word
 *     explains why; below the threshold nobody is asked anything.
 *  7. THE MONEY READS CORRECTLY, including cost per ACCEPTED piece after a
 *     quality check has netted the batch.
 *  8. RATES AND SUPPLIER IDENTITIES ARE FINANCE'S. A production login sees
 *     totals and cost-per-piece; the per-layer breakdown is ABSENT from its
 *     payload, not merely nulled.
 *
 * WHERE THE RATES COME FROM IN EACH SCENE. Inventory owns the rate contract
 * (MaterialLot::currentRatePerKg()/receiptRatePerKg()/hasRevisions()) and
 * BagLotRateResolver is the seam Production adapts it through.
 *
 *  - The MULTI-RATE scenes stub that seam, keyed by lot id. What they exist
 *    to assert is the ALLOCATION ARITHMETIC — which layer, how much, at
 *    which frozen rate — and two known rates state that far more plainly
 *    than two lots' worth of GRN fixtures would.
 *  - THE CONTRACT ITSELF IS EXERCISED FOR REAL in
 *    test_real_lot_rates_flow_through_from_the_grn_and_a_later_cost_version_supersedes_them:
 *    a genuine receipt rate reaches a batch, a genuine cost version
 *    supersedes it for the NEXT batch, and the closed batch is proven not to
 *    have been rewritten. No stub anywhere in that scene.
 *  - The scenes that need no rate at all (FIFO order, carry-over, reversal,
 *    the ack gate, the unpriced opening-stock lot) run against the real
 *    resolver with no stub near them either.
 */
class BagCostTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    private WorkCenter $machine;

    private Item $bottle;

    private Item $resin;

    private Shift $shift;

    private Warehouse $store;

    private Warehouse $dayBin;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal fixtures deliberately carry no weight/cycle-time/Tally
        // identity, so the (separately covered) readiness gate does not
        // stand between these scenes and the thing they are about.
        config()->set('production.readiness.enforced', false);
        config()->set('production.traceability_enabled', true);

        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $this->bottle = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'Nos.']);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs.']);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->store = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store']);
        $this->dayBin = Warehouse::create(['code' => 'WH-DAYBIN', 'name' => 'Factory Day Bin']);

        // Real resin stock to issue against, at a stock average that is
        // deliberately NOT any bag's rate — so a figure that accidentally
        // came from the ledger instead of from a bag is visible on sight.
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '500.0000',
            unitCost: '90.0000',
            reference: 'Opening stock',
            createdBy: null,
        );
    }

    // ------------------------------------------------------------------
    // Fixtures and helpers
    // ------------------------------------------------------------------

    private function actingAsProduction(array $extra = []): User
    {
        $permissions = array_merge(['production.view', 'production.manage', 'inventory.manage'], $extra);
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    /** A registered bag of resin physically sitting in the raw-material store. */
    private function bag(string $barcode, string $kg): MaterialBag
    {
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => '2026-07-25',
            'bag_count' => 1,
            'total_received_kg' => $kg,
        ]);

        return $lot->bags()->create([
            'barcode' => $barcode,
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ]);
    }

    /**
     * Swap the Inventory rate contract for a known one, keyed by lot id.
     * See the class docblock for why the pricing scenes stub this and the
     * arithmetic scenes do not.
     *
     * @param  array<int, array{0: ?string, 1: string}>  $byLotId
     */
    private function stubRates(array $byLotId): void
    {
        $this->app->instance(BagLotRateResolver::class, new class($byLotId) extends BagLotRateResolver
        {
            /** @param array<int, array{0: ?string, 1: string}> $byLotId */
            public function __construct(private readonly array $byLotId) {}

            public function rateFor(?MaterialLot $lot): array
            {
                if ($lot === null || ! array_key_exists($lot->id, $this->byLotId)) {
                    return [null, self::SOURCE_UNKNOWN];
                }

                return $this->byLotId[$lot->id];
            }
        });
    }

    private function startBatch(): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->store->id,
        ])->assertOk()->json('data.id');
    }

    /** Scan a bag into the machine through the per-machine day-bin path. */
    private function loadBag(MaterialBag $bag, int $entryId, string $kg): void
    {
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $bag->barcode,
            'work_center_id' => $this->machine->id,
            'shift_production_entry_id' => $entryId,
            'quantity_kg' => $kg,
        ])->assertSuccessful();
    }

    /** @param array<int, array{item_id: int, quantity_issued_kg: string}> $consumptions */
    private function complete(int $entryId, string $produced, array $consumptions): void
    {
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => $produced,
            'material_consumptions' => array_map(
                fn (array $line) => [...$line, 'warehouse_id' => $this->store->id],
                $consumptions,
            ),
        ])->assertOk();
    }

    /** @return Collection<int, BatchResinAllocation> */
    private function liveAllocations(int $entryId)
    {
        return BatchResinAllocation::query()
            ->where('shift_production_entry_id', $entryId)
            ->whereNull('reversed_at')
            ->orderBy('id')
            ->get();
    }

    // ==================================================================
    // 1. FIFO ACROSS A BAG BOUNDARY, AND THE CARRY-OVER
    // ==================================================================

    public function test_a_batch_draws_the_oldest_bag_first_and_the_part_used_bag_carries_to_the_next_batch(): void
    {
        $this->actingAsProduction();

        $bagA = $this->bag('LOT-A-B1', '25.0000');
        $bagB = $this->bag('LOT-B-B1', '25.0000');

        // Two bags bought at two different rates — the whole point of
        // costing off bags rather than off a blended average.
        $this->stubRates([
            $bagA->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
            $bagB->material_lot_id => ['120.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
        ]);

        // ---- Batch 1: burns 30 kg, which is more than bag A held. -------
        $entryOne = $this->startBatch();
        $this->loadBag($bagA, $entryOne, '25');
        $this->loadBag($bagB, $entryOne, '25');

        $this->complete($entryOne, '6000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '30.0000'],
        ]);

        $rows = $this->liveAllocations($entryOne);

        // TWO layers, oldest first: bag A to exhaustion, then bag B for the
        // remainder — each at its OWN rate, neither at the 90.00 stock
        // average sitting in the ledger beside them.
        $this->assertCount(2, $rows);

        $this->assertSame($bagA->id, $rows[0]->material_bag_id);
        $this->assertSame('25.0000', $rows[0]->quantity_kg);
        $this->assertSame('100.0000', $rows[0]->rate_per_kg);
        $this->assertSame('2500.0000', $rows[0]->amount);
        $this->assertSame(BagLotRateResolver::SOURCE_BAG_RECEIPT, $rows[0]->rate_source);

        $this->assertSame($bagB->id, $rows[1]->material_bag_id);
        $this->assertSame('5.0000', $rows[1]->quantity_kg);
        $this->assertSame('120.0000', $rows[1]->rate_per_kg);
        $this->assertSame('600.0000', $rows[1]->amount);

        // Both rows are run 1 and both point at a real Load layer — no
        // fallback row, because the scans covered the whole consumption.
        $this->assertSame([1, 1], $rows->pluck('allocation_run')->all());
        $this->assertNotContains(null, $rows->pluck('day_bin_movement_id')->all());

        $summaryOne = app(BagCostAllocationService::class)->summary(ShiftProductionEntry::find($entryOne));
        $this->assertSame('3100.0000', $summaryOne['resin_cost']);

        // ---- Batch 2: the part-used bag B is still standing there. ------
        // Nothing carried anything forward. Bag B's layer simply still has
        // 20 kg un-allocated against it, and the next batch finds it.
        $entryTwo = $this->startBatch();

        $this->complete($entryTwo, '2000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '10.0000'],
        ]);

        $rowsTwo = $this->liveAllocations($entryTwo);

        $this->assertCount(1, $rowsTwo);
        $this->assertSame($bagB->id, $rowsTwo[0]->material_bag_id);
        $this->assertSame('10.0000', $rowsTwo[0]->quantity_kg);
        // AT BAG B'S RATE, not bag A's and not the average — the carry-over
        // kept its identity across a batch boundary.
        $this->assertSame('120.0000', $rowsTwo[0]->rate_per_kg);
        $this->assertSame('1200.0000', $rowsTwo[0]->amount);
        $this->assertSame($rows[1]->day_bin_movement_id, $rowsTwo[0]->day_bin_movement_id);
    }

    public function test_material_returned_out_of_the_machine_stops_being_drawable_from_its_layer(): void
    {
        $this->actingAsProduction();

        $bagA = $this->bag('LOT-A-B1', '25.0000');
        $this->stubRates([$bagA->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $entry = $this->startBatch();
        $this->loadBag($bagA, $entry, '25');

        // 10 kg goes BACK to the store. Only 15 kg is standing at the
        // machine, and the bag is in the store holding those 10 kg again.
        $this->postJson('/api/v1/production/day-bin/return', [
            'work_center_id' => $this->machine->id,
            'item_id' => $this->resin->id,
            'quantity_kg' => '10.0000',
            'material_bag_id' => $bagA->id,
            'shift_production_entry_id' => $entry,
        ])->assertSuccessful();

        $this->assertSame('10.0000', (string) $bagA->fresh()->remaining_kg);

        // The batch nevertheless claims 25 kg — 10 more than was there.
        $this->complete($entry, '5000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '25.0000'],
        ]);

        $rows = $this->liveAllocations($entry);

        // TWO rows, not one. The layer gives up only the 15 kg that stayed.
        $this->assertCount(2, $rows);

        $this->assertSame($bagA->id, $rows[0]->material_bag_id);
        $this->assertSame('15.0000', $rows[0]->quantity_kg);
        $this->assertSame('100.0000', $rows[0]->rate_per_kg);
        $this->assertSame('1500.0000', $rows[0]->amount);
        $this->assertSame(BagLotRateResolver::SOURCE_BAG_RECEIPT, $rows[0]->rate_source);

        // AND THE ALARM STILL RINGS. The 10 kg the scans cannot account for
        // falls to the beyond-layers fallback and SAYS SO — the whole point
        // of the row. Priced at a bag rate it would have looked trustworthy.
        $this->assertNull($rows[1]->day_bin_movement_id);
        $this->assertNull($rows[1]->material_bag_id);
        $this->assertSame('10.0000', $rows[1]->quantity_kg);
        $this->assertSame(BagLotRateResolver::SOURCE_AVERAGE_FALLBACK, $rows[1]->rate_source);

        // The returned kilograms are not chargeable here a second time
        // either: the layer is spent, so a later batch finds nothing left.
        $entryTwo = $this->startBatch();
        $this->complete($entryTwo, '1000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '5.0000'],
        ]);

        $rowsTwo = $this->liveAllocations($entryTwo);
        $this->assertCount(1, $rowsTwo);
        $this->assertNull($rowsTwo[0]->day_bin_movement_id);
        $this->assertSame(BagLotRateResolver::SOURCE_AVERAGE_FALLBACK, $rowsTwo[0]->rate_source);
    }

    // ==================================================================
    // 2. A CORRECTION REPLACES THE ALLOCATION
    // ==================================================================

    public function test_an_amendment_reverses_the_first_run_and_replaces_it_leaving_the_layers_untouched_by_the_mistake(): void
    {
        $this->actingAsProduction();

        $bagA = $this->bag('LOT-A-B1', '25.0000');
        $bagB = $this->bag('LOT-B-B1', '25.0000');

        $this->stubRates([
            $bagA->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
            $bagB->material_lot_id => ['120.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
        ]);

        $entry = $this->startBatch();
        $this->loadBag($bagA, $entry, '25');
        $this->loadBag($bagB, $entry, '25');

        // The wrong completion: 40 kg.
        $this->complete($entry, '6000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '40.0000'],
        ]);

        $firstRun = $this->liveAllocations($entry);
        $this->assertSame('40.0000', $firstRun->reduce(
            fn (string $carry, BatchResinAllocation $row) => bcadd($carry, (string) $row->quantity_kg, 4),
            '0.0000',
        ));

        // The correction: it was really 30 kg (and 5,000 pieces).
        $this->postJson("/api/v1/production/shift-production-entries/{$entry}/amend", [
            'quantity_produced' => '5000',
            'amendment_reason' => 'Recounted the trays; the resin figure was the previous shift\'s.',
            'material_consumptions' => [[
                'item_id' => $this->resin->id,
                'quantity_issued_kg' => '30.0000',
                'warehouse_id' => $this->store->id,
            ]],
        ])->assertOk();

        // NOTHING WAS DELETED. Run 1's rows are all still there, every one
        // of them stamped reversed_at — that is the audit trail the owner
        // asked for ("auditable reversal + reapplication").
        $reversed = BatchResinAllocation::query()
            ->where('shift_production_entry_id', $entry)
            ->whereNotNull('reversed_at')
            ->get();

        $this->assertCount($firstRun->count(), $reversed);
        $this->assertSame([1], $reversed->pluck('allocation_run')->unique()->all());

        // Run 2 replaced it, and it is the ONLY live run.
        $liveNow = $this->liveAllocations($entry);
        $this->assertSame([2], $liveNow->pluck('allocation_run')->unique()->all());

        // THE LAYERS ARE AS IF THE MISTAKE NEVER HAPPENED: bag A drawn to
        // its full 25, bag B drawn 5 — byte for byte the never-wrong world
        // asserted in scene 1.
        $this->assertCount(2, $liveNow);
        $this->assertSame($bagA->id, $liveNow[0]->material_bag_id);
        $this->assertSame('25.0000', $liveNow[0]->quantity_kg);
        $this->assertSame($bagB->id, $liveNow[1]->material_bag_id);
        $this->assertSame('5.0000', $liveNow[1]->quantity_kg);
        $this->assertSame('3100.0000', app(BagCostAllocationService::class)
            ->summary(ShiftProductionEntry::find($entry))['resin_cost']);

        // And the proof that reversal really did give the kilograms back:
        // the next batch finds bag B holding 20 kg, not 10.
        $entryTwo = $this->startBatch();
        $this->complete($entryTwo, '2000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        $rowsTwo = $this->liveAllocations($entryTwo);
        $this->assertCount(1, $rowsTwo);
        $this->assertSame($bagB->id, $rowsTwo[0]->material_bag_id);
        $this->assertSame('20.0000', $rowsTwo[0]->quantity_kg);
    }

    // ==================================================================
    // 3. NEVER TWO LIVE RUNS
    // ==================================================================

    public function test_a_second_live_allocation_run_is_refused_outright(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');
        $this->stubRates([$bag->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $entry = $this->startBatch();
        $this->loadBag($bag, $entry, '25');
        $this->complete($entry, '4000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        $allocator = app(BagCostAllocationService::class);
        $model = ShiftProductionEntry::find($entry);

        // Allocating again without reversing first would charge this batch
        // for its resin twice, and every total downstream would be wrong
        // with nothing anywhere saying so. It is refused instead.
        $this->expectException(DuplicateAllocationException::class);

        try {
            $allocator->allocate($model, null);
        } finally {
            // Exactly one live run survives the attempt.
            $this->assertSame([1], $this->liveAllocations($entry)->pluck('allocation_run')->unique()->all());
        }
    }

    // ==================================================================
    // 4. BEYOND THE LAYERS
    // ==================================================================

    public function test_consumption_beyond_every_scanned_layer_falls_back_to_the_stock_average_and_says_so(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');
        $this->stubRates([$bag->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $entry = $this->startBatch();
        $this->loadBag($bag, $entry, '25');

        // 40 kg burnt, 25 kg ever scanned. The machine ran on material
        // nobody logged — which is exactly the case this must not hide.
        $this->complete($entry, '8000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '40.0000'],
        ]);

        $rows = $this->liveAllocations($entry);
        $this->assertCount(2, $rows);

        $this->assertSame($bag->id, $rows[0]->material_bag_id);
        $this->assertSame('25.0000', $rows[0]->quantity_kg);
        $this->assertSame('100.0000', $rows[0]->rate_per_kg);

        // ONE fallback row for the unaccounted 15 kg, with no bag, no lot
        // and no layer behind it — and rate_source that admits as much
        // rather than dressing the stock average up as a purchase rate.
        $this->assertNull($rows[1]->day_bin_movement_id);
        $this->assertNull($rows[1]->material_bag_id);
        $this->assertNull($rows[1]->material_lot_id);
        $this->assertSame('15.0000', $rows[1]->quantity_kg);
        $this->assertSame(BagLotRateResolver::SOURCE_AVERAGE_FALLBACK, $rows[1]->rate_source);
        $this->assertSame('90.0000', $rows[1]->rate_per_kg);
        $this->assertSame('1350.0000', $rows[1]->amount);

        $this->assertSame('3850.0000', app(BagCostAllocationService::class)
            ->summary(ShiftProductionEntry::find($entry))['resin_cost']);
    }

    // ==================================================================
    // 5. NO PRICE IS EVER INVENTED
    // ==================================================================

    public function test_a_bag_whose_lot_has_no_recorded_rate_is_left_unpriced_rather_than_guessed(): void
    {
        $this->actingAsProduction();

        // No stub at all: the real resolver, against a lot with no cost
        // source — an opening-stock bag, which is the commonest bag in the
        // building on the day this ships.
        $bag = $this->bag('LOT-A-B1', '25.0000');

        $entry = $this->startBatch();
        $this->loadBag($bag, $entry, '25');
        $this->complete($entry, '4000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        $rows = $this->liveAllocations($entry);
        $this->assertCount(1, $rows);

        // The QUANTITY is known and recorded — it is only the price that is
        // not, and the row says exactly that.
        $this->assertSame('20.0000', $rows[0]->quantity_kg);
        $this->assertSame($bag->id, $rows[0]->material_bag_id);
        $this->assertNull($rows[0]->rate_per_kg);
        $this->assertNull($rows[0]->amount);
        $this->assertSame(BagLotRateResolver::SOURCE_UNKNOWN, $rows[0]->rate_source);

        // And the batch total refuses to be a confident understatement: it
        // is null, with words saying why, rather than 0 or a partial sum.
        $summary = app(BagCostAllocationService::class)->summary(ShiftProductionEntry::find($entry));

        $this->assertNull($summary['resin_cost']);
        $this->assertNull($summary['material_cost_total']);
        $this->assertNull($summary['cost_per_accepted_unit']);
        $this->assertStringContainsString('no recorded purchase rate', (string) $summary['reason']);
    }

    // ==================================================================
    // 6. THE SCAN ACKNOWLEDGEMENT — a question, never a weighing
    // ==================================================================

    public function test_topping_up_a_machine_that_still_shows_material_is_refused_until_one_word_explains_why(): void
    {
        $this->actingAsProduction();
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        $first = $this->bag('LOT-A-B1', '25.0000');
        $second = $this->bag('LOT-B-B1', '25.0000');

        // The FIRST scan is never gated: before it there is no baseline, so
        // there is no figure to exceed the threshold and nobody is asked to
        // explain a machine the system has never seen loaded.
        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $first->barcode,
            'work_center_id' => $this->machine->id,
        ])->assertOk();

        $this->assertNull(DayBinMovement::query()->latest('id')->first()->balance_ack_reason);

        // Now the machine is estimated to hold 25 kg and somebody scans
        // another bag into it. Refused — with the figure named and the
        // choices offered.
        $refused = $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $second->barcode,
            'work_center_id' => $this->machine->id,
        ])->assertStatus(422);

        $message = $refused->json('errors.balance_ack_reason.0');
        $this->assertStringContainsString('25', (string) $message);
        $this->assertStringContainsString('confirm_extra', (string) $message);
        $this->assertStringContainsString('return_to_store', (string) $message);

        // IT ASKED FOR A REASON, NOT FOR A WEIGHT — no routine day-bin
        // weighing exists in this factory and this gate introduces none.
        $this->assertStringNotContainsStringIgnoringCase('weigh', (string) $message);

        // AND THE REFUSAL MOVED NOTHING: the bag is untouched and no second
        // load row exists.
        $this->assertSame('25.0000', $second->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $second->fresh()->status);
        $this->assertSame(1, DayBinMovement::query()->where('type', DayBinMovementType::Load->value)->count());

        // With the word, it goes through — and the word is recorded on the
        // load row itself, where every estimate and allocation can see it.
        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $second->barcode,
            'work_center_id' => $this->machine->id,
            'balance_ack_reason' => 'confirm_extra',
            'balance_ack_note' => 'Hopper genuinely still part full.',
        ])->assertOk();

        $recorded = DayBinMovement::query()->latest('id')->first();
        $this->assertSame('confirm_extra', $recorded->balance_ack_reason);
        $this->assertSame('Hopper genuinely still part full.', $recorded->balance_ack_note);
        $this->assertSame('0.0000', $second->fresh()->remaining_kg);
    }

    public function test_a_scan_below_the_threshold_is_never_asked_to_explain_itself(): void
    {
        $this->actingAsProduction();
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        // A nearly-empty machine: 3 kg estimated remaining, under the 5 kg
        // threshold. The ordinary scan must stay one tap — a gate that
        // fires on every load is a gate operators learn to dismiss.
        $first = $this->bag('LOT-A-B1', '3.0000');
        $second = $this->bag('LOT-B-B1', '25.0000');

        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $first->barcode,
            'work_center_id' => $this->machine->id,
        ])->assertOk();

        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $second->barcode,
            'work_center_id' => $this->machine->id,
        ])->assertOk();

        $this->assertSame(2, DayBinMovement::query()->where('type', DayBinMovementType::Load->value)->count());
        $this->assertNull(DayBinMovement::query()->latest('id')->first()->balance_ack_reason);
    }

    // ==================================================================
    // 7 & 8. WHAT THE MONEY READS, AND WHO MAY SEE THE RATES
    // ==================================================================

    public function test_batch_cost_splits_resin_from_other_material_and_costs_each_accepted_piece_after_quality_nets_it(): void
    {
        $this->actingAsProduction();

        $masterbatch = Item::create(['sku' => 'RM-MB', 'name' => 'Blue Masterbatch', 'uom' => 'Kgs.']);
        app(StockMovementService::class)->recordReceipt(
            itemId: $masterbatch->id,
            warehouseId: $this->store->id,
            quantity: '100.0000',
            unitCost: '250.0000',
            reference: 'Opening stock',
            createdBy: null,
        );

        $bag = $this->bag('LOT-A-B1', '25.0000');
        $this->stubRates([$bag->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $entry = $this->startBatch();
        $this->loadBag($bag, $entry, '25');

        // Resin was scanned; masterbatch was not. The scan trail alone
        // decides which bucket each line lands in — no name is matched
        // anywhere, which is the rule this module holds to.
        $this->complete($entry, '10000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
            ['item_id' => $masterbatch->id, 'quantity_issued_kg' => '2.0000'],
        ]);

        $allocator = app(BagCostAllocationService::class);
        $summary = $allocator->summary(ShiftProductionEntry::find($entry));

        // Resin off its bag (20 × 100), masterbatch at its issue cost
        // (2 × 250) — and the two buckets sum to the total with no line
        // counted twice and none dropped.
        $this->assertSame('2000.0000', $summary['resin_cost']);
        $this->assertSame('500.0000', $summary['other_cost']);
        $this->assertSame('2500.0000', $summary['material_cost_total']);
        $this->assertSame(
            $summary['material_cost_total'],
            bcadd((string) $summary['resin_cost'], (string) $summary['other_cost'], 4),
        );

        // 2500 ÷ 10,000 pieces.
        $this->assertSame('0.2500', $summary['cost_per_accepted_unit']);
        $this->assertNull($summary['reason']);

        // ---- Quality nets the batch: 500 pieces rejected. ---------------
        $qc = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('quality.manage', 'web');
        $qc->givePermissionTo('quality.manage');
        Sanctum::actingAs($qc);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry}/quality-check", [
            'reviewed_nos' => 10000,
            'ok_nos' => 9500,
            'rejected_nos' => 500,
        ])->assertOk();

        // THE COST PER PIECE ROSE, and it should have: the rejected pieces
        // did not stop costing what they cost, so the accepted ones carry
        // the whole bill. 2500 ÷ 9,500 = 0.263157…, truncated at 4 dp by
        // plain bcdiv — the module's convention everywhere (see
        // BatchEstimationService, CapacityPlanService), and a tenth of a
        // paisa per bottle is not a figure worth a second rounding regime.
        $netted = $allocator->summary(ShiftProductionEntry::find($entry));

        $this->assertSame('9500', $netted['accepted_quantity']);
        $this->assertSame('0.2631', $netted['cost_per_accepted_unit']);
        $this->assertSame('2500.0000', $netted['material_cost_total']);
    }

    public function test_rates_bags_and_supplier_lots_are_finance_only_while_the_totals_are_everyones(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');
        $this->stubRates([$bag->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $entry = $this->startBatch();
        $this->loadBag($bag, $entry, '25');
        $this->complete($entry, '10000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        // ---- The production login: totals, yes. Rates, no. --------------
        $asProduction = $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->json('data.0.batch_cost');

        $this->assertSame('2000.0000', $asProduction['material_cost_total']);
        $this->assertSame('0.2000', $asProduction['cost_per_accepted_unit']);

        // ABSENT, not null — a supplier rate must never be one devtools
        // panel away from a production login.
        $this->assertArrayNotHasKey('layers', $asProduction);
        $this->assertArrayNotHasKey('other_lines', $asProduction);

        // ---- Finance: the same totals, plus what is behind them. --------
        $this->actingAsProduction(['finance.view']);

        $asFinance = $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->json('data.0.batch_cost');

        $this->assertSame('2000.0000', $asFinance['material_cost_total']);
        $this->assertArrayHasKey('layers', $asFinance);

        $this->assertCount(1, $asFinance['layers']);
        $this->assertSame('LOT-A-B1', $asFinance['layers'][0]['bag_barcode']);
        $this->assertSame('100.0000', $asFinance['layers'][0]['rate_per_kg']);
        $this->assertSame('20.0000', $asFinance['layers'][0]['quantity_kg']);
        $this->assertSame(BagLotRateResolver::SOURCE_BAG_RECEIPT, $asFinance['layers'][0]['rate_source']);
    }

    // ==================================================================
    // THE REAL CONTRACT, END TO END — no stub anywhere in this scene
    // ==================================================================

    public function test_real_lot_rates_flow_through_from_the_grn_and_a_later_cost_version_supersedes_them(): void
    {
        $this->actingAsProduction();

        // A lot that arrived with a real purchase rate on it — Inventory's
        // own column, through Inventory's own contract methods. The stub
        // used elsewhere in this file is deliberately absent here: this is
        // the scene that proves the seam is wired to the real thing.
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => '2026-07-25',
            'bag_count' => 1,
            'total_received_kg' => '25.0000',
            'receipt_rate_per_kg' => '104.5000',
        ]);

        $bag = $lot->bags()->create([
            'barcode' => 'LOT-REAL-B1',
            'original_kg' => '25.0000',
            'remaining_kg' => '25.0000',
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ]);

        $entry = $this->startBatch();
        $this->loadBag($bag, $entry, '25');
        $this->complete($entry, '4000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        $row = $this->liveAllocations($entry)->first();

        // The GRN rate reached the batch untouched, and is named as such.
        $this->assertSame('104.5000', $row->rate_per_kg);
        $this->assertSame('2090.0000', $row->amount);
        $this->assertSame(BagLotRateResolver::SOURCE_BAG_RECEIPT, $row->rate_source);

        // ---- The purchase invoice lands, revising the rate upward. ------
        app(MaterialCostVersionService::class)->append(
            $lot,
            ['rate_per_kg' => '111.0000', 'kind' => 'invoice', 'note' => 'Supplier invoice 8841.'],
            null,
        );

        // THE CLOSED BATCH IS NOT REWRITTEN. Its rate was frozen at
        // allocation time, which is the whole rule about provisional GRN
        // costs: a revision reaches FUTURE batches, it never silently edits
        // a figure somebody has already signed off.
        $this->assertSame('104.5000', $row->fresh()->rate_per_kg);
        $this->assertSame('2090.0000', $row->fresh()->amount);

        // The next batch draws the same bag's remaining 5 kg at the REVISED
        // rate, and says it is a revision rather than a receipt.
        $entryTwo = $this->startBatch();
        $this->complete($entryTwo, '1000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '5.0000'],
        ]);

        $rowTwo = $this->liveAllocations($entryTwo)->first();

        $this->assertSame('5.0000', $rowTwo->quantity_kg);
        $this->assertSame('111.0000', $rowTwo->rate_per_kg);
        $this->assertSame('555.0000', $rowTwo->amount);
        $this->assertSame(BagLotRateResolver::SOURCE_BAG_VERSION, $rowTwo->rate_source);
    }

    // ==================================================================
    // THE LEDGER IS NOT TOUCHED — the ruling this whole layer rests on
    // ==================================================================

    public function test_allocating_bag_costs_leaves_the_stock_ledgers_own_valuation_exactly_as_it_was(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');
        // A bag rate deliberately far from the 90.00 stock average: if any
        // of this leaked into the ledger, this assertion is where it shows.
        $this->stubRates([$bag->material_lot_id => ['400.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $entry = $this->startBatch();
        $this->loadBag($bag, $entry, '25');

        $averageBefore = StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->store->id)
            ->value('average_cost');

        $this->complete($entry, '4000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        $averageAfter = StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->store->id)
            ->value('average_cost');

        // The Accounts-approved moving average is untouched — bag rates are
        // an analytic layer BESIDE the ledger and never inside it, and they
        // never reach Tally at all.
        $this->assertSame($averageBefore, $averageAfter);
        $this->assertSame('90.0000', (string) $averageAfter);

        // While the bag layer, beside it, priced the batch at the bag rate.
        $this->assertSame('8000.0000', app(BagCostAllocationService::class)
            ->summary(ShiftProductionEntry::find($entry))['resin_cost']);
    }
}
