<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Production\Models\Enums\MoldStatus;
use App\Modules\Production\Models\Mold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /production/molds — the shared list contract (ListSort, 03-Sep-2026)
 * on the mould master. Its own order stays by code when nothing is asked.
 */
class MoldsListSortTest extends TestCase
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

    private function mould(string $code, int $cavities): Mold
    {
        return Mold::create(['code' => $code, 'name' => 'Mould '.$code, 'cavity_count' => $cavities, 'status' => MoldStatus::Active]);
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->getJson('/api/v1/production/molds?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_a_descending_sort_tiebreaks_on_id_desc(): void
    {
        $one = $this->mould('MLD-C', 4);
        $two = $this->mould('MLD-B', 8);
        $three = $this->mould('MLD-A', 8);

        $ids = array_column($this->getJson('/api/v1/production/molds?sort=-cavity_count')->assertOk()->json('data'), 'id');

        $this->assertSame([$three->id, $two->id, $one->id], $ids);

        // Nothing asked: the master's own order, by code.
        $byCode = array_column($this->getJson('/api/v1/production/molds')->assertOk()->json('data'), 'id');
        $this->assertSame([$three->id, $two->id, $one->id], $byCode);
    }

    public function test_per_page_cuts_a_real_page_with_the_real_total(): void
    {
        $this->mould('MLD-A', 4);
        $this->mould('MLD-B', 4);
        $this->mould('MLD-C', 4);

        $response = $this->getJson('/api/v1/production/molds?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
