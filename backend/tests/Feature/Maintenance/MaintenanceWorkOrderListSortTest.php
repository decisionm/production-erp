<?php

namespace Tests\Feature\Maintenance;

use App\Models\User;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /maintenance/work-orders through ListMaintenanceWorkOrdersRequest
 * (03-Sep-2026): an unknown sort is refused, a known one orders the whole
 * register with `id desc` as the tiebreak, the page size asked for is the
 * page served, the asset_id filter the page always sent still narrows, and
 * the default order — newest first — is what it always was.
 *
 * The cost columns follow FC-06: a reader the resource hides parts_cost /
 * total_cost from may not sort by them either; finance eyes may.
 */
class MaintenanceWorkOrderListSortTest extends TestCase
{
    use RefreshDatabase;

    private Asset $press;

    private Asset $chiller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->press = Asset::create(['code' => 'PRESS-A', 'name' => 'Press']);
        $this->chiller = Asset::create(['code' => 'CHILLER-A', 'name' => 'Chiller']);
    }

    /** @param  list<string>  $permissions */
    private function actingAsWith(array $permissions): void
    {
        $user = User::factory()->create(['name' => 'Maintenance Reader', 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    private function workOrder(Asset $asset, string $reportedDate, string $totalCost = '0'): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'asset_id' => $asset->id,
            'type' => 'corrective',
            'status' => 'open',
            'reported_date' => $reportedDate,
            'labor_cost' => 0,
            'parts_cost' => $totalCost,
            'total_cost' => $totalCost,
        ]);
    }

    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->actingAsWith(['maintenance.view']);

        $this->getJson('/api/v1/maintenance/work-orders?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_a_descending_sort_orders_the_column_with_id_desc_as_the_tiebreak(): void
    {
        $this->actingAsWith(['maintenance.view']);
        $earlier = $this->workOrder($this->press, '2026-08-01');
        $laterFirst = $this->workOrder($this->press, '2026-08-05');
        $laterSecond = $this->workOrder($this->chiller, '2026-08-05');

        $ids = $this->getJson('/api/v1/maintenance/work-orders?sort=-reported_date')
            ->assertOk()
            ->json('data.*.id');

        // The later date first; the two rows sharing it newest first.
        $this->assertSame([$laterSecond->id, $laterFirst->id, $earlier->id], $ids);
    }

    public function test_the_page_size_asked_for_is_the_page_served_with_the_whole_total(): void
    {
        $this->actingAsWith(['maintenance.view']);
        $this->workOrder($this->press, '2026-08-01');
        $this->workOrder($this->press, '2026-08-05');
        $this->workOrder($this->chiller, '2026-08-05');

        $this->getJson('/api/v1/maintenance/work-orders?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_the_default_order_is_still_newest_first(): void
    {
        $this->actingAsWith(['maintenance.view']);
        $first = $this->workOrder($this->press, '2026-08-05');
        $second = $this->workOrder($this->chiller, '2026-08-01');

        $ids = $this->getJson('/api/v1/maintenance/work-orders')->assertOk()->json('data.*.id');

        $this->assertSame([$second->id, $first->id], $ids);
    }

    public function test_the_asset_filter_still_narrows_a_sorted_list(): void
    {
        $this->actingAsWith(['maintenance.view']);
        $this->workOrder($this->press, '2026-08-01');
        $chillerRow = $this->workOrder($this->chiller, '2026-08-05');

        $ids = $this->getJson("/api/v1/maintenance/work-orders?asset_id={$this->chiller->id}&sort=status")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('data.*.id');

        $this->assertSame([$chillerRow->id], $ids);
    }

    public function test_a_reader_without_finance_eyes_may_not_sort_by_a_cost_column(): void
    {
        $this->actingAsWith(['maintenance.view']);

        $this->getJson('/api/v1/maintenance/work-orders?sort=-total_cost')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
        $this->getJson('/api/v1/maintenance/work-orders?sort=parts_cost')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_finance_eyes_may_sort_by_total_cost(): void
    {
        $this->actingAsWith(['maintenance.view', 'finance.view']);
        $cheap = $this->workOrder($this->press, '2026-08-01', '10.0000');
        $dear = $this->workOrder($this->chiller, '2026-08-01', '250.0000');

        $ids = $this->getJson('/api/v1/maintenance/work-orders?sort=-total_cost')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$dear->id, $cheap->id], $ids);
    }
}
