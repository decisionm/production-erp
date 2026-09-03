<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Production\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * THE STORE'S ACTION COUNTS ON THE DASHBOARD (chapter 1 §1: "Each number
 * must open the exact filtered work queue it counted").
 *
 * "Exact" is the whole contract and it is the easy half to fake, so it is
 * asserted the only way that means anything: the tile's number is compared
 * against `meta.total` of the LIST ENDPOINT THE TILE LINKS TO, on that
 * list's DEFAULT view — no query string, exactly what a storekeeper sees
 * after tapping the tile. If the queue's idea of "still to issue" ever
 * changes, this fails rather than the tile quietly lying.
 *
 * AND WHO GETS THE FIGURES IS DECIDED BY PERMISSION, NEVER BY ROLE NAME.
 * Roles are created and granted through the Roles UI on the live instance
 * (PermissionSeeder says so three times over), so there are role names this
 * codebase has never seen — "Supervisor" is already one of them. A dashboard
 * that recognised names would hand those logins a blank page, which is worse
 * than the common page they have today. The last test pins that.
 */
class DashboardStoreQueueTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create([
            'name' => 'A',
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
            'is_active' => true,
        ]);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'Relpet PET Resin', 'uom' => 'Kgs']);

        // Two open to the store, and three that are not: a draft production
        // has not sent, one fully issued, one cancelled.
        $this->request(MaterialRequestStatus::Submitted, submitted: true);
        $this->request(MaterialRequestStatus::PartiallyIssued, submitted: true);
        $this->request(MaterialRequestStatus::Draft, submitted: false);
        $this->request(MaterialRequestStatus::Issued, submitted: true);
        $this->request(MaterialRequestStatus::Cancelled, submitted: true);
    }

    /**
     * THE DEFAULT VIEW IS NOT THE BARE PATH, and that is the trap this pins.
     *
     * A bare `GET /inventory/material-requests` is every request that reached
     * the store — issued and cancelled ones too. "Still to issue" is a status
     * LIST the STORE ISSUE QUEUE SCREEN sends for you when its dropdown is
     * untouched (frontend `queueStatusFilter('open')` → submitted +
     * partially_issued, its documented default). So the tile is compared
     * against the query the screen actually issues, because that is the set
     * of rows the storekeeper lands on after tapping it — which is the
     * promise. Comparing it against the bare path would pass only if the tile
     * counted the wrong thing.
     */
    private const QUEUE_DEFAULT_VIEW = 'status[]=submitted&status[]=partially_issued';

    public function test_the_tile_counts_exactly_the_rows_its_queue_shows(): void
    {
        $this->actingWith(['inventory.view']);

        $tile = $this->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->json('data.inventory.material_requests_to_issue');

        $queue = $this->getJson('/api/v1/inventory/material-requests?'.self::QUEUE_DEFAULT_VIEW)
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(2, $tile, 'submitted + partially_issued, and nothing else');
        $this->assertSame($queue, $tile, 'the tile must count the rows the queue shows');

        // And the difference is real, so the assertion above is not vacuous.
        $this->assertSame(4, $this->getJson('/api/v1/inventory/material-requests')->json('meta.total'));
    }

    public function test_the_tile_survives_paging_because_it_is_counted_in_sql(): void
    {
        $this->actingWith(['inventory.view']);

        $tile = $this->getJson('/api/v1/dashboard/summary')->json('data.inventory.material_requests_to_issue');
        $onePerPage = $this->getJson('/api/v1/inventory/material-requests?'.self::QUEUE_DEFAULT_VIEW.'&per_page=1')
            ->json('meta.total');

        $this->assertSame($onePerPage, $tile);
    }

    public function test_a_reader_without_inventory_gets_no_store_figures_at_all(): void
    {
        // Hiding is not security: the figure itself must not leave the server
        // for someone who cannot open the queue behind it.
        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonMissingPath('data.inventory');
    }

    public function test_a_role_name_this_codebase_has_never_seen_still_gets_its_permissions_figures(): void
    {
        $user = $this->actingWith(['inventory.view']);

        // Created through the Roles UI on live, as far as this code knows.
        $role = Role::findOrCreate('Night Store Supervisor', 'web');
        $user->assignRole($role);

        $summary = $this->getJson('/api/v1/dashboard/summary')->assertOk();

        $summary->assertJsonPath('data.inventory.material_requests_to_issue', 2);
        $this->assertIsInt($summary->json('data.inventory.order_lines_awaiting_store'));
    }

    private function request(MaterialRequestStatus $status, bool $submitted): MaterialRequest
    {
        $request = MaterialRequest::create([
            'status' => $status,
            'shift_id' => $this->shift->id,
            'work_center_id' => null,
            'requested_at' => '2026-09-03 07:00:00',
            'submitted_at' => $submitted ? '2026-09-03 07:00:00' : null,
        ]);

        $request->lines()->create([
            'item_id' => $this->resin->id,
            'quantity' => '500',
        ]);

        return $request;
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
