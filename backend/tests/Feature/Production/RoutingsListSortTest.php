<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Routing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /production/routings — the shared list contract (ListSort, 03-Sep-2026).
 */
class RoutingsListSortTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        $this->item = Item::create(['sku' => 'BTL-A', 'name' => 'Bottle A', 'uom' => 'Nos']);
    }

    private function routing(string $name): Routing
    {
        return Routing::create(['item_id' => $this->item->id, 'name' => $name, 'is_active' => true]);
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->getJson('/api/v1/production/routings?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_a_descending_sort_tiebreaks_on_id_desc(): void
    {
        $one = $this->routing('Blow');
        $two = $this->routing('Mould');
        $three = $this->routing('Mould');

        $ids = array_column($this->getJson('/api/v1/production/routings?sort=-name')->assertOk()->json('data'), 'id');

        $this->assertSame([$three->id, $two->id, $one->id], $ids);
    }

    public function test_per_page_cuts_a_real_page_with_the_real_total(): void
    {
        $this->routing('Blow');
        $this->routing('Mould');
        $this->routing('Pack');

        $response = $this->getJson('/api/v1/production/routings?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
