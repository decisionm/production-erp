<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SORTING THE TWO TRACEABILITY REGISTERS — lots and bags (03-Sep-2026).
 *
 * Both are paginated, so the order has to be the server's. The lot register
 * keeps its older `order=newest|oldest` switch for every caller that still
 * sends it; a column `sort` wins over it when both arrive. The bag register
 * reads `per_page` for the first time — the bench was fixed at 20 whatever
 * its pager asked for.
 */
class MaterialLotAndBagSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('production.traceability_enabled', true);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    private function lot(string $receivedDate, string $supplierLot, int $bags = 4, string $kg = '100.0000'): MaterialLot
    {
        $item = Item::firstOrCreate(
            ['sku' => 'SYN-RESIN'],
            ['name' => 'Synthetic Resin', 'uom' => 'Kgs.', 'is_active' => true],
        );

        return MaterialLot::create([
            'item_id' => $item->id,
            'supplier_lot_no' => $supplierLot,
            'received_date' => $receivedDate,
            'bag_count' => $bags,
            'bag_weight_kg' => '25.0000',
            'total_received_kg' => $kg,
        ]);
    }

    private function bag(MaterialLot $lot, string $barcode, string $remaining, MaterialBagStatus $status = MaterialBagStatus::InStore): MaterialBag
    {
        return MaterialBag::create([
            'material_lot_id' => $lot->id,
            'barcode' => $barcode,
            'original_kg' => '25.0000',
            'remaining_kg' => $remaining,
            'status' => $status,
        ]);
    }

    // ---- lots ---------------------------------------------------------------

    public function test_lots_refuse_an_unknown_sort(): void
    {
        $this->getJson('/api/v1/inventory/material-lots?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_lots_sort_by_bag_count_descending_with_newest_id_breaking_a_tie(): void
    {
        $fourA = $this->lot('2026-08-10', 'FOUR-A', 4);
        $ten = $this->lot('2026-08-11', 'TEN', 10);
        $fourB = $this->lot('2026-08-12', 'FOUR-B', 4);

        $response = $this->getJson('/api/v1/inventory/material-lots?sort=-bag_count')->assertOk();

        $this->assertSame(
            [$ten->id, $fourB->id, $fourA->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_a_column_sort_wins_over_the_older_order_switch(): void
    {
        $this->lot('2026-08-10', 'EARLY');
        $this->lot('2026-08-14', 'MIDDLE');
        $this->lot('2026-08-20', 'LATE');

        $response = $this->getJson('/api/v1/inventory/material-lots?order=oldest&sort=-received_date')->assertOk();

        $this->assertSame(['LATE', 'MIDDLE', 'EARLY'], collect($response->json('data'))->pluck('supplier_lot_no')->all());
    }

    public function test_lots_page_size_is_honoured_and_out_of_range_is_refused(): void
    {
        $this->lot('2026-08-10', 'ONE');
        $this->lot('2026-08-11', 'TWO');
        $this->lot('2026-08-12', 'THREE');

        $response = $this->getJson('/api/v1/inventory/material-lots?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));

        $this->getJson('/api/v1/inventory/material-lots?per_page=500')->assertStatus(422);
    }

    // ---- bags ---------------------------------------------------------------

    public function test_bags_refuse_an_unknown_sort_and_an_unknown_status(): void
    {
        $this->getJson('/api/v1/inventory/material-bags?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);

        $this->getJson('/api/v1/inventory/material-bags?status=lost')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_bags_sort_by_remaining_kg_descending_with_newest_id_breaking_a_tie(): void
    {
        $lot = $this->lot('2026-08-10', 'LOT-1');
        $fullA = $this->bag($lot, 'BAG-A', '25.0000');
        $part = $this->bag($lot, 'BAG-B', '10.0000');
        $fullB = $this->bag($lot, 'BAG-C', '25.0000');

        $response = $this->getJson('/api/v1/inventory/material-bags?sort=-remaining_kg')->assertOk();

        $this->assertSame(
            [$fullB->id, $fullA->id, $part->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_bags_default_order_is_still_oldest_bag_first(): void
    {
        $lot = $this->lot('2026-08-10', 'LOT-1');
        $first = $this->bag($lot, 'BAG-A', '25.0000');
        $second = $this->bag($lot, 'BAG-B', '25.0000');

        $response = $this->getJson('/api/v1/inventory/material-bags')->assertOk();

        $this->assertSame([$first->id, $second->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_bags_page_size_is_read_for_the_first_time(): void
    {
        $lot = $this->lot('2026-08-10', 'LOT-1');
        $this->bag($lot, 'BAG-A', '25.0000');
        $this->bag($lot, 'BAG-B', '25.0000');
        $this->bag($lot, 'BAG-C', '25.0000');

        $response = $this->getJson('/api/v1/inventory/material-bags?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.per_page'));
    }
}
