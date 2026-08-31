<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHERE TOMORROW'S BATCH TAKES ITS MATERIAL FROM — owner decision, 31-Aug-2026:
 * "Material remaining in Production/WIP stays available for the next
 * production day, INCLUDING existing material without a Store Issue."
 *
 * WHAT CHANGED AND WHY IT WAS GUARDED BEFORE. `consumptionSource` used to
 * require BOTH that Production/WIP held the material AND that a store issue
 * had put it there. The second condition was deliberate: the WIP row predates
 * this phase and carries rehearsal-era balances, so "WIP holds some" alone
 * would have redirected the first completion after deploy onto stock nobody
 * had handed over. On the live instance that is not a hypothetical — seven of
 * the nine materials standing in Production/WIP have no store issue behind
 * them at all.
 *
 * The owner has now ruled on exactly that population: those kilograms are
 * real, they are in production, and the next day's batch consumes them. The
 * issue-gate is therefore gone and this file is what replaces it — the
 * behaviour is now a decision somebody made rather than a condition somebody
 * could delete by accident.
 *
 * THE EXHAUSTION CASE IS THE ONE TO READ CAREFULLY, because removing a guard
 * usually opens the hole the guard was covering, and the argument that it
 * does not here has to be checked rather than asserted. The concern is real
 * and written into consumptionSource's own comment: a source test that stops
 * being true the moment a batch eats everything standing would send the NEXT
 * consumption to the store, drawing material the store never issued, with no
 * shortfall recorded anywhere and the over-consumption invisible.
 *
 * It does not happen, and the reason is that the surviving condition tests
 * for a NON-ZERO balance rather than a positive one. An over-draw lands in
 * Production/WIP and leaves it NEGATIVE, negative is non-zero, so WIP stays
 * the source and the completion's own shortfall record fires. The only
 * transition back to the store is at EXACTLY zero — which is the honest
 * answer, because at exactly zero there is nothing left on the floor and the
 * store is where the material actually is. All three states are pinned below
 * so that the reasoning is a test rather than a paragraph.
 */
class WipAvailableWithoutAStoreIssueTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    private FactoryWarehouseResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'WA-STORE', 'name' => 'WA Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WA-WIP', 'name' => 'WA Production WIP', 'is_active' => true]);
        $this->resin = Item::create(['sku' => 'WA-RESIN', 'name' => 'WA Resin', 'uom' => 'KGS', 'is_active' => true]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        $this->resolver = app(FactoryWarehouseResolver::class);
        $this->resolver->setRawMaterialWarehouseId($this->store->id);

        // The store always holds plenty; the question is only ever whether
        // production's own location answers first.
        $this->balance($this->store, '500.0000');
    }

    private function balance(Warehouse $warehouse, string $quantity): void
    {
        StockBalance::updateOrCreate(
            ['item_id' => $this->resin->id, 'warehouse_id' => $warehouse->id],
            ['quantity' => $quantity, 'average_cost' => '10.0000'],
        );
    }

    /** THE DECISION. No store issue exists anywhere in this test. */
    public function test_material_standing_in_production_is_consumed_from_there_even_with_no_store_issue_behind_it(): void
    {
        $this->balance($this->wip, '30.0000');

        $this->assertSame(
            $this->wip->id,
            $this->resolver->consumptionSource($this->resin->id)?->id,
            'material on the floor is consumed from the floor, whatever put it there',
        );
    }

    /**
     * THE EXHAUSTION CASE. An over-draw must not silently walk back to the
     * store: it belongs to Production/WIP, where the completion's shortfall
     * record can see it.
     */
    public function test_an_over_drawn_production_location_stays_the_source(): void
    {
        $this->balance($this->wip, '-70.0000');

        $this->assertSame(
            $this->wip->id,
            $this->resolver->consumptionSource($this->resin->id)?->id,
            'a location already over-drawn is not one to walk away from — the next consumption must land there too',
        );
    }

    /**
     * AND IT STILL LETS GO. At exactly zero nothing is standing in
     * production, so the store is not a fallback — it is where the material
     * is.
     */
    public function test_an_empty_production_location_hands_back_to_the_store(): void
    {
        $this->balance($this->wip, '0.0000');

        $this->assertSame(
            $this->store->id,
            $this->resolver->consumptionSource($this->resin->id)?->id,
            'nothing on the floor means the store answers, exactly as it did before this phase',
        );
    }
}
