<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Production\Models\ProductionStandard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /production/standards — column-header sorting on the Product
 * Standards workspace (03-Sep-2026): an unknown sort is refused, a known one
 * reorders the WHOLE assessed set (id desc as the tiebreak) before the page
 * is cut, and the page and its total are still the workspace's own.
 */
class ProductStandardsListSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);
    }

    private function standard(string $product, string $status): ProductionStandard
    {
        return ProductionStandard::create([
            'source_product_name' => $product,
            'cavities' => 8,
            'unit_weight_grams' => '12.5',
            'cycle_time' => '11.5',
            'status' => $status,
            'source' => 'IMPORT',
        ]);
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->getJson('/api/v1/production/standards?view=all&sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_a_descending_sort_tiebreaks_on_id_desc(): void
    {
        $one = $this->standard('90ML RIB', 'approved');
        $two = $this->standard('60ML ROUND', 'draft');
        $three = $this->standard('400ML HEXAGON', 'draft');

        $ids = array_column(
            $this->getJson('/api/v1/production/standards?view=all&sort=-status')->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$three->id, $two->id, $one->id], $ids);

        // Nothing asked: the workspace's own order, by product name.
        $names = array_column($this->getJson('/api/v1/production/standards?view=all')->assertOk()->json('data'), 'source_product_name');
        $this->assertSame(['400ML HEXAGON', '60ML ROUND', '90ML RIB'], $names);
    }

    public function test_the_sort_reorders_the_whole_set_before_the_page_is_cut(): void
    {
        $one = $this->standard('90ML RIB', 'approved');
        $this->standard('60ML ROUND', 'draft');
        $this->standard('400ML HEXAGON', 'draft');

        $response = $this->getJson('/api/v1/production/standards?view=all&sort=status&per_page=25')->assertOk();

        $this->assertSame(3, $response->json('total'));
        $this->assertSame($one->id, $response->json('data.0.id'));
    }
}
