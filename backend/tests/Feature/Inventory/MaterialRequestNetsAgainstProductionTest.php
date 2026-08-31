<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE NEXT REQUEST COUNTS WHAT IS ALREADY ON THE FLOOR (DEC-20260831-001).
 *
 * The owner's rule: material not returned at the end of production stays
 * available in Production/WIP and is the next day's opening material, so when
 * the next request is prepared the ERP must take account of the usable
 * quantity already standing there for the same item and unit, and the screen
 * must show total required / already in production / balance to request —
 * the first minus the second, floored at zero.
 *
 * What these pin:
 *
 *  1. The store is asked for the BALANCE, not the total, and all three
 *     figures are recorded on the line.
 *  2. A floor that already covers the need asks the store for NOTHING —
 *     the floor at zero, never a negative.
 *  3. Two lines of one material share ONE standing quantity.
 *  4. A NEGATIVE production balance nets nothing: it is a discrepancy, and
 *     netting it would make the floor ask for more than it needs.
 *  5. A UNIT MISMATCH nets nothing (FC-03) — the quantity is reported and
 *     not subtracted.
 *  6. Orphan material, standing with no handover behind it, nets normally.
 *  7. A caller that sends no `required_quantity` is untouched, and its two
 *     new columns stay NULL rather than claiming the floor was empty.
 */
class MaterialRequestNetsAgainstProductionTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production', 'is_active' => false]);

        $this->resin = Item::create([
            'sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs.', 'is_production_input' => true,
        ]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);

        $this->planner = $this->userWith(['production.manage', 'inventory.manage']);
        Sanctum::actingAs($this->planner);
    }

    // ---- (1) and (2) the three figures, and the floor at zero -------------

    public function test_the_store_is_asked_only_for_the_balance(): void
    {
        $this->standingInProduction($this->resin, '300');

        $request = $this->raise([['item_id' => $this->resin->id, 'required_quantity' => '1000']]);
        $line = $request['lines'][0];

        $this->assertSame('1000.0000', $line['required_quantity']);
        $this->assertSame('300.0000', $line['available_in_production']);
        // 1000 needed, 300 already on the floor, so 700 is asked of the store.
        $this->assertSame('700.0000', $line['quantity']);
    }

    public function test_a_floor_that_already_covers_the_need_asks_for_nothing(): void
    {
        $this->standingInProduction($this->resin, '1200');

        $line = $this->raise([['item_id' => $this->resin->id, 'required_quantity' => '1000']])['lines'][0];

        $this->assertSame('1000.0000', $line['required_quantity']);
        $this->assertSame('1200.0000', $line['available_in_production']);
        // Floored at zero. A negative balance to request is not a number a
        // storekeeper can act on.
        $this->assertSame('0.0000', $line['quantity']);
    }

    // ---- (3) one standing quantity, however many lines ask for it --------

    public function test_two_lines_of_one_material_share_one_standing_quantity(): void
    {
        $this->standingInProduction($this->resin, '300');

        $request = $this->raise([
            ['item_id' => $this->resin->id, 'required_quantity' => '400'],
            ['item_id' => $this->resin->id, 'required_quantity' => '400'],
        ]);

        // The first line nets the whole 300 and asks for 100. The second has
        // nothing left to net and asks for all 400 — the 300 kg on the floor
        // cannot answer both lines.
        $this->assertSame('100.0000', $request['lines'][0]['quantity']);
        $this->assertSame('400.0000', $request['lines'][1]['quantity']);
        $this->assertSame('0.0000', $request['lines'][1]['available_in_production']);
    }

    // ---- (4) and (5) what "usable" refuses to count ----------------------

    public function test_a_negative_production_balance_nets_nothing(): void
    {
        // Real state: a batch may consume more than was ever issued to it.
        StockBalance::query()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->wip->id,
            'quantity' => '-112.3250',
            'average_cost' => '0',
        ]);

        $line = $this->raise([['item_id' => $this->resin->id, 'required_quantity' => '1000']])['lines'][0];

        // Netting a negative would ask the store for 1112 kg to cover an
        // error nobody has looked at.
        $this->assertSame('0.0000', $line['available_in_production']);
        $this->assertSame('1000.0000', $line['quantity']);
    }

    public function test_a_unit_the_handover_disagrees_with_nets_nothing(): void
    {
        // The material went to the floor as Nos and the master now says Kgs.
        // — which ItemService::upsertFromTally can do unattended. 300 of a
        // different thing may not be subtracted from 1000 of this one.
        $this->standingInProduction($this->resin, '300', handoverUom: 'Nos');

        $line = $this->raise([['item_id' => $this->resin->id, 'required_quantity' => '1000']])['lines'][0];

        $this->assertSame('0.0000', $line['available_in_production']);
        $this->assertSame('1000.0000', $line['quantity']);

        // And the picker says the quantity IS there while refusing to net it,
        // so nobody concludes the floor is empty.
        $material = collect($this->getJson('/api/v1/inventory/requestable-materials')->assertOk()->json('data'))
            ->firstWhere('id', $this->resin->id);

        $this->assertFalse($material['production_unit_matches']);
        $this->assertSame('0.0000', $material['available_in_production']);
    }

    // ---- (6) orphan material nets normally -------------------------------

    public function test_material_with_no_handover_behind_it_still_nets(): void
    {
        // Seven of the nine live materials are exactly this shape. Refusing
        // them would be inventing a mismatch rather than finding one.
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->wip->id,
            quantity: '860',
            unitCost: '0',
            reference: 'Pre-existing residue',
            purpose: StockMovementPurpose::Opening,
        );

        $line = $this->raise([['item_id' => $this->resin->id, 'required_quantity' => '1000']])['lines'][0];

        $this->assertSame('860.0000', $line['available_in_production']);
        $this->assertSame('140.0000', $line['quantity']);
    }

    // ---- (7) the old shape is untouched ----------------------------------

    public function test_a_request_that_names_no_required_quantity_is_unchanged(): void
    {
        $this->standingInProduction($this->resin, '300');

        $line = $this->raise([['item_id' => $this->resin->id, 'quantity' => '1000']])['lines'][0];

        // Asked for exactly what was typed — nothing netted.
        $this->assertSame('1000.0000', $line['quantity']);
        // NULL, not zero: zero would claim the floor was empty, and it holds
        // 300 kg.
        $this->assertNull($line['required_quantity']);
        $this->assertNull($line['available_in_production']);
    }

    public function test_nothing_nets_when_no_production_location_is_configured(): void
    {
        app(ProductionWipLocationResolver::class)->setWarehouseId(null);
        Warehouse::query()->whereKey($this->wip->id)->update(['code' => 'OLD-WIP']);

        $line = $this->raise([['item_id' => $this->resin->id, 'required_quantity' => '1000']])['lines'][0];

        // Nobody has told the ERP where production is, so netting against a
        // figure nobody configured would silently under-order.
        $this->assertSame('0.0000', $line['available_in_production']);
        $this->assertSame('1000.0000', $line['quantity']);
    }

    // ---- the store's own arithmetic is unaffected -------------------------

    public function test_the_store_owes_the_balance_not_the_total(): void
    {
        $this->standingInProduction($this->resin, '300');

        $request = $this->raise([['item_id' => $this->resin->id, 'required_quantity' => '1000']]);
        $line = MaterialRequest::query()->sole()->lines()->sole();

        // remainingQuantity() is what the store still owes, and it answers
        // the same question it always did — about `quantity`, which is what
        // was asked of the store.
        $this->assertSame('700.0000', $line->remainingQuantity());
        $this->assertSame('700.0000', $request['lines'][0]['remaining_quantity']);
    }

    // ---- helpers -----------------------------------------------------------

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * Material standing in production, put there by a real handover so the
     * unit on the store issue line is the one being tested.
     */
    private function standingInProduction(Item $item, string $quantity, ?string $handoverUom = null): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id,
            warehouseId: $this->store->id,
            quantity: $quantity,
            unitCost: '0',
            reference: 'Opening',
            purpose: StockMovementPurpose::Opening,
        );

        $receiver = $this->userWith(['production.manage']);

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $receiver->id,
            'lines' => [['item_id' => $item->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data');

        if ($handoverUom !== null) {
            // The unit the material actually went out in, before the master
            // was overwritten — set directly because the API snapshots the
            // item's current unit by design (FC-03).
            StoreIssueLine::query()
                ->whereKey($issue['lines'][0]['id'])
                ->update(['uom' => $handoverUom]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function raise(array $lines): array
    {
        // Every line needs a quantity: `required_quantity` is the netting
        // input, and `quantity` stays the field the API has always required.
        $lines = array_map(
            fn (array $line) => $line + ['quantity' => $line['required_quantity'] ?? '0'],
            $lines,
        );

        return $this->postJson('/api/v1/inventory/material-requests', ['lines' => $lines])
            ->assertCreated()
            ->json('data');
    }
}
