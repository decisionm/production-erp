<?php

namespace Tests\Feature\Maintenance;

use App\Models\User;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /maintenance/schedules through ListMaintenanceSchedulesRequest
 * (03-Sep-2026): an unknown sort is refused, a known one orders the whole
 * list with `id desc` as the tiebreak, the page size asked for is the page
 * served, the asset_id filter the page always sent still narrows, and the
 * default order — soonest due first — is what it always was.
 */
class MaintenanceScheduleListSortTest extends TestCase
{
    use RefreshDatabase;

    private Asset $press;

    private Asset $chiller;

    protected function setUp(): void
    {
        parent::setUp();

        $reader = User::factory()->create(['name' => 'Maintenance Reader', 'is_active' => true]);
        Permission::findOrCreate('maintenance.view', 'web');
        $reader->givePermissionTo('maintenance.view');
        Sanctum::actingAs($reader);

        $this->press = Asset::create(['code' => 'PRESS-A', 'name' => 'Press']);
        $this->chiller = Asset::create(['code' => 'CHILLER-A', 'name' => 'Chiller']);
    }

    private function schedule(Asset $asset, string $name, int $frequencyDays, string $nextDue): MaintenanceSchedule
    {
        return MaintenanceSchedule::create([
            'asset_id' => $asset->id,
            'name' => $name,
            'frequency_days' => $frequencyDays,
            'next_due_date' => $nextDue,
        ]);
    }

    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->getJson('/api/v1/maintenance/schedules?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_a_descending_sort_orders_the_column_with_id_desc_as_the_tiebreak(): void
    {
        $quarterly = $this->schedule($this->press, 'Quarterly PM', 90, '2026-12-01');
        $monthlyFirst = $this->schedule($this->press, 'Monthly PM', 30, '2026-10-01');
        $monthlySecond = $this->schedule($this->chiller, 'Monthly Filter', 30, '2026-11-01');

        $ids = $this->getJson('/api/v1/maintenance/schedules?sort=-frequency_days')
            ->assertOk()
            ->json('data.*.id');

        // Ninety before thirty; the two thirty-day rows newest first.
        $this->assertSame([$quarterly->id, $monthlySecond->id, $monthlyFirst->id], $ids);
    }

    public function test_the_page_size_asked_for_is_the_page_served_with_the_whole_total(): void
    {
        $this->schedule($this->press, 'Quarterly PM', 90, '2026-12-01');
        $this->schedule($this->press, 'Monthly PM', 30, '2026-10-01');
        $this->schedule($this->chiller, 'Monthly Filter', 30, '2026-11-01');

        $this->getJson('/api/v1/maintenance/schedules?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_the_default_order_is_still_soonest_due_first(): void
    {
        $later = $this->schedule($this->press, 'Quarterly PM', 90, '2026-12-01');
        $sooner = $this->schedule($this->chiller, 'Monthly Filter', 30, '2026-10-01');

        $ids = $this->getJson('/api/v1/maintenance/schedules')->assertOk()->json('data.*.id');

        $this->assertSame([$sooner->id, $later->id], $ids);
    }

    public function test_the_asset_filter_still_narrows_a_sorted_list(): void
    {
        $this->schedule($this->press, 'Quarterly PM', 90, '2026-12-01');
        $chillerRow = $this->schedule($this->chiller, 'Monthly Filter', 30, '2026-10-01');

        $ids = $this->getJson("/api/v1/maintenance/schedules?asset_id={$this->chiller->id}&sort=name")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('data.*.id');

        $this->assertSame([$chillerRow->id], $ids);
    }
}
