<?php

namespace Tests\Feature\Maintenance;

use App\Models\User;
use App\Modules\Maintenance\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /maintenance/assets through ListAssetsRequest (03-Sep-2026): an
 * unknown sort is refused, a known one orders the whole register with
 * `id desc` as the tiebreak, the page size asked for is the page served,
 * and the default order — name — is what it always was.
 */
class AssetListSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $reader = User::factory()->create(['name' => 'Maintenance Reader', 'is_active' => true]);
        Permission::findOrCreate('maintenance.view', 'web');
        $reader->givePermissionTo('maintenance.view');
        Sanctum::actingAs($reader);
    }

    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->getJson('/api/v1/maintenance/assets?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_a_descending_sort_orders_the_column_with_id_desc_as_the_tiebreak(): void
    {
        $pump = Asset::create(['code' => 'PUMP-A', 'name' => 'Pump', 'category' => 'Utility']);
        $dryer = Asset::create(['code' => 'DRYER-A', 'name' => 'Dryer', 'category' => 'Plant']);
        $chiller = Asset::create(['code' => 'CHILLER-A', 'name' => 'Chiller', 'category' => 'Plant']);

        $ids = $this->getJson('/api/v1/maintenance/assets?sort=-category')
            ->assertOk()
            ->json('data.*.id');

        // Utility before Plant; the two Plant rows newest first.
        $this->assertSame([$pump->id, $chiller->id, $dryer->id], $ids);
    }

    public function test_the_page_size_asked_for_is_the_page_served_with_the_whole_total(): void
    {
        Asset::create(['code' => 'PUMP-A', 'name' => 'Pump']);
        Asset::create(['code' => 'DRYER-A', 'name' => 'Dryer']);
        Asset::create(['code' => 'CHILLER-A', 'name' => 'Chiller']);

        $this->getJson('/api/v1/maintenance/assets?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_the_default_order_is_still_by_name(): void
    {
        $zeta = Asset::create(['code' => 'ZETA-A', 'name' => 'Zeta Press']);
        $alpha = Asset::create(['code' => 'ALPHA-A', 'name' => 'Alpha Press']);

        $ids = $this->getJson('/api/v1/maintenance/assets')->assertOk()->json('data.*.id');

        $this->assertSame([$alpha->id, $zeta->id], $ids);
    }
}
