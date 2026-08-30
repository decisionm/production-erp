<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHO MOVED THIS. `stock_movements.created_by` has existed since the ledger
 * was created and every writer populates it — it was simply never served, so
 * the ledger read as though nobody had done anything and a storekeeper asking
 * the obvious question had to be told the ERP knew and would not say.
 *
 * The N+1 assertion is the other half and is not decoration: this resource is
 * served for the whole factory ledger, so an ungated lazy belongsTo here is
 * one query per row on a list that already runs to hundreds.
 */
class StockMovementActorExposureTest extends TestCase
{
    use RefreshDatabase;

    private function storekeeper(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_ledger_says_who_recorded_each_movement(): void
    {
        $user = $this->storekeeper();
        $item = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs.']);
        $store = Warehouse::create(['code' => 'STORE', 'name' => 'The Store', 'is_active' => true]);

        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id,
            warehouseId: $store->id,
            quantity: '100.0000',
            unitCost: '0.0000',
            reference: 'GRN for PO 4',
            createdBy: $user->id,
        );

        $response = $this->getJson('/api/v1/inventory/stock-movements')->assertOk();

        $this->assertSame($user->name, $response->json('data.0.recorded_by'));
    }

    public function test_the_actor_costs_one_query_not_one_per_row(): void
    {
        $user = $this->storekeeper();
        $item = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs.']);
        $store = Warehouse::create(['code' => 'STORE', 'name' => 'The Store', 'is_active' => true]);
        $service = app(StockMovementService::class);

        for ($i = 0; $i < 12; $i++) {
            $service->recordReceipt(
                itemId: $item->id,
                warehouseId: $store->id,
                quantity: '1.0000',
                unitCost: '0.0000',
                reference: 'GRN for PO '.$i,
                createdBy: $user->id,
            );
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $response = $this->getJson('/api/v1/inventory/stock-movements')->assertOk();

        $this->assertCount(12, $response->json('data'));
        $this->assertSame($user->name, $response->json('data.0.recorded_by'));

        // Twelve rows must not cost twelve actor lookups. The bound is loose
        // on purpose — it is catching an N+1, not pinning an exact plan.
        $this->assertLessThan(12, $queries, "the ledger page ran {$queries} queries — the actor looks unbatched");
    }
}
