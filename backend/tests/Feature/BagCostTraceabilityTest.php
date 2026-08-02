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
use App\Modules\Production\Models\ResinPoolBalance;
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
 * RESIN COSTING AT THE COMMON INPUT — the story after the owner's correction
 * (2-Aug), told end to end: what did this batch's resin cost, on what basis,
 * and what is that basis honestly worth.
 *
 * WHAT THE CORRECTION CHANGED. The factory has ONE COMMON resin input point
 * serving every machine. A bag is never assigned or scanned to a machine, so
 * "which bag did this batch burn" has no physical answer and this module
 * stopped answering it. Per-machine layers, per-machine bag history and
 * per-machine estimated balance are gone. What replaced them is a WEIGHTED
 * AVERAGE PER EXACT MATERIAL — an accounting allocation, labelled as one
 * everywhere it appears.
 *
 * WHAT MUST HOLD, and each one is a scene below:
 *
 *  1. THE POOL IS A WEIGHTED AVERAGE. Two bags at 92 and 118 make a pool at
 *     105, and a batch draws at 105 — not at either bag's own price, because
 *     no bag is that batch's bag.
 *  2. A PARTIAL LOAD FOLDS THE KG IT ACTUALLY POURED, and the bag keeps its
 *     remainder in the store.
 *  3. NO PRICE IS EVER INVENTED. A rateless lot lands in unpriced_kg and does
 *     NOT move the average — averaging in a rate nobody knows would price
 *     that material at whatever the rest of the pool happened to cost.
 *  4. A CORRECTION REPLACES, IT DOES NOT ACCUMULATE. An amendment reverses
 *     the run (stamped, never deleted), gives the kilograms back AT THEIR OWN
 *     FROZEN RATE, and re-draws — leaving the pool byte-for-byte where a
 *     never-wrong world would have left it.
 *  5. BEYOND THE POOL IS ADMITTED, NOT HIDDEN. Consumption the loads cannot
 *     account for falls back to the stock average and says so in rate_source.
 *  6. THE LOAD GATE ASKS ONE QUESTION, ABOUT THE COMMON INPUT, and its 422
 *     says the figure is an estimate that does not count running batches.
 *     There is NO running-batch exemption any more — with one shared input
 *     some batch is always running, so the old exemption would have silenced
 *     the gate permanently.
 *  7. A LOAD CARRIES NO MACHINE. Not on the ledger row, not on the bag.
 *  8. IDENTITY SURVIVES. Lot, permanent barcode, original rate, quantity and
 *     partial-bag balance are all preserved — it is the CLAIM built on top of
 *     them that was removed, not the data.
 *  9. THE MONEY SAYS WHAT IT IS. batch_cost carries the accounting-allocation
 *     sentence for everyone, and carries NO bag or supplier identity for
 *     anyone.
 * 10. NEVER TWICE, and the stock ledger is never touched.
 *
 * WHERE THE RATES COME FROM IN EACH SCENE. Inventory owns the rate contract
 * (MaterialLot::currentRatePerKg()/receiptRatePerKg()/hasRevisions()) and
 * BagLotRateResolver is the seam Production adapts it through — now read at
 * LOAD time, when a bag's kg enter the pool, rather than at allocation time.
 *
 *  - The multi-rate scenes stub that seam, keyed by lot id, because what they
 *    exist to assert is the POOL ARITHMETIC and two known rates state it far
 *    more plainly than two lots' worth of GRN fixtures would.
 *  - THE CONTRACT ITSELF IS EXERCISED FOR REAL in
 *    test_real_lot_rates_reach_the_pool_and_a_later_cost_version_reaches_it_with_the_next_load.
 *    No stub anywhere in that scene.
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

        // The common input needs a WIP warehouse named before a bag can be
        // loaded into it — the load is still the ordinary store → WIP stock
        // transfer it has always been.
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        // Real resin stock to issue against, at a stock average that is
        // deliberately NOT any bag's rate and NOT any pool average — so a
        // figure that accidentally came from the ledger instead of from the
        // pool is visible on sight.
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
    private function bag(string $barcode, string $kg, ?MaterialLot $lot = null): MaterialBag
    {
        $lot ??= MaterialLot::create([
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
            /** @param  array<int, array{0: ?string, 1: string}>  $byLotId */
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

    /**
     * Load a bag at the COMMON INPUT. No machine is passed, because there is
     * no machine to pass.
     *
     * $ack is the acknowledgement the gate demands once the common input is
     * already estimated to hold this material — every scene that loads a
     * second bag of one material has to answer it, which is the gate working.
     */
    private function loadBag(MaterialBag $bag, ?string $kg = null, ?string $ack = null): void
    {
        $this->postJson('/api/v1/production/day-bin/load-bag', array_filter([
            'barcode' => $bag->barcode,
            'quantity_kg' => $kg,
            'balance_ack_reason' => $ack,
        ], fn ($value) => $value !== null))->assertSuccessful();
    }

    /** @param  array<int, array{item_id: int, quantity_issued_kg: string}>  $consumptions */
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

    private function pool(): ResinPoolBalance
    {
        return ResinPoolBalance::query()->where('item_id', $this->resin->id)->firstOrFail();
    }

    // ==================================================================
    // 1. THE POOL IS A WEIGHTED AVERAGE, AND THE BATCH DRAWS AT IT
    // ==================================================================

    public function test_two_bags_at_different_rates_make_one_weighted_average_the_batch_draws_at(): void
    {
        $this->actingAsProduction();

        $cheap = $this->bag('LOT-A-B1', '25.0000');
        $dear = $this->bag('LOT-B-B1', '25.0000');

        $this->stubRates([
            $cheap->material_lot_id => ['92.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
            $dear->material_lot_id => ['118.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
        ]);

        $this->loadBag($cheap);
        // The second bag of the same material is gated — 25 kg are still
        // estimated to be standing in the common input. That is scene 6's
        // subject; here it is simply answered.
        $this->loadBag($dear, ack: 'confirm_extra');

        // 50 kg at (25×92 + 25×118) ÷ 50 = 105. Neither bag's own price.
        $pool = $this->pool();
        $this->assertSame('50.0000', $pool->quantity_kg);
        $this->assertSame('105.0000', $pool->avg_rate_per_kg);
        $this->assertSame('0.0000', $pool->unpriced_kg);

        $entry = $this->startBatch();
        $this->complete($entry, '4000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        // ONE ROW FOR THE RUN, not one per bag — there are no bag layers to
        // walk any more, and no bag identity on it to walk to.
        $rows = $this->liveAllocations($entry);
        $this->assertCount(1, $rows);
        $this->assertSame('20.0000', $rows[0]->quantity_kg);
        $this->assertSame('105.0000', $rows[0]->rate_per_kg);
        $this->assertSame('2100.0000', $rows[0]->amount);
        $this->assertSame(BagCostAllocationService::SOURCE_POOL_AVERAGE, $rows[0]->rate_source);
        $this->assertNull($rows[0]->material_bag_id);
        $this->assertNull($rows[0]->material_lot_id);
        $this->assertNull($rows[0]->day_bin_movement_id);

        // The pool is 20 kg lighter and STILL AT 105 — drawing kg out of a
        // pool does not change what the remaining kg cost.
        $this->assertSame('30.0000', $this->pool()->quantity_kg);
        $this->assertSame('105.0000', $this->pool()->avg_rate_per_kg);

        // The next batch draws the same average, with nobody carrying
        // anything forward by hand.
        $entryTwo = $this->startBatch();
        $this->complete($entryTwo, '2000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '10.0000'],
        ]);

        $this->assertSame('105.0000', $this->liveAllocations($entryTwo)->first()->rate_per_kg);
        $this->assertSame('20.0000', $this->pool()->quantity_kg);
    }

    // ==================================================================
    // 2. A PARTIAL LOAD FOLDS ONLY THE KG IT POURED
    // ==================================================================

    public function test_a_partial_bag_load_folds_the_poured_kg_and_leaves_the_rest_in_the_bag(): void
    {
        $this->actingAsProduction();

        $first = $this->bag('LOT-A-B1', '25.0000');
        $second = $this->bag('LOT-B-B1', '25.0000');

        $this->stubRates([
            $first->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
            $second->material_lot_id => ['150.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
        ]);

        $this->loadBag($first);
        // Only 10 of the second bag's 25 kg are poured in.
        $this->loadBag($second, '10', ack: 'confirm_extra');

        // 35 kg at (25×100 + 10×150) ÷ 35 = 4000 ÷ 35 = 114.2857…, truncated
        // at 4 dp by plain bcdiv — the module's convention everywhere.
        $pool = $this->pool();
        $this->assertSame('35.0000', $pool->quantity_kg);
        $this->assertSame('114.2857', $pool->avg_rate_per_kg);

        // THE BAG KEPT ITS REMAINDER, in the store, still loadable — the
        // partial-bag balance the correction explicitly preserved.
        $second->refresh();
        $this->assertSame('15.0000', $second->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $second->status);
        $this->assertSame($this->store->id, $second->current_warehouse_id);

        // The fully poured bag is empty and consumed.
        $first->refresh();
        $this->assertSame('0.0000', $first->remaining_kg);
        $this->assertSame(MaterialBagStatus::Consumed, $first->status);
    }

    // ==================================================================
    // 3. A RATELESS LOT IS COUNTED, NEVER PRICED
    // ==================================================================

    public function test_a_lot_with_no_recorded_rate_lands_in_unpriced_kg_and_does_not_move_the_average(): void
    {
        $this->actingAsProduction();

        $priced = $this->bag('LOT-A-B1', '25.0000');
        // Opening stock: a real lot, with no cost source anywhere behind it.
        $openingStock = $this->bag('LOT-OPENING-B1', '25.0000');

        $this->stubRates([
            $priced->material_lot_id => ['120.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
            // The rateless lot is simply absent from the stub, which is
            // exactly what the real resolver answers for it: [null, unknown].
        ]);

        $this->loadBag($priced);
        $this->loadBag($openingStock, ack: 'confirm_extra');

        $pool = $this->pool();

        // THE AVERAGE DID NOT MOVE. Folding 25 unpriced kg in at 120 would
        // have claimed a price for material nobody has a price for; folding
        // them in at 0 would have claimed it was free. Neither is true, so
        // they are counted apart.
        $this->assertSame('25.0000', $pool->quantity_kg);
        $this->assertSame('120.0000', $pool->avg_rate_per_kg);
        $this->assertSame('25.0000', $pool->unpriced_kg);

        // Both bags' KILOGRAMS still reached the common input — the estimate
        // is a kg question and is not confused by the missing price.
        $this->assertSame('50.0000', DayBinMovement::query()
            ->where('type', DayBinMovementType::Load->value)
            ->get()
            ->reduce(fn (string $carry, DayBinMovement $row) => bcadd($carry, (string) $row->quantity_kg, 4), '0.0000'));
    }

    // ==================================================================
    // 4. A CORRECTION REPLACES, AND THE POOL ENDS WHERE IT SHOULD
    // ==================================================================

    public function test_an_amendment_gives_the_kilograms_back_at_their_own_rate_and_redraws(): void
    {
        $this->actingAsProduction();

        $bagA = $this->bag('LOT-A-B1', '25.0000');
        $bagB = $this->bag('LOT-B-B1', '25.0000');

        $this->stubRates([
            $bagA->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
            $bagB->material_lot_id => ['120.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT],
        ]);

        $this->loadBag($bagA);
        $this->loadBag($bagB, ack: 'confirm_extra');

        // 50 kg at 110.
        $this->assertSame('110.0000', $this->pool()->avg_rate_per_kg);

        $entry = $this->startBatch();

        // The wrong completion: 40 kg.
        $this->complete($entry, '6000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '40.0000'],
        ]);

        $this->assertSame('10.0000', $this->pool()->quantity_kg);

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

        // NOTHING WAS DELETED. Run 1's rows are all still there, stamped
        // reversed_at — the audit trail the owner asked for.
        $reversed = BatchResinAllocation::query()
            ->where('shift_production_entry_id', $entry)
            ->whereNotNull('reversed_at')
            ->get();

        $this->assertCount(1, $reversed);
        $this->assertSame([1], $reversed->pluck('allocation_run')->unique()->all());
        $this->assertSame('40.0000', $reversed[0]->quantity_kg);

        // Run 2 replaced it, and it is the ONLY live run.
        $live = $this->liveAllocations($entry);
        $this->assertSame([2], $live->pluck('allocation_run')->unique()->all());
        $this->assertCount(1, $live);
        $this->assertSame('30.0000', $live[0]->quantity_kg);
        $this->assertSame('110.0000', $live[0]->rate_per_kg);
        $this->assertSame('3300.0000', $live[0]->amount);

        // THE POOL IS EXACTLY THE NEVER-WRONG WORLD: 50 loaded, 30 drawn,
        // 20 left at 110. The 40 kg went back at THEIR OWN rate (110), not
        // at whatever the pool happened to average afterwards — which is why
        // the average survives the round trip untouched.
        $this->assertSame('20.0000', $this->pool()->quantity_kg);
        $this->assertSame('110.0000', $this->pool()->avg_rate_per_kg);

        $this->assertSame('3300.0000', app(BagCostAllocationService::class)
            ->summary(ShiftProductionEntry::find($entry))['resin_cost']);
    }

    // ==================================================================
    // 5. BEYOND THE POOL IS ADMITTED, NOT HIDDEN
    // ==================================================================

    public function test_consumption_beyond_the_pool_falls_back_to_the_stock_average_and_says_so(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');
        $this->stubRates([$bag->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $this->loadBag($bag);

        $entry = $this->startBatch();

        // 40 kg burnt against 25 kg ever loaded. The 15 kg gap is the load
        // discipline's own error bar and must be visible, not absorbed.
        $this->complete($entry, '6000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '40.0000'],
        ]);

        $rows = $this->liveAllocations($entry);
        $this->assertCount(2, $rows);

        $this->assertSame('25.0000', $rows[0]->quantity_kg);
        $this->assertSame('100.0000', $rows[0]->rate_per_kg);
        $this->assertSame(BagCostAllocationService::SOURCE_POOL_AVERAGE, $rows[0]->rate_source);

        // The surplus, at the STOCK average (90) and labelled as such.
        $this->assertSame('15.0000', $rows[1]->quantity_kg);
        $this->assertSame('90.0000', $rows[1]->rate_per_kg);
        $this->assertSame(BagLotRateResolver::SOURCE_AVERAGE_FALLBACK, $rows[1]->rate_source);

        // 2500 + 1350.
        $this->assertSame('3850.0000', app(BagCostAllocationService::class)
            ->summary(ShiftProductionEntry::find($entry))['resin_cost']);

        // The pool is empty, not negative — it cannot lend what it never had.
        $this->assertSame('0.0000', $this->pool()->quantity_kg);
    }

    // ==================================================================
    // 6. THE LOAD GATE — ONE QUESTION, ABOUT THE COMMON INPUT
    // ==================================================================

    public function test_loading_more_while_the_common_input_still_shows_material_is_refused_until_one_word_explains_why(): void
    {
        $this->actingAsProduction();

        $first = $this->bag('LOT-A-B1', '25.0000');
        $second = $this->bag('LOT-B-B1', '25.0000');

        // A FIRST-EVER LOAD IS NEVER GATED — there is no baseline to exceed.
        $this->loadBag($first);

        // A BATCH IS RUNNING, AND THE GATE FIRES ANYWAY. Under the
        // per-machine model this was exempt; with one shared input some batch
        // is running almost always, so the exemption would have silenced the
        // gate permanently.
        $this->startBatch();

        $refused = $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $second->barcode,
        ])->assertStatus(422);

        $message = $refused->json('errors.balance_ack_reason.0');

        $this->assertStringContainsString('common resin input', $message);
        $this->assertStringContainsString('25 kg', $message);
        // THE HONEST CAVEAT, in the sentence itself — the figure has not been
        // charged for anything the running batch has already melted.
        $this->assertStringContainsString('estimated, not counting batches still running', $message);

        foreach (FactoryDayBinService::ACK_REASONS as $reason) {
            $this->assertStringContainsString($reason, $message);
        }

        // NOTHING MOVED on the refusal.
        $this->assertSame('25.0000', $second->fresh()->remaining_kg);
        $this->assertSame(1, DayBinMovement::query()->where('type', DayBinMovementType::Load->value)->count());

        // One word, and the scan goes through — recorded ON the load row.
        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $second->barcode,
            'balance_ack_reason' => 'spill',
            'balance_ack_note' => 'Bag split on the way in.',
        ])->assertSuccessful();

        $load = DayBinMovement::query()
            ->where('type', DayBinMovementType::Load->value)
            ->orderByDesc('id')
            ->first();

        $this->assertSame('spill', $load->balance_ack_reason);
        $this->assertSame('Bag split on the way in.', $load->balance_ack_note);
    }

    public function test_a_load_below_the_threshold_is_never_asked_to_explain_itself(): void
    {
        $this->actingAsProduction();

        $first = $this->bag('LOT-A-B1', '25.0000');
        $second = $this->bag('LOT-B-B1', '25.0000');

        $this->loadBag($first);

        // The batch burnt nearly all of it: 23 of 25 kg, leaving 2 kg — below
        // the 5 kg threshold, so the ordinary scan stays one tap.
        $entry = $this->startBatch();
        $this->complete($entry, '4000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '23.0000'],
        ]);

        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $second->barcode,
        ])->assertSuccessful();

        $this->assertNull(DayBinMovement::query()
            ->where('type', DayBinMovementType::Load->value)
            ->orderByDesc('id')
            ->first()
            ->balance_ack_reason);
    }

    // ==================================================================
    // 7. A LOAD CARRIES NO MACHINE
    // ==================================================================

    public function test_a_load_names_no_machine_anywhere_even_when_an_old_client_sends_one(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');

        // A floor tablet still running the previous build keeps posting
        // work_center_id for as long as it has not reloaded. The scan must
        // succeed — refusing it would stop material entering the factory over
        // a field the server no longer wants — and the value must reach
        // nothing.
        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => $bag->barcode,
            'work_center_id' => $this->machine->id,
        ])->assertSuccessful();

        $load = DayBinMovement::query()->where('type', DayBinMovementType::Load->value)->firstOrFail();

        $this->assertNull($load->work_center_id);
        // Nor is the machine stamped on the BAG — a bag at the common input
        // is not at a machine.
        $this->assertNull($bag->fresh()->day_bin_work_center_id);
    }

    // ==================================================================
    // 8. IDENTITY SURVIVES — it was the CLAIM that was removed, not the data
    // ==================================================================

    public function test_lot_identity_the_permanent_barcode_and_the_original_rate_all_survive_a_load(): void
    {
        $this->actingAsProduction();

        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => '2026-07-25',
            'bag_count' => 1,
            'total_received_kg' => '25.0000',
            'supplier_lot_no' => 'SUP-9931',
            'receipt_rate_per_kg' => '104.5000',
        ]);

        $bag = $this->bag('LOT-REAL-B1', '25.0000', $lot);

        $this->loadBag($bag, '10');

        $bag->refresh();
        $lot->refresh();

        // The bag's permanent barcode, its original size, and the balance it
        // still holds after a partial pour.
        $this->assertSame('LOT-REAL-B1', $bag->barcode);
        $this->assertSame('25.0000', $bag->original_kg);
        $this->assertSame('15.0000', $bag->remaining_kg);

        // The lot's supplier identity and the rate it was bought at.
        $this->assertSame('SUP-9931', $lot->supplier_lot_no);
        $this->assertSame('104.5000', $lot->receipt_rate_per_kg);
        $this->assertSame($lot->id, $bag->material_lot_id);

        // And the load row still names the BAG it came out of — the store's
        // own record of which bag was opened, which is a fact. What is gone
        // is the claim that this bag belongs to a machine or to a batch.
        $load = DayBinMovement::query()->where('type', DayBinMovementType::Load->value)->firstOrFail();
        $this->assertSame($bag->id, $load->material_bag_id);
        $this->assertSame('10.0000', $load->quantity_kg);
    }

    // ==================================================================
    // 9. THE MONEY SAYS WHAT IT IS
    // ==================================================================

    public function test_batch_cost_splits_pooled_resin_from_other_material_and_costs_each_accepted_piece_after_quality_nets_it(): void
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

        $this->loadBag($bag);

        $entry = $this->startBatch();

        // Resin was loaded; masterbatch was not. The LOAD TRAIL alone decides
        // which bucket each line lands in — no name is matched anywhere,
        // which is the rule this module holds to.
        $this->complete($entry, '10000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
            ['item_id' => $masterbatch->id, 'quantity_issued_kg' => '2.0000'],
        ]);

        $allocator = app(BagCostAllocationService::class);
        $summary = $allocator->summary(ShiftProductionEntry::find($entry));

        // Resin at the pool average (20 × 100), masterbatch at its issue cost
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
        // the whole bill. 2500 ÷ 9,500 = 0.263157…, truncated at 4 dp.
        $netted = $allocator->summary(ShiftProductionEntry::find($entry));

        $this->assertSame('9500', $netted['accepted_quantity']);
        $this->assertSame('0.2631', $netted['cost_per_accepted_unit']);
        $this->assertSame('2500.0000', $netted['material_cost_total']);
    }

    public function test_batch_cost_states_the_accounting_allocation_and_carries_no_bag_identity_for_anyone(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');
        $this->stubRates([$bag->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $this->loadBag($bag);

        $entry = $this->startBatch();
        $this->complete($entry, '10000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        // ---- The production login: totals and the claim, yes. Rates, no. --
        $asProduction = $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->json('data.0.batch_cost');

        $this->assertSame('2000.0000', $asProduction['material_cost_total']);
        $this->assertSame('0.2000', $asProduction['cost_per_accepted_unit']);

        // THE SENTENCE IS FOR EVERYONE. It is not anatomy, it is what the
        // number means, and a reader shown the figure without it would be
        // shown a claim this module does not make.
        $this->assertSame(BagCostAllocationService::ALLOCATION_SENTENCE, $asProduction['basis']);
        $this->assertStringContainsString('not physical bag traceability', $asProduction['basis']);
        $this->assertSame('common_resin_pool_weighted_average', $asProduction['sources']['resin_cost']);

        // ABSENT, not null — a rate must never be one devtools panel away
        // from a production login.
        $this->assertArrayNotHasKey('allocations', $asProduction);
        $this->assertArrayNotHasKey('other_lines', $asProduction);
        // The dead key from the layer model is gone entirely.
        $this->assertArrayNotHasKey('layers', $asProduction);

        // ---- Finance: the same totals, plus the rate behind them. --------
        $this->actingAsProduction(['finance.view']);

        $asFinance = $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->json('data.0.batch_cost');

        $this->assertSame('2000.0000', $asFinance['material_cost_total']);
        $this->assertArrayHasKey('allocations', $asFinance);
        $this->assertArrayNotHasKey('layers', $asFinance);

        $this->assertCount(1, $asFinance['allocations']);
        $detail = $asFinance['allocations'][0];

        $this->assertSame('100.0000', $detail['pool_rate']);
        $this->assertSame('20.0000', $detail['quantity']);
        $this->assertSame('2000.0000', $detail['amount']);
        $this->assertSame(BagCostAllocationService::SOURCE_POOL_AVERAGE, $detail['rate_source']);
        $this->assertSame(BagCostAllocationService::ALLOCATION_SENTENCE, $detail['sentence']);

        // NOT EVEN FINANCE GETS A BAG. The claim is dead at every permission
        // level — this is the assertion that stops it being quietly restored
        // "just for the accountant".
        foreach (['bag_barcode', 'material_bag_id', 'material_lot_id', 'supplier_lot_no', 'day_bin_movement_id'] as $dead) {
            $this->assertArrayNotHasKey($dead, $detail);
        }
    }

    // ==================================================================
    // 10. NEVER TWICE, AND THE LEDGER IS NEVER TOUCHED
    // ==================================================================

    public function test_a_second_live_allocation_run_is_refused_outright(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');
        $this->stubRates([$bag->material_lot_id => ['100.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $this->loadBag($bag);

        $entry = $this->startBatch();
        $this->complete($entry, '4000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        $this->expectException(DuplicateAllocationException::class);

        // A double allocation would charge this batch for its resin twice and
        // would be invisible in every total.
        app(BagCostAllocationService::class)->allocate(ShiftProductionEntry::find($entry));
    }

    public function test_allocating_pool_costs_leaves_the_stock_ledgers_own_valuation_exactly_as_it_was(): void
    {
        $this->actingAsProduction();

        $bag = $this->bag('LOT-A-B1', '25.0000');
        // A bag rate deliberately far from the 90.00 stock average: if any of
        // this leaked into the ledger, this assertion is where it shows.
        $this->stubRates([$bag->material_lot_id => ['400.0000', BagLotRateResolver::SOURCE_BAG_RECEIPT]]);

        $this->loadBag($bag);

        $entry = $this->startBatch();

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

        // The Accounts-approved moving average is untouched — pool rates are
        // an analytic layer BESIDE the ledger and never inside it, and they
        // never reach Tally at all.
        $this->assertSame($averageBefore, $averageAfter);
        $this->assertSame('90.0000', (string) $averageAfter);

        // While the pool, beside it, priced the batch at the pool rate.
        $this->assertSame('8000.0000', app(BagCostAllocationService::class)
            ->summary(ShiftProductionEntry::find($entry))['resin_cost']);
    }

    // ==================================================================
    // THE REAL CONTRACT, END TO END — no stub anywhere in this scene
    // ==================================================================

    public function test_real_lot_rates_reach_the_pool_and_a_later_cost_version_reaches_it_with_the_next_load(): void
    {
        $this->actingAsProduction();

        // A lot that arrived with a real purchase rate on it — Inventory's
        // own column, through Inventory's own contract methods. The stub used
        // elsewhere in this file is deliberately absent here: this is the
        // scene that proves the seam is wired to the real thing.
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => '2026-07-25',
            'bag_count' => 2,
            'total_received_kg' => '50.0000',
            'receipt_rate_per_kg' => '104.5000',
        ]);

        $bagOne = $this->bag('LOT-REAL-B1', '25.0000', $lot);
        $bagTwo = $this->bag('LOT-REAL-B2', '25.0000', $lot);

        $this->loadBag($bagOne);

        $this->assertSame('104.5000', $this->pool()->avg_rate_per_kg);

        $entry = $this->startBatch();
        $this->complete($entry, '4000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '20.0000'],
        ]);

        $row = $this->liveAllocations($entry)->first();

        // The GRN rate reached the batch through the pool, untouched.
        $this->assertSame('104.5000', $row->rate_per_kg);
        $this->assertSame('2090.0000', $row->amount);
        $this->assertSame(BagCostAllocationService::SOURCE_POOL_AVERAGE, $row->rate_source);

        // ---- The purchase invoice lands, revising the rate upward. ------
        app(MaterialCostVersionService::class)->append(
            $lot,
            ['rate_per_kg' => '111.0000', 'kind' => 'invoice', 'note' => 'Supplier invoice 8841.'],
            null,
        );

        // THE CLOSED BATCH IS NOT REWRITTEN. Its rate was frozen at
        // allocation time, which is the whole rule about provisional GRN
        // costs: a revision reaches FUTURE material, it never silently edits
        // a figure somebody has already signed off.
        $this->assertSame('104.5000', $row->fresh()->rate_per_kg);
        $this->assertSame('2090.0000', $row->fresh()->amount);

        // NOR DOES IT REPRICE WHAT IS ALREADY IN THE POOL. The 5 kg still
        // standing in the common input were bought at 104.50 and are still
        // carried at 104.50 — a revision reaches the pool WITH THE NEXT LOAD,
        // which is the only moment a rate is read.
        $this->assertSame('5.0000', $this->pool()->quantity_kg);
        $this->assertSame('104.5000', $this->pool()->avg_rate_per_kg);

        $this->loadBag($bagTwo, ack: 'confirm_extra');

        // (5 × 104.50 + 25 × 111) ÷ 30 = 3297.50 ÷ 30 = 109.9166…
        $this->assertSame('30.0000', $this->pool()->quantity_kg);
        $this->assertSame('109.9166', $this->pool()->avg_rate_per_kg);

        $entryTwo = $this->startBatch();
        $this->complete($entryTwo, '2000', [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '10.0000'],
        ]);

        $rowTwo = $this->liveAllocations($entryTwo)->first();

        $this->assertSame('10.0000', $rowTwo->quantity_kg);
        $this->assertSame('109.9166', $rowTwo->rate_per_kg);
        $this->assertSame('1099.1660', $rowTwo->amount);
    }
}
