<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ReturnedQualityState;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\QualityHoldLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * QUALITY'S END OF THE DAMAGED RETURN (DEC-20260901-003) — the second half of
 * the owner's rule, where the material either becomes Scrap or comes back.
 *
 * The first half (a damaged return lands in quality hold and never in the
 * store) is pinned by StoreToProductionAndBackTest. This class starts where
 * that one stops: material is already standing in the hold, and Quality has
 * looked at it.
 *
 * WHAT IT PINS:
 *
 *  1. The hold is READABLE — Quality can see what is waiting, by material,
 *     with its unit.
 *  2. CONFIRMED DAMAGE SCRAPS IT. The quantity leaves stock through an
 *     issue carrying purpose `scrap`; it does not move to another location
 *     and it does not come back.
 *  3. NOT DAMAGED AFTER ALL RELEASES IT to a store as usable stock — the
 *     door that keeps a mis-ticked line from stranding good material.
 *  4. Neither action may take more than is actually standing.
 *  5. THE WHOLE LOOP IS CLOSED: nothing reaches usable stock without
 *     passing Quality first.
 *
 * NO TALLY VOUCHER IS POSTED OR STAGED by any of this, and no test here asks
 * for one: what Tally should receive for scrapped return material is
 * expressly undecided (DEC-20260901-003).
 */
class DamagedReturnReachesQualityTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    private Warehouse $qualityHold;

    private User $storeKeeper;

    private User $qualityUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production', 'is_active' => false]);
        $this->qualityHold = Warehouse::create(['code' => 'QC-HOLD', 'name' => 'Quality Hold', 'is_active' => true]);

        $this->resin = Item::create([
            'sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs.', 'is_production_input' => true,
        ]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        app(QualityHoldLocationResolver::class)->setWarehouseId($this->qualityHold->id);

        $this->storeKeeper = $this->userWith(['inventory.manage', 'production.manage']);
        $this->qualityUser = $this->userWith(['quality.manage']);
    }

    // ---- (1) and (2): confirmed damage becomes scrap -----------------------

    public function test_quality_sees_what_is_waiting_and_scrapping_it_takes_it_out_of_stock(): void
    {
        $this->damagedReturnOf('40');

        Sanctum::actingAs($this->qualityUser);

        $waiting = $this->getJson('/api/v1/quality/returned-material-holds')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $waiting);
        $this->assertSame($this->resin->id, $waiting[0]['item_id']);
        $this->assertSame('40.0000', $waiting[0]['quantity']);
        // THE UNIT TRAVELS WITH THE FIGURE. A quantity with no unit beside it
        // is the shape of error FC-03 exists about.
        $this->assertSame('Kgs.', $waiting[0]['uom']);

        $this->postJson('/api/v1/quality/returned-material-holds/confirm-damage', [
            'notes' => 'Wet through',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '40']],
        ])->assertCreated();

        // IT LEFT STOCK. Not moved to another location, not booked inward
        // anywhere: an issue, the same route incoming QC uses for material it
        // rejects. Nothing anywhere gained 40 kg.
        $this->assertSame('0.0000', $this->balance($this->qualityHold));
        $this->assertSame('0.0000', $this->balance($this->store));
        $this->assertSame('0.0000', $this->balance($this->wip));

        $scrap = StockMovement::query()
            ->where('purpose', StockMovementPurpose::Scrap->value)
            ->firstOrFail();

        $this->assertSame(StockMovementType::Issue, $scrap->type);
        $this->assertSame($this->qualityHold->id, $scrap->warehouse_id);
        $this->assertSame('40.0000', bcadd((string) $scrap->quantity, '0', 4));
        $this->assertSame($this->qualityUser->id, $scrap->created_by);

        // THE ITEM IS STILL THE ITEM. No scrap master is named, invented or
        // created — DEC-20260901-002 leaves which Scrap item undecided, and
        // an agent never invents a Tally name.
        $this->assertSame($this->resin->id, $scrap->item_id);

        $this->assertLedgerMatchesBalances('after scrapping a confirmed-damaged return');
    }

    // ---- (3) not damaged after all -----------------------------------------

    /**
     * THE RELEASE DOOR, and why it exists: the owner's rule forbids damaged
     * material going back to usable stock DIRECTLY. Quality looking at it and
     * finding it sound is not "directly" — and without this a storekeeper who
     * ticks the wrong box at the end of a shift strands good material in a
     * location nothing can draw from, which is the failure the return door was
     * built to fix. Recorded as the agent's reading in DEC-20260901-003.
     */
    public function test_material_quality_clears_goes_back_to_the_store_as_usable_stock(): void
    {
        $this->damagedReturnOf('40');

        Sanctum::actingAs($this->qualityUser);

        $this->postJson('/api/v1/quality/returned-material-holds/release', [
            'to_warehouse_id' => $this->store->id,
            'notes' => 'Outer wrap only',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '25']],
        ])->assertCreated();

        // 25 released, 15 still waiting — a PARTIAL disposition is as valid
        // as a whole one, the same way a partial return is.
        $this->assertSame('25.0000', $this->balance($this->store));
        $this->assertSame('15.0000', $this->balance($this->qualityHold));

        // AND THE LEDGER SAYS WHY IT MOVED. The confirm branch beside this one
        // stamps `scrap`; this one wrote `unknown` until the purpose existed,
        // which left the single movement meaning "Quality cleared this"
        // indistinguishable from any untyped transfer — on the one new
        // location whose whole point is that somebody asks what came out of it.
        $released = StockMovement::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->store->id)
            ->where('type', StockMovementType::TransferIn->value)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(StockMovementPurpose::QualityRelease, $released->purpose);
        $this->assertSame('25.0000', bcadd((string) $released->quantity, '0', 4));

        // It is NOT filed as a production return: that read is what the hold
        // exists to serve, and these rows are not the floor sending anything
        // back.
        $this->assertNotSame(StockMovementPurpose::ReturnFromProduction, $released->purpose);

        $this->assertLedgerMatchesBalances('after releasing from quality hold');
    }

    public function test_releasing_into_the_hold_itself_is_refused(): void
    {
        $this->damagedReturnOf('40');

        Sanctum::actingAs($this->qualityUser);

        $this->postJson('/api/v1/quality/returned-material-holds/release', [
            'to_warehouse_id' => $this->qualityHold->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '25']],
        ])->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');

        $this->assertSame('40.0000', $this->balance($this->qualityHold));
    }

    // ---- (4) neither action may exceed what is standing --------------------

    public function test_neither_door_may_dispose_of_more_than_is_standing(): void
    {
        $this->damagedReturnOf('40');

        Sanctum::actingAs($this->qualityUser);

        $this->postJson('/api/v1/quality/returned-material-holds/confirm-damage', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '41']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');

        $this->postJson('/api/v1/quality/returned-material-holds/release', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '41']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');

        // TWO LINES OF ONE MATERIAL SHARE ONE BUDGET — neither is told the
        // whole hold is theirs. 30 + 20 against a hold of 40 is refused, and
        // refused before anything moved.
        $this->postJson('/api/v1/quality/returned-material-holds/confirm-damage', [
            'lines' => [
                ['item_id' => $this->resin->id, 'quantity' => '30'],
                ['item_id' => $this->resin->id, 'quantity' => '20'],
            ],
        ])->assertUnprocessable();

        $this->assertSame('40.0000', $this->balance($this->qualityHold));
        $this->assertSame('0.0000', $this->balance($this->store));
    }

    // ---- (5) the loop, end to end ------------------------------------------

    /**
     * NOTHING REACHES USABLE STOCK WITHOUT PASSING QUALITY. Read as one
     * sequence because that is the owner's rule stated as arithmetic: the
     * store's balance is zero from the moment the damaged material comes off
     * the floor until Quality decides, and what Quality confirms as damaged
     * never adds to it at all.
     */
    public function test_the_store_gains_nothing_until_quality_has_decided(): void
    {
        $this->damagedReturnOf('100');

        // Off the floor, and the store has gained nothing.
        $this->assertSame('0.0000', $this->balance($this->store));
        $this->assertSame('100.0000', $this->balance($this->qualityHold));

        Sanctum::actingAs($this->qualityUser);

        // 60 genuinely damaged, 40 sound.
        $this->postJson('/api/v1/quality/returned-material-holds/confirm-damage', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '60']],
        ])->assertCreated();

        $this->postJson('/api/v1/quality/returned-material-holds/release', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '40']],
        ])->assertCreated();

        // The store gained exactly what Quality cleared, and not a kilogram
        // of what it condemned.
        $this->assertSame('40.0000', $this->balance($this->store));
        $this->assertSame('0.0000', $this->balance($this->qualityHold));

        $this->assertSame(
            '60.0000',
            bcadd(
                (string) StockMovement::query()
                    ->where('purpose', StockMovementPurpose::Scrap->value)
                    ->firstOrFail()
                    ->quantity,
                '0',
                4,
            ),
        );

        $this->assertLedgerMatchesBalances('after a full quality disposition');
    }

    // ---- helpers -----------------------------------------------------------

    /** Material on the floor, returned damaged — the state Quality inherits. */
    private function damagedReturnOf(string $quantity): void
    {
        Sanctum::actingAs($this->storeKeeper);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->wip->id,
            quantity: $quantity,
            unitCost: '0',
            reference: 'Opening',
            purpose: StockMovementPurpose::Opening,
        );

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => $quantity,
                'quality_state' => ReturnedQualityState::Damaged->value,
            ]],
        ])->assertCreated();
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function balance(Warehouse $warehouse): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0.0000');
    }

    /** The invariant `inventory:check-ledger` enforces: movements sign to balances. */
    private function assertLedgerMatchesBalances(string $step): void
    {
        $sums = [];
        foreach (StockMovement::query()->orderBy('id')->get() as $movement) {
            $sign = match ($movement->type) {
                StockMovementType::Receipt, StockMovementType::TransferIn => '1',
                StockMovementType::Issue, StockMovementType::TransferOut => '-1',
            };
            $key = "{$movement->item_id}@{$movement->warehouse_id}";
            $sums[$key] = bcadd($sums[$key] ?? '0.0000', bcmul((string) $movement->quantity, $sign, 4), 4);
        }

        foreach (StockBalance::query()->get() as $balance) {
            $key = "{$balance->item_id}@{$balance->warehouse_id}";
            $this->assertSame(
                bcadd($sums[$key] ?? '0.0000', '0', 4),
                bcadd((string) $balance->quantity, '0', 4),
                "The ledger stopped matching the balances {$step} ({$key}).",
            );
        }
    }
}
