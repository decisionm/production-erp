<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Production\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /production/shifts — the shared list contract (ListSort, 03-Sep-2026)
 * on the shift master. Its own order stays the clock's (start_time) when
 * nothing is asked, and `per_page` is read now (it never was before).
 */
class ShiftsListSortTest extends TestCase
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

    private function shift(string $name, string $start, string $end): Shift
    {
        return Shift::create(['name' => $name, 'start_time' => $start, 'end_time' => $end, 'is_active' => true]);
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->getJson('/api/v1/production/shifts?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_a_descending_sort_tiebreaks_on_id_desc(): void
    {
        $one = $this->shift('Shift A', '06:00:00', '14:00:00');
        $two = $this->shift('Shift B', '14:00:00', '22:00:00');
        $three = $this->shift('Shift B2', '14:00:00', '22:00:00');

        $ids = array_column($this->getJson('/api/v1/production/shifts?sort=-start_time')->assertOk()->json('data'), 'id');

        $this->assertSame([$three->id, $two->id, $one->id], $ids);
    }

    public function test_per_page_cuts_a_real_page_with_the_real_total(): void
    {
        $this->shift('Shift A', '06:00:00', '14:00:00');
        $this->shift('Shift B', '14:00:00', '22:00:00');
        $this->shift('Shift C', '22:00:00', '06:00:00');

        $response = $this->getJson('/api/v1/production/shifts?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
