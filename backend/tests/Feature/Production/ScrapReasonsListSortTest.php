<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Production\Models\ScrapReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /production/scrap-reasons — the shared list contract (ListSort,
 * 03-Sep-2026) on the scrap-reason master. Its own order stays by name when
 * nothing is asked.
 */
class ScrapReasonsListSortTest extends TestCase
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

    private function reason(string $code, string $name): ScrapReason
    {
        return ScrapReason::create(['code' => $code, 'name' => $name, 'is_active' => true]);
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->getJson('/api/v1/production/scrap-reasons?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_a_descending_sort_tiebreaks_on_id_desc(): void
    {
        $one = $this->reason('SR-C', 'Flash');
        $two = $this->reason('SR-B', 'Short shot');
        $three = $this->reason('SR-A', 'Short shot');

        $ids = array_column($this->getJson('/api/v1/production/scrap-reasons?sort=-name')->assertOk()->json('data'), 'id');

        $this->assertSame([$three->id, $two->id, $one->id], $ids);

        // Nothing asked: the master's own order, by name (id desc between equals).
        $byName = array_column($this->getJson('/api/v1/production/scrap-reasons')->assertOk()->json('data'), 'id');
        $this->assertSame([$one->id, $three->id, $two->id], $byName);
    }

    public function test_per_page_cuts_a_real_page_with_the_real_total(): void
    {
        $this->reason('SR-A', 'Flash');
        $this->reason('SR-B', 'Short shot');
        $this->reason('SR-C', 'Black spot');

        $response = $this->getJson('/api/v1/production/scrap-reasons?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
