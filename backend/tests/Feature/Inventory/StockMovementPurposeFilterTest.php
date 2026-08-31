<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * NARROWING THE LEDGER BY PURPOSE — the read the column was added for.
 *
 * 2026_08_17_150000 created `purpose` AND `stock_movements_purpose_index`,
 * saying in as many words that "the reads this exists for group and filter the
 * ledger by purpose". Nothing could: the endpoint took item_id and
 * warehouse_id and nothing else, so the index sat unused and a caller wanting
 * "every issue and return" had to fetch the whole ledger and filter it in the
 * browser.
 *
 * THE ONE-ROW-PER-TRANSFER PROPERTY IS THE POINT OF THE LAST TEST, and it is
 * why the Store <-> Production history asks for the Production/WIP leg rather
 * than for a purpose alone. A transfer is TWO movements — out of the store,
 * into WIP — so a purpose filter by itself lists both and the history reads as
 * twice the traffic it was. Worse, `meta.total` becomes a lie for anyone
 * counting handovers. Adding the warehouse narrows each pair to the single leg
 * standing in WIP, which works because recordTransfer refuses from == to, so
 * the two legs are always in different warehouses. It is a WHERE predicate, so
 * the collapse happens before the LIMIT and pagination counts events.
 */
class StockMovementPurposeFilterTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    private StockMovementService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockMovementService::class);

        $this->store = Warehouse::create(['code' => 'PF-STORE', 'name' => 'PF Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'PF-WIP', 'name' => 'PF Work In Progress', 'is_active' => false]);
        $this->resin = Item::create(['sku' => 'PF-RESIN', 'name' => 'PF Resin', 'uom' => 'KGS', 'is_active' => true]);

        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '1000.0000',
            'average_cost' => '10.0000',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
        Sanctum::actingAs($user);
    }

    private function issue(string $qty, string $reference): void
    {
        $this->stock->recordTransfer(
            itemId: $this->resin->id,
            fromWarehouseId: $this->store->id,
            toWarehouseId: $this->wip->id,
            quantity: $qty,
            reference: $reference,
            purpose: StockMovementPurpose::IssueToProduction,
        );
    }

    private function returnToStore(string $qty, string $reference): void
    {
        $this->stock->recordTransfer(
            itemId: $this->resin->id,
            fromWarehouseId: $this->wip->id,
            toWarehouseId: $this->store->id,
            quantity: $qty,
            reference: $reference,
            purpose: StockMovementPurpose::ReturnFromProduction,
        );
    }

    /**
     * A CONSUMPTION STANDING IN THE SAME WAREHOUSE. Every test below that
     * claims the purpose filter works must have something to EXCLUDE, or it
     * passes just as well with no filter at all — which is how a filter test
     * quietly stops testing the filter.
     */
    private function consumeInWip(string $qty): void
    {
        $this->stock->recordIssue(
            itemId: $this->resin->id,
            warehouseId: $this->wip->id,
            quantity: $qty,
            reference: 'SPE #1',
            allowNegative: true,
            purpose: StockMovementPurpose::Consumption,
        );
    }

    private function rows(string $query): array
    {
        return $this->getJson('/api/v1/inventory/stock-movements'.$query)->assertOk()->json('data');
    }

    public function test_the_ledger_can_be_narrowed_to_one_purpose(): void
    {
        $this->issue('300.0000', 'Store issue SI-1');
        $this->stock->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '50.0000',
            unitCost: '0.0000',
            reference: 'GRN for PO 1',
        );

        $rows = $this->rows('?purpose=issue_to_production');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('issue_to_production', $row['purpose']);
        }
    }

    public function test_more_than_one_purpose_is_one_list_not_two_requests(): void
    {
        $this->issue('300.0000', 'Store issue SI-1');
        $this->returnToStore('80.0000', 'Store issue SI-1 — return');
        $this->consumeInWip('20.0000');

        $rows = $this->rows('?purpose=issue_to_production,return_from_production');

        $purposes = array_unique(array_column($rows, 'purpose'));
        sort($purposes);
        $this->assertSame(['issue_to_production', 'return_from_production'], $purposes);
    }

    public function test_a_purpose_that_does_not_exist_is_refused_and_the_real_ones_are_named(): void
    {
        // Dropping it silently would answer a typo with the WHOLE ledger — the
        // same fail-open direction the array refusal exists to prevent.
        $response = $this->getJson('/api/v1/inventory/stock-movements?purpose=issue_to_prodcution')
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');

        $message = $response->json('errors.purpose.0');
        $this->assertStringContainsString('issue_to_prodcution', $message);
        $this->assertStringContainsString('issue_to_production', $message, 'the refusal must name the values that exist');
    }

    public function test_an_empty_purpose_narrows_nothing(): void
    {
        $this->issue('300.0000', 'Store issue SI-1');

        $this->assertCount(
            count($this->rows('')),
            $this->rows('?purpose='),
            'an empty filter is no filter, the same rule searchTerm follows',
        );
    }

    /**
     * THE ONE THAT MAKES THE HISTORY HONEST. Without the warehouse, a transfer
     * appears twice and the screen reports double the traffic.
     */
    public function test_asking_for_the_wip_leg_gives_one_row_per_handover_not_two(): void
    {
        $this->issue('300.0000', 'Store issue SI-1');
        $this->returnToStore('80.0000', 'Store issue SI-1 — return');
        $this->consumeInWip('20.0000');

        $bothLegs = $this->rows('?purpose=issue_to_production,return_from_production');
        $this->assertCount(4, $bothLegs, 'two transfers are four movements in the raw ledger');

        $wipLeg = $this->rows('?purpose=issue_to_production,return_from_production&warehouse_id='.$this->wip->id);
        $this->assertCount(2, $wipLeg, 'one row per handover once the Production/WIP leg is named');

        // And direction is unambiguous from the type against that leg.
        $byPurpose = [];
        foreach ($wipLeg as $row) {
            $byPurpose[$row['purpose']] = $row['type'];
        }
        $this->assertSame('transfer_in', $byPurpose['issue_to_production'], 'an issue arrives INTO production');
        $this->assertSame('transfer_out', $byPurpose['return_from_production'], 'a return leaves production');
    }

    public function test_the_totals_count_handovers_rather_than_legs(): void
    {
        foreach (range(1, 3) as $n) {
            $this->issue('10.0000', "Store issue SI-{$n}");
        }
        $this->consumeInWip('5.0000');

        $meta = $this->getJson(
            '/api/v1/inventory/stock-movements?purpose=issue_to_production&warehouse_id='.$this->wip->id
        )->assertOk()->json('meta');

        $this->assertSame(3, $meta['total'], 'three handovers must total three, not six');
    }
}
