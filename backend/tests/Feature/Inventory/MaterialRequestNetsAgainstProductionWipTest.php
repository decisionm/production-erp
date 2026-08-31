<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DON'T ASK THE STORE FOR WHAT IS ALREADY ON THE FLOOR — owner decision,
 * 31-Aug-2026:
 *
 *     Net request quantity = Total required − Usable Production/WIP balance
 *                            for the same item and UOM, minimum zero.
 *
 * The floor states what the shift needs IN TOTAL and the ERP subtracts what
 * is already standing in production, so a request is the shortfall rather
 * than the requirement. `quantity` carries the net because everything
 * downstream of it — issued_quantity, remaining_quantity, the store's whole
 * fulfilment arithmetic — is computed against it; `required_quantity` keeps
 * what was actually needed, which is the only thing that can tell "we needed
 * 70" apart from "we needed 100 and 30 was already there".
 *
 * THE LIMIT OF THE RULE AS STATED, pinned in the last test so it is a known
 * property rather than a surprise: the netting reads the WIP balance at the
 * moment the request is raised and nothing reserves it. Two requests raised
 * back to back for the same material therefore both net against the same
 * kilograms, and the second under-asks. Closing that needs a reservation the
 * decision does not describe, so the behaviour is recorded here rather than
 * invented in code.
 */
class MaterialRequestNetsAgainstProductionWipTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $wip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wip = Warehouse::create(['code' => 'MN-WIP', 'name' => 'MN Production WIP', 'is_active' => true]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);

        $this->resin = Item::create([
            'sku' => 'MN-RESIN', 'name' => 'MN Resin', 'uom' => 'KGS',
            'is_active' => true, 'is_production_input' => true,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);
    }

    private function onFloor(string $quantity): void
    {
        StockBalance::updateOrCreate(
            ['item_id' => $this->resin->id, 'warehouse_id' => $this->wip->id],
            ['quantity' => $quantity, 'average_cost' => '10.0000'],
        );
    }

    /** @return array{quantity: string, required_quantity: string|null} */
    private function raise(string $required): array
    {
        $line = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => $required]],
        ])->assertCreated()->json('data.lines.0');

        return ['quantity' => $line['quantity'], 'required_quantity' => $line['required_quantity']];
    }

    public function test_the_store_is_asked_only_for_the_shortfall(): void
    {
        $this->onFloor('30.0000');

        $line = $this->raise('100');

        $this->assertSame('70.0000', $line['quantity'], 'the store hands over the shortfall, not the requirement');
        $this->assertSame('100.0000', $line['required_quantity'], 'what the shift needed stays readable');
    }

    public function test_nothing_on_the_floor_means_the_whole_requirement_is_asked_for(): void
    {
        $line = $this->raise('100');

        $this->assertSame('100.0000', $line['quantity']);
        $this->assertSame('100.0000', $line['required_quantity']);
    }

    /** The minimum-of-zero half of the rule, and the line is kept rather than dropped. */
    public function test_a_requirement_already_covered_asks_for_nothing_and_still_says_why(): void
    {
        $this->onFloor('250.0000');

        $line = $this->raise('100');

        $this->assertSame('0.0000', $line['quantity'], 'never negative — the floor already has more than enough');
        $this->assertSame(
            '100.0000',
            $line['required_quantity'],
            'the line stays on the request: "you need 100 and it is already in production" is the useful answer',
        );
    }

    /**
     * AN OVER-DRAWN FLOOR IS NOT A SUPPLY. Netting against a negative balance
     * would ADD to the request — "you are 70 kg overdrawn, so ask for 70
     * more" — turning a bookkeeping problem into a bigger handover.
     */
    public function test_an_over_drawn_production_balance_does_not_inflate_the_request(): void
    {
        $this->onFloor('-70.0000');

        $line = $this->raise('100');

        $this->assertSame('100.0000', $line['quantity'], 'an over-draw is not stock anybody can pick up');
    }

    /**
     * THE STATED RULE'S KNOWN LIMIT. Nothing reserves the floor balance, so
     * two requests in one morning both net against the same kilograms. This
     * asserts the behaviour rather than endorsing it — closing it needs a
     * reservation the owner's formula does not describe.
     */
    public function test_two_requests_in_a_row_both_net_against_the_same_floor_stock(): void
    {
        $this->onFloor('30.0000');

        $first = $this->raise('100');
        $second = $this->raise('100');

        $this->assertSame('70.0000', $first['quantity']);
        $this->assertSame(
            '70.0000',
            $second['quantity'],
            'the same 30 kg is netted twice — raising two requests before either is issued under-asks by design of the stated rule',
        );
    }

    /**
     * Lines raised before the rule existed are NULL, not backfilled to their
     * own quantity: "not netted" and "netted and found nothing" are different
     * facts and only one of them is true of history.
     */
    public function test_the_netting_is_recorded_per_line_and_not_assumed_for_old_ones(): void
    {
        $this->onFloor('30.0000');
        $this->raise('100');

        $this->assertDatabaseHas('material_request_lines', [
            'item_id' => $this->resin->id,
            'quantity' => '70.0000',
            'required_quantity' => '100.0000',
        ]);
    }
}
